<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Scheduler;

use Lsr\Otel\Internal\TelemetryOperation;
use Lsr\Scheduler\Lifecycle\SchedulerLifecycleScopeInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class SchedulerLifecycleScope implements SchedulerLifecycleScopeInterface
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

    public function complete(?int $exitCode = null): void {
        if ($exitCode !== null) {
            try {
                $this->span?->setAttribute('process.exit.code', $exitCode);
            } catch (Throwable) {
                // Continue with metrics and span completion.
            }
        }

        $failed = $this->operation->hasFailed() || ($exitCode !== null && $exitCode !== 0);
        $this->operation->complete([
            'lsr.operation.outcome' => $failed ? 'failure' : 'success',
        ], error: $failed);
    }
}
