<?php

declare(strict_types=1);

namespace Lsr\Otel;

use OpenTelemetry\Context\Propagation\NoopTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderFactory;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderFactory;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Propagation\PropagatorFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\SdkBuilder;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderFactory;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

final readonly class ProviderFactory
{
    public function __construct(private bool $enabled = true) {
    }

    public function createResource(): ResourceInfo {
        return ResourceInfoFactory::defaultResource();
    }

    public function createMeterProvider(ResourceInfo $resource): MeterProviderInterface {
        if ( ! $this->enabled) {
            return new NoopMeterProvider();
        }

        return (new MeterProviderFactory())->create($resource);
    }

    public function createTracerProvider(MeterProviderInterface $meterProvider): TracerProviderInterface {
        if ( ! $this->enabled) {
            return new NoopTracerProvider();
        }

        return (new TracerProviderFactory())->create($meterProvider);
    }

    public function createLoggerProvider(
        MeterProviderInterface $meterProvider,
        ResourceInfo $resource,
    ): LoggerProviderInterface {
        if ( ! $this->enabled) {
            return NoopLoggerProvider::getInstance();
        }

        return (new LoggerProviderFactory())->create($meterProvider, $resource);
    }

    public function createPropagator(): TextMapPropagatorInterface {
        if ( ! $this->enabled) {
            return NoopTextMapPropagator::getInstance();
        }

        return (new PropagatorFactory())->create();
    }

    public function createSdk(
        TracerProviderInterface $tracerProvider,
        MeterProviderInterface $meterProvider,
        LoggerProviderInterface $loggerProvider,
        TextMapPropagatorInterface $propagator,
    ): Sdk {
        return (new SdkBuilder())
            ->setTracerProvider($tracerProvider)
            ->setMeterProvider($meterProvider)
            ->setLoggerProvider($loggerProvider)
            ->setPropagator($propagator)
            ->build();
    }
}
