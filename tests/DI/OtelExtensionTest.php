<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Otel\DI\OtelExtension;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
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
        self::assertInstanceOf(
            TelemetryLifecycleInterface::class,
            $container->getByType(TelemetryLifecycleInterface::class),
        );
        self::assertInstanceOf(
            InstrumentationRegistry::class,
            $container->getByType(InstrumentationRegistry::class),
        );
    }
}
