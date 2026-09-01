<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Core\FpmHandler;
use Lsr\CQRS\CommandBus;
use Lsr\Otel\DI\OtelExtension;
use Lsr\Roadrunner\Tasks\TaskProducer;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Application;

final class OtelOptionalIntegrationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        if (
            !class_exists(FpmHandler::class)
            || !class_exists(HttpWorker::class)
            || !class_exists(CommandBus::class)
            || !class_exists(Application::class)
        ) {
            self::markTestSkipped('Optional framework packages are not installed.');
        }

        $this->directory = sys_get_temp_dir() . '/lsr-otel-tests/integrations-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void {
        if (isset($this->directory)) {
            FileSystem::delete($this->directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function disabledIntegrationProvider(): iterable {
        yield 'core' => ['core'];
        yield 'roadrunner' => ['roadrunner'];
        yield 'console' => ['console'];
        yield 'cqrs' => ['cqrs'];
    }

    #[DataProvider('disabledIntegrationProvider')]
    public function testIntegrationsAreEnabledByDefaultAndIndependentlyDisabled(string $disabled): void {
        $container = $this->compileContainer($disabled);
        self::assertSame(
            $disabled !== 'core',
            $container->hasService('otel.integration.core.http'),
        );

        $fpm = $container->getService('fpm');
        self::assertSame(
            $disabled !== 'core',
            $this->property($fpm, 'requestLifecycle') !== null,
        );
        self::assertSame(
            $disabled !== 'core',
            $this->property($fpm, 'asyncHandlers') !== [],
        );

        $httpWorker = $container->getService('httpWorker');
        $jobsWorker = $container->getService('jobsWorker');
        $producer = $container->getService('taskProducer');
        self::assertSame(
            $disabled !== 'roadrunner',
            $this->property($httpWorker, 'requestLifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'roadrunner',
            $this->property($httpWorker, 'workerLifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'roadrunner',
            $this->property($jobsWorker, 'taskLifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'roadrunner',
            $this->property($jobsWorker, 'workerLifecycleHook') !== null,
        );
        self::assertSame(
            $disabled !== 'roadrunner',
            $this->property($producer, 'lifecycleHook') !== null,
        );

        self::assertSame(
            $disabled !== 'console',
            $this->property($container->getService('application'), 'dispatcher') !== null,
        );
        self::assertSame(
            $disabled !== 'cqrs',
            $this->property($container->getService('commandBus'), 'lifecycleHook') !== null,
        );
    }

    private function compileContainer(string $disabled): Container {
        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler) use ($disabled): ?string {
            $compiler->addExtension('otel', new OtelExtension());
            $builder = $compiler->getContainerBuilder();
            $builder->addDefinition('fpm')
                ->setType(FpmHandler::class)
                ->setFactory([OptionalIntegrationFactory::class, 'fpm']);
            $builder->addDefinition('httpWorker')
                ->setType(HttpWorker::class)
                ->setFactory([OptionalIntegrationFactory::class, 'httpWorker']);
            $builder->addDefinition('jobsWorker')
                ->setType(JobsWorker::class)
                ->setFactory([OptionalIntegrationFactory::class, 'jobsWorker']);
            $builder->addDefinition('taskProducer')
                ->setType(TaskProducer::class)
                ->setFactory([OptionalIntegrationFactory::class, 'taskProducer']);
            $builder->addDefinition('commandBus')
                ->setType(CommandBus::class)
                ->setFactory([OptionalIntegrationFactory::class, 'commandBus']);
            $builder->addDefinition('application')
                ->setType(Application::class)
                ->setFactory([OptionalIntegrationFactory::class, 'application']);
            $compiler->addConfig([
                'otel' => [
                    'autoShutdown' => false,
                    'integrations' => [
                        $disabled => ['enabled' => false],
                    ],
                ],
            ]);

            return null;
        }, $disabled);

        return new $containerClass();
    }

    private function property(object $object, string $name): mixed {
        $reflection = new ReflectionClass($object);
        while (!$reflection->hasProperty($name)) {
            $reflection = $reflection->getParentClass();
            if ($reflection === false) {
                self::fail('Property ' . $name . ' was not found on ' . $object::class . '.');
            }
        }

        return $reflection->getProperty($name)->getValue($object);
    }
}
