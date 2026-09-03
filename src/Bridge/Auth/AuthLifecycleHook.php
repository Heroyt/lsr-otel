<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Auth;

use Lsr\Core\Auth\Lifecycle\AuthLifecycleEvent;
use Lsr\Core\Auth\Lifecycle\AuthLifecycleHookInterface;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\Span;
use Throwable;

final readonly class AuthLifecycleHook implements AuthLifecycleHookInterface
{
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        private bool $traces = true,
        bool $metrics = true,
    ) {
        $meter = $metrics ? $instrumentation->meter('lsr/auth') : null;
        $this->duration = DurationHistogram::create(
            $meter,
            'lsr.auth.operation.duration',
            'Duration of authentication operations.',
        );
        $this->count = $meter?->createCounter(
            'lsr.auth.operations',
            '{operation}',
            'Authentication operation outcomes.',
        );
    }

    public function record(AuthLifecycleEvent $event): void {
        $metricAttributes = [
            'lsr.auth.operation' => $event->operation,
            'lsr.auth.outcome' => $event->outcome,
        ];

        try {
            $this->duration?->record($event->durationSeconds, $metricAttributes);
            $this->count?->add(1, $metricAttributes);
        } catch (Throwable) {
            // Continue with trace enrichment.
        }

        if (!$this->traces) {
            return;
        }

        try {
            $traceAttributes = $metricAttributes;
            if ($event->errorType !== null) {
                $traceAttributes['error.type'] = $event->errorType;
            }
            Span::getCurrent()->addEvent('lsr.auth.operation', $traceAttributes);
        } catch (Throwable) {
            // Telemetry must never affect authentication.
        }
    }
}
