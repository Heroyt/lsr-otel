<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Request;

use Lsr\Core\Requests\Lifecycle\RequestMappingEvent;
use Lsr\Core\Requests\Lifecycle\RequestMappingLifecycleHookInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\Span;
use Throwable;

final readonly class RequestMappingLifecycleHook implements RequestMappingLifecycleHookInterface
{
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        private bool $traces = true,
        bool $metrics = true,
    ) {
        $meter = $metrics ? $instrumentation->meter('lsr/request') : null;
        $this->duration = $meter?->createHistogram(
            'lsr.request.mapping.duration',
            's',
            'Duration of request-to-object mapping and validation.',
        );
        $this->count = $meter?->createCounter(
            'lsr.request.mappings',
            '{mapping}',
            'Request-to-object mapping outcomes.',
        );
    }

    public function record(RequestMappingEvent $event): void {
        $metricAttributes = [
            'lsr.request.source' => $event->source,
            'lsr.request.outcome' => $event->outcome,
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
            $traceAttributes = $metricAttributes + [
                'lsr.request.target_class' => $event->targetClass,
                'lsr.request.validation_error_count' => $event->validationErrorCount,
            ];
            if ($event->errorType !== null) {
                $traceAttributes['error.type'] = $event->errorType;
            }
            Span::getCurrent()->addEvent('lsr.request.mapping', $traceAttributes);
        } catch (Throwable) {
            // Telemetry must never affect request mapping.
        }
    }
}
