<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Inertia;

use Lsr\Inertia\Lifecycle\InertiaLifecycleHookInterface;
use Lsr\Inertia\Lifecycle\InertiaLifecycleScopeInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class InertiaLifecycleHook implements InertiaLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/inertia') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/inertia') : null;
        $this->duration = $meter?->createHistogram(
            'lsr.inertia.render.duration',
            's',
            'Duration of Inertia prop resolution and response rendering.',
        );
        $this->count = $meter?->createCounter(
            'lsr.inertia.renders',
            '{render}',
            'Inertia response renders.',
        );
    }

    public function begin(
        string $component,
        int $inputPropCount,
        bool $inertiaRequest,
    ): InertiaLifecycleScopeInterface {
        $metricAttributes = ['lsr.inertia.request' => $inertiaRequest];
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $span = $this->tracer
                    ->spanBuilder('inertia ' . $component)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes([
                        'lsr.inertia.component' => $component,
                        'lsr.inertia.input_prop_count' => $inputPropCount,
                        'lsr.inertia.request' => $inertiaRequest,
                    ])
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                try {
                    $scope?->detach();
                    $span?->end();
                } catch (Throwable) {
                    // Ignore cleanup failures from telemetry implementations.
                }
                $span = null;
                $scope = null;
            }
        }

        return new InertiaLifecycleScope(
            $span,
            $scope,
            $this->duration,
            $this->count,
            $metricAttributes,
        );
    }
}
