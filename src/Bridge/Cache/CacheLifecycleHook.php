<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Cache;

use Lsr\Caching\Lifecycle\CacheLifecycleHookInterface;
use Lsr\Caching\Lifecycle\CacheLifecycleScopeInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class CacheLifecycleHook implements CacheLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $operations;
    private ?CounterInterface $items;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/cache') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/cache') : null;
        $this->duration = $meter?->createHistogram(
            'lsr.cache.operation.duration',
            's',
            'Duration of cache load operations.',
        );
        $this->operations = $meter?->createCounter(
            'lsr.cache.operations',
            '{operation}',
            'Cache load operations.',
        );
        $this->items = $meter?->createCounter(
            'lsr.cache.items',
            '{item}',
            'Cache items returned as hits, misses, or generated values.',
        );
    }

    public function begin(string $operation, int $itemCount): CacheLifecycleScopeInterface {
        $attributes = ['lsr.cache.operation' => $operation];
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $span = $this->tracer
                    ->spanBuilder('cache ' . $operation)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes($attributes)
                    ->setAttribute('lsr.cache.item_count', $itemCount)
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

        return new CacheLifecycleScope(
            $span,
            $scope,
            $this->duration,
            $this->operations,
            $this->items,
            $attributes,
        );
    }
}
