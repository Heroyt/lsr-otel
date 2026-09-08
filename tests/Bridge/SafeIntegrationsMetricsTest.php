<?php

declare(strict_types=1);

namespace Tests\Bridge;

use Lsr\Caching\Lifecycle\CacheLifecycleHookInterface;
use Lsr\Core\Auth\Lifecycle\AuthLifecycleEvent;
use Lsr\Core\Http\Lifecycle\RouteResolutionEvent;
use Lsr\Core\Requests\Lifecycle\RequestMappingEvent;
use Lsr\Db\Lifecycle\DatabaseLifecycleEvent;
use Lsr\Enums\RequestMethod;
use Lsr\Inertia\Lifecycle\InertiaLifecycleHookInterface;
use Lsr\Otel\Bridge\Auth\AuthLifecycleHook;
use Lsr\Otel\Bridge\Cache\CacheLifecycleHook;
use Lsr\Otel\Bridge\Core\RouteResolutionHook;
use Lsr\Otel\Bridge\Database\DatabaseLifecycleHook;
use Lsr\Otel\Bridge\Inertia\InertiaLifecycleHook;
use Lsr\Otel\Bridge\Request\RequestMappingLifecycleHook;
use Lsr\Otel\Bridge\Scheduler\SchedulerLifecycleHook;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Scheduler\Lifecycle\SchedulerLifecycleHookInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class SafeIntegrationsMetricsTest extends TestCase
{
    public static function setUpBeforeClass(): void {
        $packagesRoot = dirname(__DIR__, 3);
        $packages = ['lsr-cache', 'lsr-core', 'lsr-auth', 'lsr-request', 'lsr-inertia', 'lsr-scheduler', 'lsr-db'];
        foreach ($packages as $package) {
            $autoload = $packagesRoot . '/' . $package . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
    }

    protected function setUp(): void {
        if (
            ! interface_exists(CacheLifecycleHookInterface::class)
            || ! interface_exists(SchedulerLifecycleHookInterface::class)
            || ! interface_exists(InertiaLifecycleHookInterface::class)
            || ! class_exists(AuthLifecycleEvent::class)
            || ! class_exists(RouteResolutionEvent::class)
            || ! class_exists(RequestMappingEvent::class)
            || ! class_exists(DatabaseLifecycleEvent::class)
        ) {
            self::markTestSkipped('Optional framework packages are not installed.');
        }
    }

    public function test_safe_adapters_export_only_bounded_metric_dimensions(): void {
        $spanExporter = new InMemorySpanExporter();
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($spanExporter))
            ->build();
        $metricExporter = new InMemoryMetricExporter();
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($metricExporter))
            ->build();
        $instrumentation = new InstrumentationRegistry(
            $tracerProvider,
            $meterProvider,
            new NoopLoggerProvider(),
        );

        (new CacheLifecycleHook($instrumentation))->begin('load', 1)->complete(1, 0, 0);
        (new RouteResolutionHook($instrumentation))->record(
            new RouteResolutionEvent(RequestMethod::GET, null, 0.001),
        );
        (new SchedulerLifecycleHook($instrumentation))->begin('job', 'PrivateJobName')->complete();
        (new AuthLifecycleHook($instrumentation))->record(
            new AuthLifecycleEvent(AuthLifecycleEvent::LOGIN, AuthLifecycleEvent::SUCCESS, 0.001),
        );
        (new RequestMappingLifecycleHook($instrumentation))->record(
            new RequestMappingEvent(
                RequestMappingEvent::BODY,
                'PrivateRequestClass',
                RequestMappingEvent::SUCCESS,
                0.001,
            ),
        );
        (new InertiaLifecycleHook($instrumentation))->begin('Private/Component', 2, true)->complete(1, 1, 0);
        (new DatabaseLifecycleHook($instrumentation))->record(
            new DatabaseLifecycleEvent(
                DatabaseLifecycleEvent::SELECT,
                DatabaseLifecycleEvent::SUCCESS,
                0.001,
                'sqlite',
                'PrivateConnectionName',
                1,
            ),
        );
        (new DatabaseLifecycleHook($instrumentation))->record(
            new DatabaseLifecycleEvent(
                DatabaseLifecycleEvent::QUERY,
                DatabaseLifecycleEvent::SUCCESS,
                0.002,
                'sqlite',
                sql: "SELECT * FROM private_table WHERE secret = 'private-value'",
            ),
        );

        self::assertTrue($tracerProvider->forceFlush());
        self::assertTrue($meterProvider->forceFlush());
        self::assertCount(5, $spanExporter->getSpans());
        $databaseSpans = array_values(array_filter(
            $spanExporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'db select',
        ));
        self::assertCount(1, $databaseSpans);
        self::assertSame(
            'PrivateConnectionName',
            $databaseSpans[0]->getAttributes()->get('lsr.db.connection.name'),
        );
        self::assertNull($databaseSpans[0]->getAttributes()->get('db.query.text'));
        self::assertNull($databaseSpans[0]->getAttributes()->get('db.statement'));
        $rawSqlSpans = array_values(array_filter(
            $spanExporter->getSpans(),
            static fn ($span): bool => $span->getName() === 'db query',
        ));
        self::assertCount(1, $rawSqlSpans);
        self::assertSame(
            "SELECT * FROM private_table WHERE secret = 'private-value'",
            $rawSqlSpans[0]->getAttributes()->get('db.query.text'),
        );
        self::assertNull($rawSqlSpans[0]->getAttributes()->get('db.statement'));

        $names = [];
        foreach ($metricExporter->collect() as $metric) {
            $names[] = $metric->name;
            $dataPoints = is_array($metric->data->dataPoints)
                ? $metric->data->dataPoints
                : iterator_to_array($metric->data->dataPoints);
            foreach ($dataPoints as $dataPoint) {
                self::assertNull($dataPoint->attributes->get('db.query.text'));
            }
        }
        sort($names);
        self::assertSame([
            'lsr.auth.operation.duration',
            'lsr.auth.operations',
            'lsr.cache.items',
            'lsr.cache.operation.duration',
            'lsr.cache.operations',
            'lsr.db.operation.duration',
            'lsr.db.operations',
            'lsr.inertia.render.duration',
            'lsr.inertia.renders',
            'lsr.request.mapping.duration',
            'lsr.request.mappings',
            'lsr.routing.match.duration',
            'lsr.routing.matches',
            'lsr.scheduler.execution.duration',
            'lsr.scheduler.executions',
        ], $names);
    }
}
