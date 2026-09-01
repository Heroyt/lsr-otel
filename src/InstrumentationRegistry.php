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

final class InstrumentationRegistry
{
    /** @var array<string, array<string, Metrics>> */
    private array $metrics = [];

    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly MeterProviderInterface $meterProvider,
        private readonly LoggerProviderInterface $loggerProvider,
    ) {
    }

    public function tracer(string $packageName, ?string $version = null): TracerInterface {
        $this->assertInstrumentationName($packageName);

        return $this->tracerProvider->getTracer($packageName, $version);
    }

    public function tracing(string $packageName, ?string $version = null): Tracing {
        return new Tracing($this->tracer($packageName, $version));
    }

    public function metrics(string $packageName, ?string $version = null): Metrics {
        $this->assertInstrumentationName($packageName);
        $versionKey = $version === null ? '' : 'v:' . $version;

        return $this->metrics[$packageName][$versionKey] ??=
            new Metrics($this->meterProvider->getMeter($packageName, $version));
    }

    public function meter(string $packageName, ?string $version = null): MeterInterface {
        $this->assertInstrumentationName($packageName);

        return $this->meterProvider->getMeter($packageName, $version);
    }

    public function logger(string $packageName, ?string $version = null): LoggerInterface {
        $this->assertInstrumentationName($packageName);

        return $this->loggerProvider->getLogger($packageName, $version);
    }

    private function assertInstrumentationName(string $packageName): void {
        if (
            preg_match(
                '/^[a-z0-9](?:[_.-]?[a-z0-9]+)*\/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*$/D',
                $packageName,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf('Instrumentation name "%s" must be a Composer package name.', $packageName),
            );
        }
    }
}
