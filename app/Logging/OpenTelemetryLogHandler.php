<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord as MonologLogRecord;
use OpenTelemetry\API\Logs\LogRecord as OtelLogRecord;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;

/**
 * Ships every Laravel log entry (report()'d exceptions included) to SigNoz
 * alongside the existing file log, so an error is visible there even if
 * nothing about the request/trace path itself went wrong. Tagged with the
 * currently-active span's trace/span ID (if any) so a log line and the
 * request that produced it show up linked in SigNoz.
 */
class OpenTelemetryLogHandler extends AbstractProcessingHandler
{
    protected function write(MonologLogRecord $record): void
    {
        $logger = app(LoggerProviderInterface::class)->getLogger('nexora-backend');

        $otelRecord = (new OtelLogRecord($record->message))
            ->setSeverityNumber(Severity::fromPsr3($record->level->toPsrLogLevel()))
            ->setSeverityText($record->level->getName())
            ->setTimestamp((int) ($record->datetime->format('Uu') * 1000))
            ->setAttributes($this->attributes($record));

        $logger->emit($otelRecord);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(MonologLogRecord $record): array
    {
        $attributes = $record->context;

        $span = Span::getCurrent()->getContext();
        if ($span->isValid()) {
            $attributes['trace_id'] = $span->getTraceId();
            $attributes['span_id'] = $span->getSpanId();
        }

        if (isset($attributes['exception']) && $attributes['exception'] instanceof \Throwable) {
            $e = $attributes['exception'];
            $attributes['exception.type'] = get_class($e);
            $attributes['exception.message'] = $e->getMessage();
            $attributes['exception.stacktrace'] = (string) $e;
            unset($attributes['exception']);
        }

        // Context can hold arbitrary values (arrays, models, ...) -- OTel
        // attributes only accept scalars, so anything else is JSON-encoded
        // rather than silently dropped or thrown on at export time.
        foreach ($attributes as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                $attributes[$key] = json_encode($value) ?: (string) $value;
            }
        }

        return $attributes;
    }
}
