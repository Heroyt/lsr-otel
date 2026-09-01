<?php

declare(strict_types=1);

namespace Tests\Lifecycle;

use Lsr\Otel\Lifecycle\TelemetryLifecycle;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelemetryLifecycleTest extends TestCase
{
    public function testForceFlushAttemptsEveryProviderAndContainsFailures(): void {
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

    public function testShutdownRunsOnceAndRetainsTheFirstResult(): void {
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
}
