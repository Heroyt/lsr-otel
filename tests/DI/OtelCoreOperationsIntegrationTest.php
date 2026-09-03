<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Core\App;
use Lsr\Core\FpmHandler;
use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RequestOperationLifecycleHookInterface;
use Lsr\Core\RouteHandler;
use Lsr\Otel\DI\OtelExtension;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\WorkerLifecycleHookInterface;
use Lsr\Roadrunner\Workers\HttpWorker;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class OtelCoreOperationsIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        if (
            !class_exists(App::class)
            || !class_exists(RouteHandler::class)
            || !interface_exists(RequestOperationLifecycleHookInterface::class)
        ) {
            self::markTestSkipped('The compatible lsr/core package is not installed.');
        }

        $this->directory = sys_get_temp_dir() . '/lsr-otel-tests/core-operations-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void {
        if (isset($this->directory)) {
            FileSystem::delete($this->directory);
        }
    }

    public function testExtensionWiresOneOperationHookIntoAppAndRouteHandler(): void {
        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler): ?string {
            $compiler->addExtension('otel', new OtelExtension());
            $builder = $compiler->getContainerBuilder();
            $builder->addDefinition('app')
                ->setType(App::class)
                ->setFactory([CoreOperationFactory::class, 'app']);
            $builder->addDefinition('routeHandler')
                ->setType(RouteHandler::class)
                ->setFactory([CoreOperationFactory::class, 'routeHandler']);
            $compiler->addConfig([
                'otel' => [
                    'autoShutdown' => false,
                    'integrations' => [
                        'roadrunner' => ['enabled' => false],
                    ],
                ],
            ]);

            return null;
        });
        $container = new $containerClass();

        $hook = $container->getService('otel.integration.core.operations');
        self::assertInstanceOf(RequestOperationLifecycleHookInterface::class, $hook);
        self::assertSame(
            $hook,
            (new ReflectionProperty(App::class, 'requestOperationLifecycleHook'))
                ->getValue($container->getService('app')),
        );
        self::assertSame(
            $hook,
            (new ReflectionProperty(RouteHandler::class, 'requestOperationLifecycleHook'))
                ->getValue($container->getService('routeHandler')),
        );
    }
    public function testCoreAndRoadRunnerHttpHooksAreNotAutowiringCandidates(): void {
        if (
            !interface_exists(TaskLifecycleHookInterface::class)
            || !interface_exists(TaskDispatchLifecycleHookInterface::class)
            || !interface_exists(WorkerLifecycleHookInterface::class)
        ) {
            self::markTestSkipped('The compatible lsr/roadrunner package is not installed.');
        }

        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler): ?string {
            $compiler->addExtension('otel', new OtelExtension());
            $builder = $compiler->getContainerBuilder();
            $builder->addDefinition('fpmHandler')
                ->setType(FpmHandler::class)
                ->setFactory([CoreOperationFactory::class, 'fpmHandler']);
            $builder->addDefinition('httpWorker')
                ->setType(HttpWorker::class)
                ->setFactory([CoreOperationFactory::class, 'httpWorker']);
            $builder->addDefinition('requestLifecycleConsumer')
                ->setFactory(RequestLifecycleConsumer::class);
            $compiler->addConfig([
                'otel' => [
                    'autoShutdown' => false,
                    'integrations' => [
                        'console' => ['enabled' => false],
                        'cqrs' => ['enabled' => false],
                        'cache' => ['enabled' => false],
                        'routing' => ['enabled' => false],
                        'scheduler' => ['enabled' => false],
                        'auth' => ['enabled' => false],
                        'request' => ['enabled' => false],
                        'inertia' => ['enabled' => false],
                        'database' => ['enabled' => false],
                        'orm' => ['enabled' => false],
                    ],
                ],
            ]);

            return null;
        });
        $container = new $containerClass();

        $coreHook = $container->getService('otel.integration.core.http');
        $roadrunnerHook = $container->getService('otel.integration.roadrunner.http');
        self::assertSame(
            $coreHook,
            (new ReflectionProperty(FpmHandler::class, 'requestLifecycle'))
                ->getValue($container->getService('fpmHandler')),
        );
        self::assertSame(
            $roadrunnerHook,
            (new ReflectionProperty(HttpWorker::class, 'requestLifecycleHook'))
                ->getValue($container->getService('httpWorker')),
        );
        self::assertNull($container->getService('requestLifecycleConsumer')->hook);
        self::assertNull($container->getByType(RequestLifecycleHookInterface::class, false));
    }
}
