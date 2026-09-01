<?php

declare(strict_types=1);

namespace Lsr\Otel\DI;

use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Lifecycle\TelemetryLifecycle;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Lsr\Otel\ProviderFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use stdClass;

/**
 * @property-read object{enabled: bool, autoShutdown: bool}&stdClass $config
 */
final class OtelExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema {
        return Expect::structure([
            'enabled' => Expect::bool(true),
            'autoShutdown' => Expect::bool(true),
        ]);
    }

    public function loadConfiguration(): void {
        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('providerFactory'))
            ->setType(ProviderFactory::class)
            ->setFactory(ProviderFactory::class, [$this->config->enabled]);
        $providerFactory = new Reference($this->prefix('providerFactory'));

        $resource = $builder->addDefinition($this->prefix('resource'))
            ->setType(ResourceInfo::class)
            ->setFactory([$providerFactory, 'createResource']);

        $meterProvider = $builder->addDefinition($this->prefix('meterProvider'))
            ->setType(MeterProviderInterface::class)
            ->setFactory([$providerFactory, 'createMeterProvider'], [$resource]);

        $tracerProvider = $builder->addDefinition($this->prefix('tracerProvider'))
            ->setType(TracerProviderInterface::class)
            ->setFactory([$providerFactory, 'createTracerProvider'], [$meterProvider]);

        $loggerProvider = $builder->addDefinition($this->prefix('loggerProvider'))
            ->setType(LoggerProviderInterface::class)
            ->setFactory(
                [$providerFactory, 'createLoggerProvider'],
                [$meterProvider, $resource],
            );

        $propagator = $builder->addDefinition($this->prefix('propagator'))
            ->setType(TextMapPropagatorInterface::class)
            ->setFactory([$providerFactory, 'createPropagator']);

        $builder->addDefinition($this->prefix('sdk'))
            ->setType(Sdk::class)
            ->setFactory(
                [$providerFactory, 'createSdk'],
                [$tracerProvider, $meterProvider, $loggerProvider, $propagator],
            );

        $builder->addDefinition($this->prefix('lifecycle'))
            ->setType(TelemetryLifecycleInterface::class)
            ->setFactory(
                TelemetryLifecycle::class,
                [$tracerProvider, $meterProvider, $loggerProvider, $this->config->autoShutdown],
            );

        $builder->addDefinition($this->prefix('instrumentation'))
            ->setType(InstrumentationRegistry::class)
            ->setFactory(
                InstrumentationRegistry::class,
                [$tracerProvider, $meterProvider, $loggerProvider],
            );
    }

    public function afterCompile(ClassType $class): void {
        $this->initialization->addBody(
            '$this->getService(?);',
            [$this->prefix('lifecycle')],
        );
    }
}
