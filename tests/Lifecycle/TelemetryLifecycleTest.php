<?php

declare(strict_types=1);

namespace Tests\Lifecycle;

use Lsr\Otel\GlobalSdkRegistration;
use Lsr\Otel\Lifecycle\TelemetryLifecycle;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelemetryLifecycleTest extends TestCase
{
    public function test_force_flush_attempts_every_provider_and_contains_failures(): void {
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider->expects(self::once())
            ->method('forceFlush')
            ->willReturn(false);

        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->expects(self::once())
            ->method('forceFlush')
            ->willThrowException(new RuntimeException('export failed'));

        $loggerProvider = $this->createMock(LoggerProviderInterface::class);
        $loggerProvider->expects(self::once())
            ->method('forceFlush')
            ->willReturn(true);

        $lifecycle = new TelemetryLifecycle(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            false,
        );

        self::assertFalse($lifecycle->forceFlush());
    }

    public function test_shutdown_runs_once_and_retains_the_first_result(): void {
        $tracerProvider = $this->createMock(TracerProviderInterface::class);
        $tracerProvider->expects(self::once())
            ->method('shutdown')
            ->willReturn(true);

        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->expects(self::once())
            ->method('shutdown')
            ->willReturn(false);

        $loggerProvider = $this->createMock(LoggerProviderInterface::class);
        $loggerProvider->expects(self::once())
            ->method('shutdown')
            ->willReturn(true);

        $lifecycle = new TelemetryLifecycle(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            false,
        );

        self::assertFalse($lifecycle->shutdown());
        self::assertFalse($lifecycle->shutdown());
        self::assertFalse($lifecycle->forceFlush());
    }

    public function test_shutdown_detaches_global_sdk_registration(): void {
        $tracerProvider = TracerProvider::builder()->build();
        $meterProvider = MeterProvider::builder()->build();
        $loggerProvider = LoggerProvider::builder()->build();
        $sdk = Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setMeterProvider($meterProvider)
            ->setLoggerProvider($loggerProvider)
            ->build();
        $registration = new GlobalSdkRegistration($sdk);
        $lifecycle = new TelemetryLifecycle(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            false,
            $registration,
        );

        self::assertSame($sdk->getTracerProvider(), Globals::tracerProvider());
        self::assertTrue($lifecycle->shutdown());
        self::assertNotSame($sdk->getTracerProvider(), Globals::tracerProvider());
        self::assertNotSame($sdk->getMeterProvider(), Globals::meterProvider());
        self::assertNotSame($sdk->getLoggerProvider(), Globals::loggerProvider());
    }
}
