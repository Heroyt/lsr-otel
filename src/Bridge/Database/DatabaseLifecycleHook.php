<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Database;

use Lsr\Db\Lifecycle\DatabaseLifecycleEvent;
use Lsr\Db\Lifecycle\DatabaseLifecycleHookInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class DatabaseLifecycleHook implements DatabaseLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/db') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/db') : null;
        $this->duration = $meter?->createHistogram(
            'lsr.db.operation.duration',
            's',
            'Duration of database operations.',
        );
        $this->count = $meter?->createCounter(
            'lsr.db.operations',
            '{operation}',
            'Database operation outcomes.',
        );
    }

    public function record(DatabaseLifecycleEvent $event): void {
        $metricAttributes = [
            'db.operation.name' => $event->operation,
            'lsr.db.outcome' => $event->outcome,
        ];

        try {
            $this->duration?->record($event->durationSeconds, $metricAttributes);
            $this->count?->add(1, $metricAttributes);
        } catch (Throwable) {
            // Continue with trace export.
        }

        if ($this->tracer === null) {
            return;
        }

        $span = null;
        $endedAt = null;
        try {
            $endedAt = Clock::getDefault()->now();
            $durationNanos = max(0, (int) round($event->durationSeconds * 1_000_000_000));
            $attributes = $metricAttributes + [
                'db.system.name' => $event->system,
            ];
            if ($event->connectionName !== null) {
                $attributes['lsr.db.connection.name'] = $event->connectionName;
            }
            if ($event->rowCount !== null) {
                $attributes['db.response.returned_rows'] = $event->rowCount;
            }
            if ($event->errorType !== null) {
                $attributes['error.type'] = $event->errorType;
            }
            if ($event->sql !== null) {
                $attributes['db.query.text'] = $event->sql;
            }

            $span = $this->tracer
                ->spanBuilder('db ' . $event->operation)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp(max(0, $endedAt - $durationNanos))
                ->setAttributes($attributes)
                ->startSpan();
            if ($event->outcome === DatabaseLifecycleEvent::ERROR) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
            $span->end($endedAt);
            $span = null;
        } catch (Throwable) {
            $this->endPartialSpan($span, $endedAt);
        }
    }

    private function endPartialSpan(?SpanInterface $span, ?int $endedAt): void {
        try {
            $span?->end($endedAt);
        } catch (Throwable) {
            // Telemetry must never affect database operations.
        }
    }
}
