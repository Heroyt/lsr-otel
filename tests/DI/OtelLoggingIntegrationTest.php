<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Logging\Filter\ContextBlacklistFilter;
use Lsr\Logging\Logger;
use Lsr\Otel\DI\OtelExtension;
use Lsr\Otel\Logging\OtelStorage;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Utils\FileSystem;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OtelLoggingIntegrationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/lsr-otel-tests/logging-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void {
        FileSystem::delete($this->directory);
    }

    public function test_opt_in_appends_once_to_direct_shared_and_factory_managed_loggers(): void {
        $container = $this->compileContainer(['autoWire' => true]);
        $direct = $container->getService('direct');
        $shared = $container->getService('shared');
        $factory = $container->getService('factory');
        self::assertInstanceOf(Logger::class, $direct);
        self::assertInstanceOf(Logger::class, $shared);
        self::assertInstanceOf(Logger::class, $factory);

        $direct->info('Direct message');
        $shared->warning('Shared message');
        $factory->error('Factory message');
        (new Logger($this->directory, 'unmanaged'))->info('Unmanaged message');
        $other = $container->getService('otherPsrLogger');
        self::assertInstanceOf(NullLogger::class, $other);
        $other->info('Other PSR logger');

        $records = $this->records($container);
        self::assertCount(3, $records);
        self::assertSame(['Direct message', 'Shared message', 'Factory message'], array_map(
            static fn (ReadableLogRecord $record): mixed => $record->getBody(),
            $records,
        ));
        self::assertSame(['application', 'application', 'factory'], array_map(
            static fn (ReadableLogRecord $record): mixed => $record->getAttributes()->get('lsr.logger.name'),
            $records,
        ));
        self::assertSame('lsr/logging', $records[0]->getInstrumentationScope()->getName());
        self::assertSame(1, substr_count($this->logFile('application'), 'Direct message'));
        self::assertSame(1, substr_count($this->logFile('application'), 'Shared message'));
        self::assertStringContainsString('Factory message', $this->logFile('factory'));
        self::assertStringContainsString('Unmanaged message', $this->logFile('unmanaged'));
    }

    public function test_default_leaves_loggers_unchanged_but_exposes_explicit_storage(): void {
        $container = $this->compileContainer();
        $logger = $container->getService('direct');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->info('Local only');
        self::assertSame([], $this->records($container));
        self::assertStringContainsString('Local only', $this->logFile('application'));

        $storage = $container->getService('otel.logging.storage');
        self::assertInstanceOf(OtelStorage::class, $storage);
        $storage->store('info', 'Explicit destination');
        $records = $this->records($container);
        self::assertCount(1, $records);
        self::assertSame('Explicit destination', $records[0]->getBody());
    }

    public function test_disabled_otel_prevents_auto_wiring_and_uses_noop_explicit_storage(): void {
        $container = $this->compileContainer(['autoWire' => true], false);
        $logger = $container->getService('direct');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->error('Local while disabled');
        $storage = $container->getService('otel.logging.storage');
        self::assertInstanceOf(OtelStorage::class, $storage);
        $storage->store('error', 'Disabled explicit destination');

        self::assertSame([], $this->records($container));
        self::assertStringContainsString('Local while disabled', $this->logFile('application'));
        self::assertFalse($container->hasService('otel.logging.autoWire'));
    }

    public function test_nested_explicit_destination_wins_over_automatic_destination_and_filter(): void {
        $container = $this->compileContainer(['autoWire' => true, 'filter' => '@blacklist']);
        $logger = $container->getService('explicit');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->info('Below explicit threshold', ['secret' => 'explicit-value']);
        self::assertSame([], $this->records($container));
        $logger->error('Explicit error', ['secret' => 'explicit-value']);

        $records = $this->records($container);
        self::assertCount(1, $records);
        self::assertSame('Explicit error', $records[0]->getBody());
        self::assertSame('explicit', $records[0]->getAttributes()->get('lsr.logger.name'));
        self::assertSame('explicit-value', $records[0]->getAttributes()->get('secret'));
        self::assertStringContainsString('Below explicit threshold', $this->logFile('explicit'));
        self::assertStringContainsString('Explicit error', $this->logFile('explicit'));
    }

    public function test_automatic_threshold_and_filter_do_not_change_existing_file_destination(): void {
        $container = $this->compileContainer([
            'autoWire' => true,
            'level' => 'warning',
            'filter' => '@blacklist',
        ]);
        $logger = $container->getService('direct');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->info('Local detail', ['secret' => 'credential']);
        $logger->warning('Exported warning', ['secret' => 'credential', 'public' => 'retained']);

        $records = $this->records($container);
        self::assertCount(1, $records);
        self::assertSame('Exported warning', $records[0]->getBody());
        self::assertArrayNotHasKey('secret', $records[0]->getAttributes()->toArray());
        self::assertSame('retained', $records[0]->getAttributes()->get('public'));
        self::assertStringContainsString('Local detail', $this->logFile('application'));
        self::assertSame(2, substr_count($this->logFile('application'), '"secret":"credential"'));
    }

    /** @param array{autoWire?: bool, level?: string, filter?: string} $logging */
    private function compileContainer(array $logging = [], bool $enabled = true): Container {
        $loader = new ContainerLoader($this->directory . '/container', true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler) use ($logging, $enabled): ?string {
            $compiler->addExtension('otel', new OtelExtension());
            $compiler->addExtension('testProviders', new class ($enabled) extends CompilerExtension {
                public function __construct(private readonly bool $enabled) {
                }

                public function loadConfiguration(): void {
                    $builder = $this->getContainerBuilder();
                    /** @var ServiceDefinition $tracer */
                    $tracer = $builder->getDefinition('otel.tracerProvider');
                    $tracer->setFactory(NoopTracerProvider::class);
                    /** @var ServiceDefinition $meter */
                    $meter = $builder->getDefinition('otel.meterProvider');
                    $meter->setFactory(NoopMeterProvider::class);
                    /** @var ServiceDefinition $logger */
                    $logger = $builder->getDefinition('otel.loggerProvider');
                    if ($this->enabled) {
                        $logger->setFactory(
                            [LoggingIntegrationFactory::class, 'loggerProvider'],
                            [new Reference('logExporter')],
                        );
                    } else {
                        $logger->setFactory(NoopLoggerProvider::class);
                    }
                }
            });
            $builder = $compiler->getContainerBuilder();
            $builder->addDefinition('logExporter')->setFactory(InMemoryExporter::class);
            $builder->addDefinition('blacklist')->setFactory(ContextBlacklistFilter::class, [['secret']]);
            $builder->addDefinition('direct')
                ->setFactory(Logger::class, [$this->directory, 'application'])
                ->setAutowired(false);
            $builder->addDefinition('shared')
                ->setFactory([LoggingIntegrationFactory::class, 'shared'], [new Reference('direct')])
                ->setAutowired(false);
            $builder->addDefinition('factory')
                ->setFactory([LoggingIntegrationFactory::class, 'logger'], [$this->directory])
                ->setAutowired(false);
            $builder->addDefinition('explicit')
                ->setFactory([LoggingIntegrationFactory::class, 'explicit'], [
                    $this->directory,
                    new Reference('otel.logging.storage'),
                ])
                ->setAutowired(false);
            $builder->addDefinition('otherPsrLogger')->setFactory(NullLogger::class)->setAutowired(false);
            $compiler->addConfig([
                'otel' => [
                    'enabled' => $enabled,
                    'autoShutdown' => false,
                    'registerGlobal' => false,
                    'integrations' => ['logging' => $logging],
                ],
            ]);
            return null;
        });

        return new $containerClass();
    }

    /**
     * @return list<ReadableLogRecord>
     * @phpstan-impure Reads records appended by intervening log calls.
     */
    private function records(Container $container): array {
        $exporter = $container->getService('logExporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);
        /** @var list<ReadableLogRecord> $records */
        $records = $exporter->getStorage()->getArrayCopy();
        return $records;
    }

    private function logFile(string $name): string {
        return FileSystem::read($this->directory . '/' . $name . '-' . date('Y-m-d') . '.log');
    }
}
