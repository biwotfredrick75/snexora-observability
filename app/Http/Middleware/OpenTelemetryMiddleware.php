<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OpenTelemetryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $tracer = app(TracerProviderInterface::class)->getTracer('nexora-backend');
        $meter = app(MeterProviderInterface::class)->getMeter('nexora-backend');
        $requestCounter = $meter->createCounter('http.server.requests', unit: '{request}', description: 'HTTP requests handled');
        $errorCounter = $meter->createCounter('http.server.errors', unit: '{request}', description: 'HTTP requests that errored');
        $durationHistogram = $meter->createHistogram('http.server.duration', unit: 'ms', description: 'HTTP request duration');
        $route = optional($request->route())->uri() ?? $request->path();
        $startedAt = microtime(true);

        // Continue the browser's trace (traceparent header) instead of
        // always starting a disconnected root span, so RUM spans from the
        // Vue frontend show up as the parent of this request in SigNoz.
        $parentContext = TraceContextPropagator::getInstance()->extract(array_filter([
            'traceparent' => $request->header('traceparent'),
            'tracestate' => $request->header('tracestate'),
        ]));

        $span = $tracer->spanBuilder(sprintf('%s /%s', $request->method(), ltrim($route, '/')))
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $request->method())
            ->setAttribute('url.path', $request->path())
            ->setAttribute('http.route', $route)
            ->setAttribute('server.address', $request->getHost())
            ->setAttribute('client.address', $request->ip())
            ->startSpan();

        $scope = $span->activate();
        $metricAttributes = ['http.request.method' => $request->method(), 'http.route' => $route];

        try {
            $response = $next($request);

            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                // The exception itself (if any) is what threw this response into being --
                // Laravel's own exception handler already converted it before returning
                // here, so there's no Throwable in this branch. Best effort: pull the
                // message straight back out of the JSON body it rendered, so the span
                // still carries a human-readable description instead of just a bare code.
                $span->setStatus(StatusCode::STATUS_ERROR, (string) (
                    json_decode($response->getContent() ?: '', true)['message'] ?? ''
                ));
                $errorCounter->add(1, $metricAttributes);
            }
            if ($userId = $request->user()?->getAuthIdentifier()) {
                $span->setAttribute('enduser.id', (string) $userId);
            }

            return $response;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $errorCounter->add(1, $metricAttributes);
            throw $e;
        } finally {
            $requestCounter->add(1, $metricAttributes);
            $durationHistogram->record((microtime(true) - $startedAt) * 1000, $metricAttributes);
            $scope->detach();
            $span->end();
            app(MeterProviderInterface::class)->forceFlush();
            app(LoggerProviderInterface::class)->forceFlush();
        }
    }
}
