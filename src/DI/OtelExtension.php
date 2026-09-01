<?php

declare(strict_types=1);

namespace Lsr\Otel\DI;

use Lsr\Caching\Cache;
use Lsr\Caching\Lifecycle\CacheLifecycleHookInterface;
use Lsr\Core\App;
use Lsr\Core\Auth\Lifecycle\AuthLifecycleHookInterface;
use Lsr\Core\Auth\Services\Auth;
use Lsr\Core\FpmHandler;
use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RouteResolutionHookInterface;
use Lsr\Core\Requests\Lifecycle\RequestMappingLifecycleHookInterface;
use Lsr\Core\Requests\Validation\RequestValidationMapper;
use Lsr\Db\Connection;
use Lsr\Db\Lifecycle\DatabaseLifecycleHookInterface;
use Lsr\Inertia\Lifecycle\InertiaLifecycleHookInterface;
use Lsr\Inertia\Services\Inertia;
use Lsr\Orm\Lifecycle\ModelLifecycleHookInterface;
use Lsr\Orm\ModelRepository;
use Lsr\Otel\Bridge\Auth\AuthLifecycleHook;
use Lsr\Otel\Bridge\Cache\CacheLifecycleHook;
use Lsr\CQRS\CommandBus;
use Lsr\Otel\Bridge\Console\ConsoleTelemetrySubscriber;
use Lsr\Otel\Bridge\Core\FpmFlushHandler;
use Lsr\Otel\Bridge\Core\HttpServerLifecycleHook;
use Lsr\Otel\Bridge\Cqrs\CommandLifecycleHook;
use Lsr\Otel\Bridge\Core\RouteResolutionHook;
use Lsr\Otel\Bridge\Database\DatabaseLifecycleHook;
use Lsr\Otel\Bridge\Inertia\InertiaLifecycleHook;
use Lsr\Otel\Bridge\Orm\ModelLifecycleHook;
use Lsr\Otel\Bridge\Request\RequestMappingLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\PeriodicWorkerLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\TaskConsumerLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\TaskProducerLifecycleHook;
use Lsr\Otel\Bridge\Scheduler\SchedulerLifecycleHook;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Metrics;
use Lsr\Otel\Lifecycle\TelemetryLifecycle;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Lsr\Otel\ProviderFactory;
use Lsr\Otel\Tracing;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\WorkerLifecycleHookInterface;
use Lsr\Scheduler\Internal\ScheduledCommandMessageHandler;
use Lsr\Scheduler\Internal\SchedulerJobMessageHandler;
use Lsr\Scheduler\Lifecycle\SchedulerLifecycleHookInterface;
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
 *     applicationInstrumentation: object{name: ?string, version: ?string},
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
 *         cqrs: object{enabled: bool, traces: bool, metrics: bool},
 *         cache: object{enabled: bool, traces: bool, metrics: bool},
 *         routing: object{enabled: bool, traces: bool, metrics: bool},
 *         scheduler: object{enabled: bool, traces: bool, metrics: bool},
 *         auth: object{enabled: bool, traces: bool, metrics: bool},
 *         request: object{enabled: bool, traces: bool, metrics: bool},
 *         inertia: object{enabled: bool, traces: bool, metrics: bool},
 *         database: object{enabled: bool, traces: bool, metrics: bool, includeRawSql: bool},
 *         orm: object{
 *             enabled: bool,
 *             traces: bool,
 *             metrics: bool,
 *             mutations: bool,
 *             queries: bool,
 *             hydration: bool,
 *             modelMetrics: bool
 *         }
 *     }
 * }&stdClass $config
 */
