<?php

declare(strict_types=1);

namespace Lsr\Otel;

use LogicException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs\NoopEventLoggerProvider;
use OpenTelemetry\API\Logs\NoopLoggerProvider;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Sdk;
use Throwable;

final class GlobalSdkRegistration
{
    private ?ScopeInterface $scope = null;

    public function __construct(Sdk $sdk, bool $enabled = true) {
        if (!$enabled) {
            return;
        }
        $this->assertGlobalsAreAvailable();

        $context = Configurator::create()
            ->withTracerProvider($sdk->getTracerProvider())
            ->withMeterProvider($sdk->getMeterProvider())
            ->withLoggerProvider($sdk->getLoggerProvider())
            ->withEventLoggerProvider($sdk->getEventLoggerProvider())
            ->withPropagator($sdk->getPropagator())
            ->withResponsePropagator($sdk->getResponsePropagator())
            ->storeInContext();
        $this->scope = Context::storage()->attach($context);
    }

    public function __destruct() {
        $this->detach();
    }

    public function detach(): void {
        $scope = $this->scope;
        $this->scope = null;
        try {
            $scope?->detach();
        } catch (Throwable) {
            // Telemetry cleanup must never affect application shutdown.
        }
    }

    private function assertGlobalsAreAvailable(): void {
        if (
            !Globals::tracerProvider() instanceof NoopTracerProvider
            || !Globals::meterProvider() instanceof NoopMeterProvider
            || !Globals::loggerProvider() instanceof NoopLoggerProvider
            || !Globals::eventLoggerProvider() instanceof NoopEventLoggerProvider
            || !Globals::propagator() instanceof NoopTextMapPropagator
            || !Globals::responsePropagator() instanceof NoopResponsePropagator
        ) {
            throw new LogicException(
                'Global OpenTelemetry providers are already configured. '
                . 'Disable the other SDK bootstrap or set otel.registerGlobal to false.',
            );
        }
    }
}
