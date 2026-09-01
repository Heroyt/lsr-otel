<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Inertia;

use Lsr\Inertia\Lifecycle\InertiaLifecycleScopeInterface;
use Lsr\Otel\Internal\TelemetryOperation;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class InertiaLifecycleScope implements InertiaLifecycleScopeInterface
{
    private readonly TelemetryOperation $operation;

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function __construct(
        private readonly ?SpanInterface $span,
        ?ScopeInterface $scope,
        ?HistogramInterface $duration,
        ?CounterInterface $count,
        array $attributes,
    ) {
        $this->operation = new TelemetryOperation(
            $span,
            $scope,
            $duration,
            $count,
            $attributes,
            hrtime(true),
        );
    }

    public function recordException(Throwable $exception): void {
        $this->operation->recordException($exception);
    }

    public function complete(int $resolvedPropCount, int $deferredPropCount, int $rescuedPropCount): void {
        try {
            $this->span?->setAttributes([
                'lsr.inertia.resolved_prop_count' => $resolvedPropCount,
                'lsr.inertia.deferred_prop_count' => $deferredPropCount,
                'lsr.inertia.rescued_prop_count' => $rescuedPropCount,
            ]);
        } catch (Throwable) {
            // Continue with metrics and span completion.
        }

        $failed = $this->operation->hasFailed();
        $this->operation->complete([
            'lsr.operation.outcome' => $failed ? 'failure' : 'success',
        ], error: $failed);
    }
}
