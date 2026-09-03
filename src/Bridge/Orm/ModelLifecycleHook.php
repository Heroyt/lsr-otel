<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Orm;

use Lsr\Orm\Lifecycle\ModelLifecycleEvent;
use Lsr\Orm\Lifecycle\ModelLifecycleHookInterface;
use Lsr\Orm\Lifecycle\ModelLifecycleScopeInterface;
use Lsr\Orm\Model;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class ModelLifecycleHook implements ModelLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traces = true,
        bool $metrics = true,
        private bool $mutations = true,
        private bool $queries = true,
        private bool $hydration = false,
        private bool $modelMetrics = false,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/orm') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/orm') : null;
        $this->duration = DurationHistogram::create(
            $meter,
            'lsr.orm.operation.duration',
            'Duration of ORM model lifecycle operations.',
        );
        $this->count = $meter?->createCounter(
            'lsr.orm.operations',
            '{operation}',
            'ORM model lifecycle operation outcomes.',
        );
    }

    public function captures(string $category): bool {
        return match ($category) {
            ModelLifecycleEvent::MUTATION => $this->mutations,
            ModelLifecycleEvent::QUERY => $this->queries,
            ModelLifecycleEvent::HYDRATION => $this->hydration,
            default => false,
        };
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public function begin(
        string $category,
        string $operation,
        string $modelClass,
    ): ModelLifecycleScopeInterface {
        $metricAttributes = [
            'lsr.orm.category' => $category,
            'lsr.orm.operation' => $operation,
        ];
        if ($this->modelMetrics) {
            $metricAttributes['lsr.orm.model.class'] = $modelClass;
        }
        $traceAttributes = $metricAttributes + [
            'lsr.orm.model.class' => $modelClass,
        ];

        $span = null;
        $scope = null;
        if ($this->tracer !== null) {
            try {
                $span = $this->tracer
                    ->spanBuilder('orm ' . $operation)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes($traceAttributes)
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                try {
                    $span?->end();
                } catch (Throwable) {
                    // Ignore cleanup failures from telemetry implementations.
                }
                $span = null;
                $scope = null;
            }
        }

        return new ModelLifecycleScope(
            $span,
            $scope,
            $this->duration,
            $this->count,
            $traceAttributes,
            $metricAttributes,
        );
    }
}
