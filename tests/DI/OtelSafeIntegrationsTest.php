<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Caching\Cache;
use Lsr\Core\App;
use Lsr\Core\Auth\Services\Auth;
use Lsr\Core\Requests\Validation\RequestValidationMapper;
use Lsr\Db\Connection;
use Lsr\Inertia\Services\Inertia;
use Lsr\Orm\Lifecycle\ModelLifecycleEvent;
use Lsr\Orm\ModelRepository;
use Lsr\Otel\DI\OtelExtension;
use Lsr\Scheduler\Internal\ScheduledCommandMessageHandler;
use Lsr\Scheduler\Internal\SchedulerJobMessageHandler;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OtelSafeIntegrationsTest extends TestCase
{
    /** @var array<class-string, string> */
    private const array REQUIRED_HOOKS = [
        Cache::class => 'setLifecycleHook',
        App::class => 'setRouteResolutionHook',
        SchedulerJobMessageHandler::class => 'setLifecycleHook',
        ScheduledCommandMessageHandler::class => 'setLifecycleHook',
        Auth::class => 'setLifecycleHook',
        RequestValidationMapper::class => 'setLifecycleHook',
        Inertia::class => 'setLifecycleHook',
        Connection::class => 'setLifecycleHook',
        ModelRepository::class => 'setLifecycleHook',
    ];

    private string $directory;

    protected function setUp(): void {
        foreach (self::REQUIRED_HOOKS as $class => $method) {
            if ( ! method_exists($class, $method)) {
                self::markTestSkipped('Local safe integration packages are not available.');
            }
        }

        $this->directory = sys_get_temp_dir() . '/lsr-otel-tests/safe-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
        ModelRepository::setLifecycleHook(null);
    }

    protected function tearDown(): void {
        if (isset($this->directory)) {
            ModelRepository::setLifecycleHook(null);
            FileSystem::delete($this->directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function disabledIntegrationProvider(): iterable {
        yield 'cache' => ['cache'];
        yield 'routing' => ['routing'];
        yield 'scheduler' => ['scheduler'];
        yield 'auth' => ['auth'];
        yield 'request' => ['request'];
        yield 'inertia' => ['inertia'];
        yield 'database' => ['database'];
        yield 'orm' => ['orm'];
    }

    #[DataProvider('disabledIntegrationProvider')]
    public function test_integrations_are_enabled_by_default_and_independently_disabled(string $disabled): void {
        $container = $this->compileContainer($disabled);

        self::assertSame(
            $disabled !== 'cache',
            $this->property($container->getService('cache'), 'lifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'routing',
            $this->property($container->getService('app'), 'routeResolutionHook') !== null,
        );
        self::assertSame(
            $disabled !== 'scheduler',
            $this->property($container->getService('schedulerJobHandler'), 'lifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'scheduler',
            $this->property($container->getService('scheduledCommandHandler'), 'lifecycleHook') !== null,
        );
        $auth = $container->getService('auth');
        self::assertSame(
            $disabled !== 'auth',
            $this->weakMapLifecycleHook($auth) !== null,
        );
        self::assertSame(
            $disabled !== 'request',
            $this->property($container->getService('requestMapper'), 'lifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'inertia',
            $this->property($container->getService('inertia'), 'lifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'database',
            $this->weakMapLifecycleHook($container->getService('database')) !== null,
        );
        self::assertSame(
            $disabled !== 'orm',
            $this->staticProperty(ModelRepository::class, 'lifecycleHook') !== null,
        );
    }

    public function test_raw_sql_capture_is_disabled_by_default_and_requires_traces(): void {
        $defaultDatabase = $this->compileContainer('cache')->getService('database');
        self::assertFalse($this->databaseIncludesRawSql($defaultDatabase));

        $enabledDatabase = $this->compileContainer('routing', ['includeRawSql' => true])->getService('database');
        self::assertTrue($this->databaseIncludesRawSql($enabledDatabase));

        $metricsOnlyDatabase = $this->compileContainer('auth', [
            'traces' => false,
            'includeRawSql' => true,
        ])->getService('database');
        self::assertFalse($this->databaseIncludesRawSql($metricsOnlyDatabase));
    }

    public function test_orm_granularity_is_configured_independently(): void {
        $this->compileContainer('cache', ormConfig: [
            'mutations' => false,
            'queries' => true,
            'hydration' => false,
            'modelMetrics' => true,
        ]);
        $hook = $this->staticProperty(ModelRepository::class, 'lifecycleHook');

        self::assertNotNull($hook);
        self::assertFalse($hook->captures(ModelLifecycleEvent::MUTATION));
        self::assertTrue($hook->captures(ModelLifecycleEvent::QUERY));
        self::assertFalse($hook->captures(ModelLifecycleEvent::HYDRATION));
        self::assertTrue($this->property($hook, 'modelMetrics'));
    }

    /**
     * @param array<string, bool> $databaseConfig
     * @param array<string, bool> $ormConfig
     */
    private function compileContainer(
        string $disabled,
        array $databaseConfig = [],
        array $ormConfig = [],
    ): Container {
        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(
            function (Compiler $compiler) use ($disabled, $databaseConfig, $ormConfig): ?string {
                $compiler->addExtension('otel', new OtelExtension());
                $builder = $compiler->getContainerBuilder();
                $builder->addDefinition('cache')
                    ->setType(Cache::class)
                    ->setFactory([SafeIntegrationFactory::class, 'cache']);
                $builder->addDefinition('app')
                    ->setType(App::class)
                    ->setFactory([SafeIntegrationFactory::class, 'app']);
                $builder->addDefinition('schedulerJobHandler')
                    ->setType(SchedulerJobMessageHandler::class)
                    ->setFactory([SafeIntegrationFactory::class, 'schedulerJobHandler']);
                $builder->addDefinition('scheduledCommandHandler')
                    ->setType(ScheduledCommandMessageHandler::class)
                    ->setFactory([SafeIntegrationFactory::class, 'scheduledCommandHandler']);
                $builder->addDefinition('auth')
                    ->setType(Auth::class)
                    ->setFactory([SafeIntegrationFactory::class, 'auth']);
                $builder->addDefinition('requestMapper')
                    ->setType(RequestValidationMapper::class)
                    ->setFactory([SafeIntegrationFactory::class, 'requestMapper']);
                $builder->addDefinition('inertia')
                    ->setType(Inertia::class)
                    ->setFactory([SafeIntegrationFactory::class, 'inertia']);
                $builder->addDefinition('database')
                    ->setType(Connection::class)
                    ->setFactory([SafeIntegrationFactory::class, 'database']);
                $compiler->addConfig([
                    'otel' => [
                        'autoShutdown' => false,
                        'integrations' => array_replace_recursive(
                            [$disabled => ['enabled' => false]],
                            $databaseConfig === [] ? [] : ['database' => $databaseConfig],
                            $ormConfig === [] ? [] : ['orm' => $ormConfig],
                        ),
                    ],
                ]);
                return null;
            },
            $disabled . '-' . hash('xxh3', serialize([$databaseConfig, $ormConfig])),
        );

        $container = new $containerClass();
        $container->initialize();
        return $container;
    }

    private function weakMapLifecycleHook(object $service): mixed {
        $hooks = $this->property($service, 'lifecycleHooks');
        return $hooks[$service] ?? null;
    }

    private function databaseIncludesRawSql(object $database): bool {
        $settings = $this->property($database, 'lifecycleRawSql');
        return $settings[$database] ?? false;
    }

    /**
     * @param class-string $class
     */
    private function staticProperty(string $class, string $name): mixed {
        return (new ReflectionClass($class))->getStaticPropertyValue($name);
    }

    private function property(object $object, string $name): mixed {
        $reflection = new ReflectionClass($object);
        while ( ! $reflection->hasProperty($name)) {
            $reflection = $reflection->getParentClass();
            if ($reflection === false) {
                self::fail('Property ' . $name . ' was not found on ' . $object::class . '.');
            }
        }
        $property = $reflection->getProperty($name);
        return $property->isInitialized($object) ? $property->getValue($object) : null;
    }
}
