<?php

declare(strict_types=1);

namespace Lsr\Otel\Lifecycle;

interface TelemetryLifecycleInterface
{
    /**
     * Flush every configured signal provider.
     *
     * Returns false when any provider reports or throws a failure. Provider
     * failures never escape into application control flow.
     */
    public function forceFlush(): bool;

    /**
     * Shut every configured signal provider down once per process.
     *
     * Repeated calls return the result of the first shutdown attempt.
     */
    public function shutdown(): bool;
}
