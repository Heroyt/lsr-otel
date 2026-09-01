<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Otel\DI\OtelExtension;
use Lsr\Otel\GlobalSdkRegistration;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Lsr\Otel\Metrics;
use Lsr\Otel\Tracing;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\Utils\FileSystem;
use OpenTelemetry\API\Logs\LoggerProviderInterface as ApiLoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface as ApiMeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface as ApiTracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use PHPUnit\Framework\TestCase;

final class OtelExtensionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/lsr-otel-tests/di-' . bin2hex(random_bytes(6));
        FileSystem::createDir($this->directory);
    }

    protected function tearDown(): void {
        FileSystem::delete($this->directory);
    }

    public function testRegistersOfficialProviderInterfacesAndLifecycle(): void {
        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler): ?string {
            $compiler->addExtension('otel', new OtelExtension());
            $compiler->addConfig([
                'otel' => [
                    'enabled' => false,
                    'autoShutdown' => false,
                ],
            ]);

            return null;
        });
        $container = new $containerClass();

        $tracerProvider = $container->getByType(ApiTracerProviderInterface::class);
        $meterProvider = $container->getByType(ApiMeterProviderInterface::class);
        $loggerProvider = $container->getByType(ApiLoggerProviderInterface::class);
        $propagator = $container->getByType(TextMapPropagatorInterface::class);
        $sdk = $container->getByType(Sdk::class);

        self::assertInstanceOf(NoopTracerProvider::class, $tracerProvider);
        self::assertInstanceOf(NoopMeterProvider::class, $meterProvider);
        self::assertInstanceOf(NoopLoggerProvider::class, $loggerProvider);
        self::assertSame($tracerProvider, $sdk->getTracerProvider());
        self::assertSame($meterProvider, $sdk->getMeterProvider());
        self::assertSame($loggerProvider, $sdk->getLoggerProvider());
        self::assertSame($propagator, $sdk->getPropagator());
        self::assertSame(
            $container->getByType(TelemetryLifecycleInterface::class),
            $container->getService('otel.lifecycle'),
        );
        self::assertSame(
            $container->getByType(GlobalSdkRegistration::class),
            $container->getService('otel.globalSdkRegistration'),
        );
        self::assertSame(
            $container->getByType(InstrumentationRegistry::class),
            $container->getService('otel.instrumentation'),
        );
    }

    public function testRegistersConfiguredApplicationInstrumentationWhenTelemetryIsDisabled(): void {
        $loader = new ContainerLoader($this->directory, true);
        /** @var class-string<Container> $containerClass */
        $containerClass = $loader->load(function (Compiler $compiler): ?string {
            $compiler->addExtension('otel', new OtelExtension());
            $compiler->addConfig([
                'otel' => [
                    'enabled' => false,
                    'autoShutdown' => false,
                    'applicationInstrumentation' => [
                        'name' => 'heroyt/laser-arena-control',
                        'version' => '0.5.1',
                    ],
                ],
            ]);

            return null;
        });
        $container = new $containerClass();

        $tracing = $container->getByType(Tracing::class);
        self::assertSame('completed', $tracing->trace('operation', static fn(): string => 'completed'));
        self::assertSame($tracing, $container->getService('otel.tracing'));
        $metrics = $container->getByType(Metrics::class);
        $metrics->counter('operations')->add();
        self::assertSame($metrics, $container->getService('otel.metrics'));
    }
}
