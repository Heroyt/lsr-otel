<?php

declare(strict_types=1);

namespace Tests;

use Lsr\Otel\ProviderFactory;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use PHPUnit\Framework\TestCase;

final class ProviderFactoryTest extends TestCase
{
    public function testDisabledFactoryBuildsOneConsistentNoopSdk(): void {
        $factory = new ProviderFactory(false);
        $resource = $factory->createResource();
        $meterProvider = $factory->createMeterProvider($resource);
        $tracerProvider = $factory->createTracerProvider($meterProvider);
        $loggerProvider = $factory->createLoggerProvider($meterProvider, $resource);
        $propagator = $factory->createPropagator();
        $sdk = $factory->createSdk(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            $propagator,
        );

        self::assertInstanceOf(NoopMeterProvider::class, $meterProvider);
        self::assertInstanceOf(NoopTracerProvider::class, $tracerProvider);
        self::assertInstanceOf(NoopLoggerProvider::class, $loggerProvider);
        self::assertInstanceOf(NoopTextMapPropagator::class, $propagator);
        self::assertSame($meterProvider, $sdk->getMeterProvider());
        self::assertSame($tracerProvider, $sdk->getTracerProvider());
        self::assertSame($loggerProvider, $sdk->getLoggerProvider());
        self::assertSame($propagator, $sdk->getPropagator());
    }
}
