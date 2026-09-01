<?php

declare(strict_types=1);

namespace Lsr\Otel\Lifecycle;

use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

final class TelemetryLifecycle implements TelemetryLifecycleInterface
{
    private ?bool $shutdownResult = null;

    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly MeterProviderInterface $meterProvider,
        private readonly LoggerProviderInterface $loggerProvider,
        bool $autoShutdown = true,
    ) {
        if ($autoShutdown) {
            ShutdownHandler::register($this->shutdown(...));
        }
    }

    public function forceFlush(): bool {
        if ($this->shutdownResult !== null) {
            return false;
        }

        return $this->attemptAll(
            $this->tracerProvider->forceFlush(...),
            $this->meterProvider->forceFlush(...),
            $this->loggerProvider->forceFlush(...),
        );
    }

    public function shutdown(): bool {
        if ($this->shutdownResult !== null) {
            return $this->shutdownResult;
        }

        return $this->shutdownResult = $this->attemptAll(
            $this->tracerProvider->shutdown(...),
            $this->meterProvider->shutdown(...),
            $this->loggerProvider->shutdown(...),
        );
    }

    private function attemptAll(callable ...$operations): bool {
        $success = true;

        foreach ($operations as $operation) {
            try {
                $success = $operation() && $success;
            } catch (Throwable) {
                $success = false;
            }
        }

        return $success;
    }
}
