<?php

declare(strict_types=1);

namespace Tests\Bridge;

use Lsr\Db\Lifecycle\DatabaseLifecycleEvent;
use Lsr\Orm\Lifecycle\ModelLifecycleEvent;
use Lsr\Orm\Model;
use Lsr\Otel\Bridge\Database\DatabaseLifecycleHook;
use Lsr\Otel\Bridge\Orm\ModelLifecycleHook;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class OrmLifecycleHookTest extends TestCase
{
    public static function setUpBeforeClass(): void {
        $packagesRoot = dirname(__DIR__, 3);
        foreach (['lsr-db', 'lsr-orm'] as $package) {
            $autoload = $packagesRoot . '/' . $package . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
    }

    protected function setUp(): void {
        if ( ! class_exists(ModelLifecycleEvent::class)) {
            self::markTestSkipped('The optional lsr/orm package is not installed.');
        }
    }

    public function test_granularity_categories_are_independent(): void {
        [$instrumentation] = $this->telemetry();
        $hook = new ModelLifecycleHook(
            $instrumentation,
            traces: false,
            metrics: false,
            mutations: false,
            queries: true,
            hydration: false,
        );

        self::assertFalse($hook->captures(ModelLifecycleEvent::MUTATION));
        self::assertTrue($hook->captures(ModelLifecycleEvent::QUERY));
        self::assertFalse($hook->captures(ModelLifecycleEvent::HYDRATION));
        self::assertFalse($hook->captures('unknown'));
    }

    public function test_model_class_is_trace_only_by_default(): void {
        [$instrumentation, $tracerProvider, $meterProvider, $spanExporter, $metricExporter] = $this->telemetry();
        $hook = new ModelLifecycleHook($instrumentation);
        $scope = $hook->begin(
            ModelLifecycleEvent::QUERY,
            ModelLifecycleEvent::GET,
            Model::class,
        );
        $scope->complete(ModelLifecycleEvent::SUCCESS, 2);

        self::assertTrue($tracerProvider->forceFlush());
        self::assertTrue($meterProvider->forceFlush());
        self::assertCount(1, $spanExporter->getSpans());
        $span = $spanExporter->getSpans()[0];
        self::assertSame('orm get', $span->getName());
        self::assertSame(Model::class, $span->getAttributes()->get('lsr.orm.model.class'));
        self::assertSame(2, $span->getAttributes()->get('lsr.orm.result.count'));

        $names = [];
        foreach ($metricExporter->collect() as $metric) {
            $names[] = $metric->name;
            foreach ($metric->data->dataPoints as $dataPoint) {
                self::assertNull($dataPoint->attributes->get('lsr.orm.model.class'));
                self::assertSame(ModelLifecycleEvent::QUERY, $dataPoint->attributes->get('lsr.orm.category'));
            }
        }
        sort($names);
        self::assertSame(['lsr.orm.operation.duration', 'lsr.orm.operations'], $names);
    }

    public function test_model_metric_dimension_requires_opt_in(): void {
        [$instrumentation, $tracerProvider, $meterProvider, $spanExporter, $metricExporter] = $this->telemetry();
        $hook = new ModelLifecycleHook(
            $instrumentation,
            traces: false,
            metrics: true,
            hydration: true,
            modelMetrics: true,
        );
        $scope = $hook->begin(
            ModelLifecycleEvent::HYDRATION,
            ModelLifecycleEvent::HYDRATE,
            Model::class,
        );
        $scope->complete(ModelLifecycleEvent::SUCCESS, 1);

        self::assertTrue($tracerProvider->forceFlush());
        self::assertTrue($meterProvider->forceFlush());
        self::assertCount(0, $spanExporter->getSpans());
        foreach ($metricExporter->collect() as $metric) {
            foreach ($metric->data->dataPoints as $dataPoint) {
                self::assertSame(Model::class, $dataPoint->attributes->get('lsr.orm.model.class'));
            }
        }
    }

    public function test_database_span_is_nested_under_orm_scope(): void {
        [$instrumentation, $tracerProvider, $meterProvider, $spanExporter] = $this->telemetry();
        $scope = (new ModelLifecycleHook($instrumentation))->begin(
            ModelLifecycleEvent::QUERY,
            ModelLifecycleEvent::GET,
            Model::class,
        );
        (new DatabaseLifecycleHook($instrumentation))->record(
            new DatabaseLifecycleEvent(
                DatabaseLifecycleEvent::SELECT,
                DatabaseLifecycleEvent::SUCCESS,
                0.001,
                'sqlite',
            ),
        );
        $scope->complete(ModelLifecycleEvent::SUCCESS, 1);

        self::assertTrue($tracerProvider->forceFlush());
        self::assertTrue($meterProvider->forceFlush());
        $spans = [];
        foreach ($spanExporter->getSpans() as $span) {
            $spans[$span->getName()] = $span;
        }
        self::assertArrayHasKey('orm get', $spans);
        self::assertArrayHasKey('db select', $spans);
        self::assertSame(
            $spans['orm get']->getContext()->getSpanId(),
            $spans['db select']->getParentContext()->getSpanId(),
        );
    }

    /**
     * @return array{
     *     InstrumentationRegistry,
     *     TracerProvider,
     *     MeterProvider,
     *     InMemorySpanExporter,
     *     InMemoryMetricExporter
     * }
     */
    private function telemetry(): array {
        $spanExporter = new InMemorySpanExporter();
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($spanExporter))
            ->build();
        $metricExporter = new InMemoryMetricExporter();
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($metricExporter))
            ->build();

        return [
            new InstrumentationRegistry($tracerProvider, $meterProvider, new NoopLoggerProvider()),
            $tracerProvider,
            $meterProvider,
            $spanExporter,
            $metricExporter,
        ];
    }
}
