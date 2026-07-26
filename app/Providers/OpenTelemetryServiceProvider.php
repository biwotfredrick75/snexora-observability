<?php

namespace App\Providers;

use App\Events\DashboardEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Reverb\Events\ChannelCreated;
use Laravel\Reverb\Events\ChannelRemoved;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Builds the TracerProvider ourselves (rather than relying on
 * open-telemetry/sdk's env-var "SdkAutoloader" magic) because that magic
 * only reads OTEL_* env vars at the moment vendor/autoload.php is first
 * required -- which happens before Laravel's own .env is loaded, so it
 * would silently no-op under `php artisan serve` / php-fpm.
 *
 * The service.name is derived from which artisan command is running, so
 * the HTTP server, the queue worker, and Reverb each show up as their own
 * service in SigNoz instead of being mixed into one.
 */
class OpenTelemetryServiceProvider extends ServiceProvider
{
    /** @var array<string, array{0: SpanInterface, 1: ScopeInterface}> */
    private array $queueSpans = [];

    public function register(): void
    {
        $this->app->singleton(TracerProviderInterface::class, function () {
            if (! filter_var(env('OTEL_ENABLED', true), FILTER_VALIDATE_BOOL)) {
                return new NoopTracerProvider();
            }

            $transport = (new OtlpHttpTransportFactory())->create($this->otlpEndpoint('/v1/traces'), 'application/x-protobuf');
            $exporter = new SpanExporter($transport);

            return TracerProvider::builder()
                ->addSpanProcessor(new SimpleSpanProcessor($exporter))
                ->setResource($this->resource())
                ->build();
        });

        $this->app->singleton(LoggerProviderInterface::class, function () {
            if (! filter_var(env('OTEL_ENABLED', true), FILTER_VALIDATE_BOOL)) {
                return new NoopLoggerProvider();
            }

            $transport = (new OtlpHttpTransportFactory())->create($this->otlpEndpoint('/v1/logs'), 'application/x-protobuf');
            $exporter = new LogsExporter($transport);

            return LoggerProvider::builder()
                ->addLogRecordProcessor(new SimpleLogRecordProcessor($exporter))
                ->setResource($this->resource())
                ->build();
        });

        $this->app->singleton(MeterProviderInterface::class, function () {
            if (! filter_var(env('OTEL_ENABLED', true), FILTER_VALIDATE_BOOL)) {
                return new NoopMeterProvider();
            }

            $transport = (new OtlpHttpTransportFactory())->create($this->otlpEndpoint('/v1/metrics'), 'application/x-protobuf');
            $exporter = new MetricExporter($transport);

            return MeterProvider::builder()
                ->addReader(new ExportingReader($exporter))
                ->setResource($this->resource())
                ->build();
        });
    }

    private function otlpEndpoint(string $path): string
    {
        return rtrim((string) env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'), '/').$path;
    }

    private function resource(): ResourceInfo
    {
        return ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $this->resolveServiceName(),
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => env('APP_ENV', 'local'),
        ]));
    }

    public function boot(): void
    {
        $this->traceDatabaseQueries();
        $this->traceQueueJobs();
        $this->traceReverbActivity();
        $this->traceAppBroadcasts();
    }

    private function resolveServiceName(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (in_array('reverb:start', $argv, true)) {
            return env('OTEL_SERVICE_NAME_REVERB', 'nexora-reverb');
        }

        if (in_array('queue:work', $argv, true) || in_array('queue:listen', $argv, true)) {
            return env('OTEL_SERVICE_NAME_QUEUE', 'nexora-queue-worker');
        }

        return env('OTEL_SERVICE_NAME', 'nexora-backend');
    }

    private function tracer(): TracerInterface
    {
        return $this->app->make(TracerProviderInterface::class)->getTracer('nexora-backend');
    }

    private function traceDatabaseQueries(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $endNanos = (int) (microtime(true) * 1e9);
            $startNanos = $endNanos - (int) ($query->time * 1e6);

            $span = $this->tracer()->spanBuilder('db.query')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp($startNanos)
                ->setAttribute('db.system', $query->connection->getDriverName())
                ->setAttribute('db.name', $query->connection->getDatabaseName())
                ->setAttribute('db.statement', $query->sql)
                ->startSpan();

            $span->end($endNanos);
        });
    }

    private function traceQueueJobs(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $span = $this->tracer()->spanBuilder('queue.job '.$event->job->resolveName())
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setAttribute('messaging.system', 'laravel_queue')
                ->setAttribute('messaging.destination', $event->job->getQueue())
                ->setAttribute('messaging.operation', 'process')
                ->setAttribute('job.connection', $event->connectionName)
                ->setAttribute('job.attempts', $event->job->attempts())
                ->startSpan();

            $this->queueSpans[$event->job->getJobId()] = [$span, $span->activate()];
        });

        Queue::failing(function (JobFailed $event): void {
            [$span] = $this->queueSpans[$event->job->getJobId()] ?? [null, null];
            if ($span) {
                $span->recordException($event->exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $event->exception->getMessage());
            }
            $this->endQueueSpan($event->job);
        });

        Queue::after(function (JobProcessed $event): void {
            $this->endQueueSpan($event->job);
        });
    }

    private function endQueueSpan(Job $job): void
    {
        [$span, $scope] = $this->queueSpans[$job->getJobId()] ?? [null, null];
        if (! $span) {
            return;
        }
        $scope->detach();
        $span->end();
        unset($this->queueSpans[$job->getJobId()]);
    }

    private function traceReverbActivity(): void
    {
        if (! class_exists(MessageReceived::class)) {
            return;
        }

        $this->app['events']->listen(MessageReceived::class, function (MessageReceived $event): void {
            $this->instantSpan('reverb.message.received', SpanKind::KIND_CONSUMER, [
                'messaging.system' => 'reverb',
                'reverb.connection_id' => $event->connection->identifier(),
            ]);
        });

        $this->app['events']->listen(MessageSent::class, function (MessageSent $event): void {
            $this->instantSpan('reverb.message.sent', SpanKind::KIND_PRODUCER, [
                'messaging.system' => 'reverb',
                'reverb.connection_id' => $event->connection->identifier(),
            ]);
        });

        $this->app['events']->listen(ChannelCreated::class, function (ChannelCreated $event): void {
            $this->instantSpan('reverb.channel.created', SpanKind::KIND_INTERNAL, [
                'reverb.channel' => $event->channel->name(),
            ]);
        });

        $this->app['events']->listen(ChannelRemoved::class, function (ChannelRemoved $event): void {
            $this->instantSpan('reverb.channel.removed', SpanKind::KIND_INTERNAL, [
                'reverb.channel' => $event->channel->name(),
            ]);
        });
    }

    /**
     * Fires once per `broadcast(new DashboardEvent(...))` call -- i.e. once
     * per real-time business event the app pushes out over Reverb,
     * regardless of how many browsers are connected to receive it. This is
     * the signal SigNoz alert rules watch to know the app broadcast
     * something.
     */
    private function traceAppBroadcasts(): void
    {
        $this->app['events']->listen(DashboardEvent::class, function (DashboardEvent $event): void {
            $attributes = [
                'broadcast.channel' => 'dashboard',
                'broadcast.scope' => $event->scope,
                'broadcast.action' => $event->action,
            ];

            foreach ($event->meta as $key => $value) {
                if (is_scalar($value)) {
                    $attributes['broadcast.meta.'.$key] = (string) $value;
                }
            }

            $this->instantSpan('app.broadcast '.$event->scope.'.'.$event->action, SpanKind::KIND_PRODUCER, $attributes);
        });
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function instantSpan(string $name, int $kind, array $attributes): void
    {
        $builder = $this->tracer()->spanBuilder($name)->setSpanKind($kind);
        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }
        $builder->startSpan()->end();
    }
}
