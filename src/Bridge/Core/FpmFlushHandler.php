<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use Lsr\Core\Http\AsyncHandlerInterface;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Throwable;

final readonly class FpmFlushHandler implements AsyncHandlerInterface
{
    public function __construct(private TelemetryLifecycleInterface $lifecycle) {
    }

    public function run(): void {
        try {
            $this->lifecycle->forceFlush();
        } catch (Throwable) {
            // Telemetry export must never affect request handling.
        }
    }
}
