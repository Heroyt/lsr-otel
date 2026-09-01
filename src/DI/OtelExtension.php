<?php

declare(strict_types=1);

namespace Lsr\Otel\DI;

use Lsr\Core\FpmHandler;
use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\CQRS\CommandBus;
use Lsr\Otel\Bridge\Console\ConsoleTelemetrySubscriber;
use Lsr\Otel\Bridge\Core\FpmFlushHandler;
use Lsr\Otel\Bridge\Core\HttpServerLifecycleHook;
use Lsr\Otel\Bridge\Cqrs\CommandLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\PeriodicWorkerLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\TaskConsumerLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\TaskProducerLifecycleHook;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Lifecycle\TelemetryLifecycle;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Lsr\Otel\ProviderFactory;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\WorkerLifecycleHookInterface;
use Lsr\Roadrunner\Tasks\TaskProducer;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
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
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @property-read object{
 *     enabled: bool,
 *     autoShutdown: bool,
 *     integrations: object{
 *         core: object{enabled: bool, traces: bool, metrics: bool},
 *         roadrunner: object{
 *             enabled: bool,
 *             traces: bool,
 *             metrics: bool,
 *             flushEvery: int,
 *             flushInterval: float
 *         },
 *         console: object{enabled: bool, traces: bool, metrics: bool},
 *         cqrs: object{enabled: bool, traces: bool, metrics: bool}
 *     }
 * }&stdClass $config
 */
final class OtelExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema {
        return Expect::structure([
            'enabled' => Expect::bool(true),
            'autoShutdown' => Expect::bool(true),
            'integrations' => Expect::structure([
                'core' => $this->integrationSchema(),
                'roadrunner' => Expect::structure([
                    'enabled' => Expect::bool(true),
                    'traces' => Expect::bool(true),
                    'metrics' => Expect::bool(true),
                    'flushEvery' => Expect::int(100)->min(1),
                    'flushInterval' => Expect::float(10.0)->min(0.001),
                ]),
                'console' => $this->integrationSchema(),
                'cqrs' => $this->integrationSchema(),
            ]),
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

        if (!$this->config->enabled) {
            return;
        }

        $this->registerCoreIntegration();
        $this->registerRoadRunnerIntegration();
        $this->registerConsoleIntegration();
        $this->registerCqrsIntegration();
    }

    public function beforeCompile(): void {
        $builder = $this->getContainerBuilder();

        if ($builder->hasDefinition($this->prefix('integration.core.http'))) {
            $this->wire(
                FpmHandler::class,
                'setRequestLifecycleHook',
                $this->prefix('integration.core.http'),
            );
            $this->wire(
                FpmHandler::class,
                'addAsyncHandler',
                $this->prefix('integration.core.flush'),
            );
        }

        if ($builder->hasDefinition($this->prefix('integration.roadrunner.http'))) {
            $this->wire(
                HttpWorker::class,
                'setRequestLifecycleHook',
                $this->prefix('integration.roadrunner.http'),
            );
            $this->wire(
                HttpWorker::class,
                'setWorkerLifecycleHook',
                $this->prefix('integration.roadrunner.worker'),
            );
            $this->wire(
                JobsWorker::class,
                'setTaskLifecycleHook',
                $this->prefix('integration.roadrunner.consumer'),
            );
            $this->wire(
                JobsWorker::class,
                'setWorkerLifecycleHook',
                $this->prefix('integration.roadrunner.worker'),
            );
            $this->wire(
                TaskProducer::class,
                'setLifecycleHook',
                $this->prefix('integration.roadrunner.producer'),
            );
        }

        if ($builder->hasDefinition($this->prefix('integration.cqrs.command'))) {
            $this->wire(
                CommandBus::class,
                'setLifecycleHook',
                $this->prefix('integration.cqrs.command'),
            );
        }

        if ($builder->hasDefinition($this->prefix('integration.console.subscriber'))) {
            $this->wireConsoleDispatcher();
        }
    }

    public function afterCompile(ClassType $class): void {
        $this->initialization->addBody(
            '$this->getService(?);',
            [$this->prefix('lifecycle')],
        );
    }

    private function integrationSchema(): Schema {
        return Expect::structure([
            'enabled' => Expect::bool(true),
            'traces' => Expect::bool(true),
            'metrics' => Expect::bool(true),
        ]);
    }

    private function registerCoreIntegration(): void {
        $config = $this->config->integrations->core;
        if (!$this->shouldRegister($config) || !interface_exists(RequestLifecycleHookInterface::class)) {
            return;
        }

        $builder = $this->getContainerBuilder();
        $builder->addDefinition($this->prefix('integration.core.http'))
            ->setType(RequestLifecycleHookInterface::class)
            ->setFactory(HttpServerLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                new Reference($this->prefix('propagator')),
                $config->traces,
                $config->metrics,
                'lsr/core',
            ]);
        $builder->addDefinition($this->prefix('integration.core.flush'))
            ->setType(FpmFlushHandler::class)
            ->setFactory(FpmFlushHandler::class, [new Reference($this->prefix('lifecycle'))]);
    }