final class OtelExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema {
        return Expect::structure([
            'enabled' => Expect::bool(true),
            'autoShutdown' => Expect::bool(true),
            'applicationInstrumentation' => Expect::structure([
                'name' => Expect::string()->nullable()->default(null),
                'version' => Expect::string()->nullable()->default(null),
            ]),
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
                'cache' => $this->integrationSchema(),
                'routing' => $this->integrationSchema(),
                'scheduler' => $this->integrationSchema(),
                'auth' => $this->integrationSchema(),
                'request' => $this->integrationSchema(),
                'inertia' => $this->integrationSchema(),
                'database' => Expect::structure([
                    'enabled' => Expect::bool(true),
                    'traces' => Expect::bool(true),
                    'metrics' => Expect::bool(true),
                    'includeRawSql' => Expect::bool(false),
                ]),
                'orm' => Expect::structure([
                    'enabled' => Expect::bool(true),
                    'traces' => Expect::bool(true),
                    'metrics' => Expect::bool(true),
                    'mutations' => Expect::bool(true),
                    'queries' => Expect::bool(true),
                    'hydration' => Expect::bool(false),
                    'modelMetrics' => Expect::bool(false),
                ]),
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
        $this->registerApplicationInstrumentation();

        if (!$this->config->enabled) {
            return;
        }

        $this->registerCoreIntegration();
        $this->registerRoadRunnerIntegration();
        $this->registerConsoleIntegration();
        $this->registerCqrsIntegration();
        $this->registerCacheIntegration();
        $this->registerRoutingIntegration();
        $this->registerSchedulerIntegration();
        $this->registerAuthIntegration();
        $this->registerRequestIntegration();
        $this->registerInertiaIntegration();
        $this->registerDatabaseIntegration();
        $this->registerOrmIntegration();
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

        if ($builder->hasDefinition($this->prefix('integration.cache'))) {
            $this->wire(Cache::class, 'setLifecycleHook', $this->prefix('integration.cache'));
        }

        if ($builder->hasDefinition($this->prefix('integration.routing'))) {
            $this->wire(App::class, 'setRouteResolutionHook', $this->prefix('integration.routing'));
        }

        if ($builder->hasDefinition($this->prefix('integration.scheduler'))) {
            $this->wire(
                SchedulerJobMessageHandler::class,
                'setLifecycleHook',
                $this->prefix('integration.scheduler'),
            );
            $this->wire(
                ScheduledCommandMessageHandler::class,
                'setLifecycleHook',
                $this->prefix('integration.scheduler'),
            );
        }

        if ($builder->hasDefinition($this->prefix('integration.auth'))) {
            $this->wire(Auth::class, 'setLifecycleHook', $this->prefix('integration.auth'));
        }

        if ($builder->hasDefinition($this->prefix('integration.request'))) {
            $this->wire(
                RequestValidationMapper::class,
                'setLifecycleHook',
                $this->prefix('integration.request'),
            );
        }

        if ($builder->hasDefinition($this->prefix('integration.inertia'))) {
            $this->wire(Inertia::class, 'setLifecycleHook', $this->prefix('integration.inertia'));
        }

        if ($builder->hasDefinition($this->prefix('integration.database'))) {
            $this->wireDatabaseIntegration();
        }
    }

    public function afterCompile(ClassType $class): void {
        $this->initialization->addBody(
            '$this->getService(?);',
            [$this->prefix('lifecycle')],
        );
        if ($this->getContainerBuilder()->hasDefinition($this->prefix('integration.orm'))) {
            $this->initialization->addBody(
                '\\Lsr\\Orm\\ModelRepository::setLifecycleHook($this->getService(?));',
                [$this->prefix('integration.orm')],
            );
        }
    }

    private function integrationSchema(): Schema {
        return Expect::structure([
            'enabled' => Expect::bool(true),
            'traces' => Expect::bool(true),
            'metrics' => Expect::bool(true),
        ]);
    }

    private function registerApplicationInstrumentation(): void {
        $config = $this->config->applicationInstrumentation;
        if ($config->name === null) {
            return;
        }

        $registry = new Reference($this->prefix('instrumentation'));
        $builder = $this->getContainerBuilder();
        $builder->addDefinition($this->prefix('tracing'))
            ->setType(Tracing::class)
            ->setFactory([$registry, 'tracing'], [$config->name, $config->version]);
        $builder->addDefinition($this->prefix('metrics'))
            ->setType(Metrics::class)
            ->setFactory([$registry, 'metrics'], [$config->name, $config->version]);
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

    private function registerCacheIntegration(): void {
        $config = $this->config->integrations->cache;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(CacheLifecycleHookInterface::class)
            || !method_exists(Cache::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.cache'))
            ->setType(CacheLifecycleHookInterface::class)
            ->setFactory(CacheLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerRoutingIntegration(): void {
        $config = $this->config->integrations->routing;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(RouteResolutionHookInterface::class)
            || !method_exists(App::class, 'setRouteResolutionHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.routing'))
            ->setType(RouteResolutionHookInterface::class)
            ->setFactory(RouteResolutionHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerSchedulerIntegration(): void {
        $config = $this->config->integrations->scheduler;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(SchedulerLifecycleHookInterface::class)
            || !method_exists(SchedulerJobMessageHandler::class, 'setLifecycleHook')
            || !method_exists(ScheduledCommandMessageHandler::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.scheduler'))
            ->setType(SchedulerLifecycleHookInterface::class)
            ->setFactory(SchedulerLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerAuthIntegration(): void {
        $config = $this->config->integrations->auth;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(AuthLifecycleHookInterface::class)
            || !method_exists(Auth::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.auth'))
            ->setType(AuthLifecycleHookInterface::class)
            ->setFactory(AuthLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerRequestIntegration(): void {
        $config = $this->config->integrations->request;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(RequestMappingLifecycleHookInterface::class)
            || !method_exists(RequestValidationMapper::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.request'))
            ->setType(RequestMappingLifecycleHookInterface::class)
            ->setFactory(RequestMappingLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerInertiaIntegration(): void {
        $config = $this->config->integrations->inertia;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(InertiaLifecycleHookInterface::class)
            || !method_exists(Inertia::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.inertia'))
            ->setType(InertiaLifecycleHookInterface::class)
            ->setFactory(InertiaLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerDatabaseIntegration(): void {
        $config = $this->config->integrations->database;
        if (
            !$this->shouldRegister($config)
            || !interface_exists(DatabaseLifecycleHookInterface::class)
            || !method_exists(Connection::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.database'))
            ->setType(DatabaseLifecycleHookInterface::class)
            ->setFactory(DatabaseLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
            ]);
    }

    private function registerOrmIntegration(): void {
        $config = $this->config->integrations->orm;
        if (
            !$this->shouldRegister($config)
            || (!$config->mutations && !$config->queries && !$config->hydration)
            || !interface_exists(ModelLifecycleHookInterface::class)
            || !method_exists(ModelRepository::class, 'setLifecycleHook')
        ) {
            return;
        }

        $this->getContainerBuilder()
            ->addDefinition($this->prefix('integration.orm'))
            ->setType(ModelLifecycleHookInterface::class)
            ->setFactory(ModelLifecycleHook::class, [
                new Reference($this->prefix('instrumentation')),
                $config->traces,
                $config->metrics,
                $config->mutations,
                $config->queries,
                $config->hydration,
                $config->modelMetrics,
            ]);
    }

    private function wireDatabaseIntegration(): void {
        $config = $this->config->integrations->database;
        foreach ($this->serviceDefinitions(Connection::class) as $definition) {
            $definition->addSetup('setLifecycleHook', [
                new Reference($this->prefix('integration.database')),
                $config->traces && $config->includeRawSql,
            ]);
        }
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
