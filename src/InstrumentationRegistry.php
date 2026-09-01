<?php

declare(strict_types=1);

namespace Lsr\Otel;

use InvalidArgumentException;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

final readonly class InstrumentationRegistry
{
    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private MeterProviderInterface $meterProvider,
        private LoggerProviderInterface $loggerProvider,
    ) {
    }

    public function tracer(string $packageName, ?string $version = null): TracerInterface {
        $this->assertPackageName($packageName);

        return $this->tracerProvider->getTracer($packageName, $version);
    }

    public function meter(string $packageName, ?string $version = null): MeterInterface {
        $this->assertPackageName($packageName);

        return $this->meterProvider->getMeter($packageName, $version);
    }

    public function logger(string $packageName, ?string $version = null): LoggerInterface {
        $this->assertPackageName($packageName);

        return $this->loggerProvider->getLogger($packageName, $version);
    }

    private function assertPackageName(string $packageName): void {
        if (preg_match('/^lsr\/[a-z0-9]+(?:-[a-z0-9]+)*$/D', $packageName) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Instrumentation name "%s" must be an LSR Composer package name.', $packageName),
            );
        }
    }
}
