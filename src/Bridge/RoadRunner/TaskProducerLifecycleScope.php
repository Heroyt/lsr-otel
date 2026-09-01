<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use Lsr\Otel\Internal\TelemetryOperation;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleScopeInterface;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Throwable;

final class TaskProducerLifecycleScope implements TaskDispatchLifecycleScopeInterface
{
    /**
     * @param non-empty-list<PreparedTaskInterface> $tasks
     * @param non-empty-list<TelemetryOperation> $operations
     */
    public function __construct(
        private readonly array $tasks,
        private readonly array $operations,
    ) {
    }

    /**
     * @return non-empty-list<PreparedTaskInterface>
     */
    public function tasks(): array {
        return $this->tasks;
    }

    public function recordException(Throwable $exception): void {
        foreach ($this->operations as $operation) {
            $operation->recordException($exception);
        }
    }

    public function complete(): void {
        foreach ($this->operations as $operation) {
            $operation->complete([
                'messaging.operation.outcome' => $operation->hasFailed() ? 'failure' : 'success',
            ]);
        }
    }
}
