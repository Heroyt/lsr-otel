<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use Lsr\Otel\Internal\TelemetryOperation;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleScopeInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Throwable;

final class TaskConsumerLifecycleScope implements TaskLifecycleScopeInterface
{
    private readonly TelemetryOperation $operation;

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function __construct(
        private readonly ReceivedTaskInterface $task,
        ?SpanInterface $span,
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

    public function complete(): void {
        try {
            $outcome = $this->task->isSuccessful()
                ? 'success'
                : ($this->task->isFails() ? 'failure' : 'unsettled');
        } catch (Throwable $exception) {
            $this->operation->recordException($exception);
            $outcome = 'unknown';
        }

        $this->operation->complete(
            ['messaging.operation.outcome' => $outcome],
            error: $outcome !== 'success',
        );
    }
}
