<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Lsr\Roadrunner\Lifecycle\WorkerLifecycleHookInterface;
use Throwable;

final class PeriodicWorkerLifecycleHook implements WorkerLifecycleHookInterface
{
    private int $iterations = 0;
    private int $lastFlush;

    public function __construct(
        private readonly TelemetryLifecycleInterface $lifecycle,
        private readonly int $flushEvery = 100,
        private readonly float $flushInterval = 10.0,
    ) {
        $this->lastFlush = hrtime(true);
    }

    public function afterIteration(): void {
        $this->iterations++;
        $elapsed = (hrtime(true) - $this->lastFlush) / 1_000_000_000;
        if ($this->iterations < $this->flushEvery && $elapsed < $this->flushInterval) {
            return;
        }

        $this->flush();
    }

    public function workerStopped(): void {
        $this->flush();
    }

    private function flush(): void {
        try {
            $this->lifecycle->forceFlush();
        } catch (Throwable) {
            // Telemetry export must never affect worker execution.
        }
        $this->iterations = 0;
        $this->lastFlush = hrtime(true);
    }
}
