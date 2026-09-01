<?php

declare(strict_types=1);

namespace Tests;

use LogicException;
use Lsr\Otel\GlobalSdkRegistration;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\TestCase;

final class GlobalSdkRegistrationTest extends TestCase
{
    public function testRegistersSdkProvidersUntilDetached(): void {
        $sdk = $this->sdk();
        $registration = new GlobalSdkRegistration($sdk);

        self::assertSame($sdk->getTracerProvider(), Globals::tracerProvider());
        self::assertSame($sdk->getMeterProvider(), Globals::meterProvider());
        self::assertSame($sdk->getLoggerProvider(), Globals::loggerProvider());

        $registration->detach();
        $registration->detach();

        self::assertNotSame($sdk->getTracerProvider(), Globals::tracerProvider());
        self::assertNotSame($sdk->getMeterProvider(), Globals::meterProvider());
        self::assertNotSame($sdk->getLoggerProvider(), Globals::loggerProvider());
        $this->shutdown($sdk);
    }

    public function testDisabledRegistrationDoesNotReplaceGlobals(): void {
        $sdk = $this->sdk();
        $registration = new GlobalSdkRegistration($sdk, false);

        self::assertNotSame($sdk->getTracerProvider(), Globals::tracerProvider());
        self::assertNotSame($sdk->getMeterProvider(), Globals::meterProvider());
        self::assertNotSame($sdk->getLoggerProvider(), Globals::loggerProvider());

        $registration->detach();
        $this->shutdown($sdk);
    }

    public function testRegistrationPreservesTheActiveContext(): void {
        $activeProvider = TracerProvider::builder()->build();
        $span = $activeProvider->getTracer('lsr-otel-tests')
            ->spanBuilder('active')
            ->startSpan();
        $scope = $span->activate();
        $sdk = $this->sdk();
        $registration = null;

        try {
            $registration = new GlobalSdkRegistration($sdk);

            self::assertSame($span->getContext(), Span::getCurrent()->getContext());
        } finally {
            $registration?->detach();
            $scope->detach();
            $span->end();
            $activeProvider->shutdown();
            $this->shutdown($sdk);
        }
    }

    public function testRejectsAnExistingGlobalSdk(): void {
        $existingProvider = TracerProvider::builder()->build();
        $scope = Configurator::create()
            ->withTracerProvider($existingProvider)
            ->activate();
        $sdk = $this->sdk();

        try {
            new GlobalSdkRegistration($sdk);
            self::fail('A second global SDK was registered.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('already configured', $exception->getMessage());
        } finally {
            $scope->detach();
            $existingProvider->shutdown();
            $this->shutdown($sdk);
        }
    }

    private function sdk(): Sdk {
        return Sdk::builder()
            ->setTracerProvider(TracerProvider::builder()->build())
            ->setMeterProvider(MeterProvider::builder()->build())
            ->setLoggerProvider(LoggerProvider::builder()->build())
            ->build();
    }

    private function shutdown(Sdk $sdk): void {
        $tracerProvider = $sdk->getTracerProvider();
        $meterProvider = $sdk->getMeterProvider();
        self::assertInstanceOf(TracerProviderInterface::class, $tracerProvider);
        self::assertInstanceOf(MeterProviderInterface::class, $meterProvider);
        $tracerProvider->shutdown();
        $meterProvider->shutdown();
        $sdk->getLoggerProvider()->shutdown();
    }
}