    private function registerRoadRunnerIntegration(): void {
        $config = $this->config->integrations->roadrunner;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(TaskLifecycleHookInterface::class)
            || !interface_exists(TaskDispatchLifecycleHookInterface::class)
            || !interface_exists(WorkerLifecycleHookInterface::class)
        ) {
            return;
        }

        $builder = $this->getContainerBuilder();
        $instrumentation = new Reference($this->prefix('instrumentation'));
        $propagator = new Reference($this->prefix('propagator'));

        $builder->addDefinition($this->prefix('integration.roadrunner.http'))
            ->setType(RequestLifecycleHookInterface::class)
            ->setFactory(HttpServerLifecycleHook::class, [
                $instrumentation,
                $propagator,
                $config->traces,
                $config->metrics,
                'lsr/roadrunner',
            ]);
        $builder->addDefinition($this->prefix('integration.roadrunner.consumer'))
            ->setType(TaskLifecycleHookInterface::class)
            ->setFactory(TaskConsumerLifecycleHook::class, [
                $instrumentation,
                $propagator,
                $config->traces,
                $config->metrics,
            ]);
        $builder->addDefinition($this->prefix('integration.roadrunner.producer'))
            ->setType(TaskDispatchLifecycleHookInterface::class)
            ->setFactory(TaskProducerLifecycleHook::class, [
                $instrumentation,
                $propagator,
                $config->traces,
                $config->metrics,
            ]);
        $builder->addDefinition($this->prefix('integration.roadrunner.worker'))
            ->setType(WorkerLifecycleHookInterface::class)
            ->setFactory(PeriodicWorkerLifecycleHook::class, [
                new Reference($this->prefix('lifecycle')),
                $config->flushEvery,
                $config->flushInterval,
            ]);
    }

    private function registerConsoleIntegration(): void {
        $config = $this->config->integrations->console;
        if (
            !$this->shouldRegister($config)
            || !class_exists(ConsoleEvents::class)
            || !class_exists(EventDispatcher::class)
            || !interface_exists(EventSubscriberInterface::class)
        ) {
            return;
        }

        $builder = $this->getContainerBuilder();
        $subscriber = $builder->addDefinition($this->prefix('integration.console.subscriber'))
            ->setType(EventSubscriberInterface::class)
            ->setFactory(ConsoleTelemetrySubscriber::class, [
                new Reference($this->prefix('instrumentation')),
                new Reference($this->prefix('lifecycle')),
                $config->traces,
                $config->metrics,
            ]);
        $builder->addDefinition($this->prefix('integration.console.dispatcher'))
            ->setType(EventDispatcherInterface::class)
            ->setFactory(EventDispatcher::class)
            ->setAutowired(false)
            ->addSetup('addSubscriber', [$subscriber]);
    }

    private function registerCqrsIntegration(): void {
        $config = $this->config->integrations->cqrs;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(\Lsr\CQRS\Lifecycle\CommandLifecycleHookInterface::class)
        ) {
            return;
        }

        $builder = $this->getContainerBuilder();
        $builder->addDefinition($this->prefix('integration.cqrs.command'))
            ->setType(\Lsr\CQRS\Lifecycle\CommandLifecycleHookInterface::class)
            ->setFactory(CommandLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    /**
     * @param object{enabled: bool, traces: bool, metrics: bool} $config
     */
    private function shouldRegister(object $config): bool {
        return $config->enabled && ($config->traces || $config->metrics);
    }

    private function wire(string $type, string $method, string $service): void {
        foreach ($this->serviceDefinitions($type) as $definition) {
            $definition->addSetup($method, [new Reference($service)]);
        }
    }

    private function wireConsoleDispatcher(): void {
        $builder = $this->getContainerBuilder();
        $ownDispatcher = $this->prefix('integration.console.dispatcher');
        $dispatcher = $ownDispatcher;

        foreach ($this->serviceDefinitions(EventDispatcherInterface::class) as $name => $definition) {
            if ($name === $ownDispatcher) {
                continue;
            }
            $definition->addSetup('addSubscriber', [
                new Reference($this->prefix('integration.console.subscriber')),
            ]);
            $dispatcher = $name;
            break;
        }

        foreach ($this->serviceDefinitions(Application::class) as $definition) {
            $definition->addSetup('setDispatcher', [new Reference($dispatcher)]);
        }
    }

    /**
     * @return array<string, ServiceDefinition>
     */
    private function serviceDefinitions(string $type): array {
        $definitions = [];
        foreach ($this->getContainerBuilder()->getDefinitions() as $name => $definition) {
            $definitionType = $definition->getType();
            if (
                $definition instanceof ServiceDefinition
                && $definitionType !== null
                && is_a($definitionType, $type, true)
            ) {
                $definitions[$name] = $definition;
            }
        }
        return $definitions;
    }
}
