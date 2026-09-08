<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use LogicException;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Lifecycle\TelemetryLifecycle;
use Lsr\Otel\Metrics;
use Lsr\Otel\ProviderFactory;
use Lsr\Otel\Tracing;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class InstrumentationRegistryTest extends TestCase
{
    public function test_exports_all_signals_with_one_stable_instrumentation_scope(): void {
        $spanExporter = new InMemorySpanExporter();
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($spanExporter))
            ->build();

        $metricExporter = new InMemoryMetricExporter();
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($metricExporter))
            ->build();

        $logExporter = new InMemoryLogExporter();
        $loggerProvider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($logExporter))
            ->build();

        $registry = new InstrumentationRegistry(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
        );
        $lifecycle = new TelemetryLifecycle(
            $tracerProvider,
            $meterProvider,
            $loggerProvider,
            false,
        );

        $span = $registry->tracer('lsr/core', '0.4.0')
            ->spanBuilder('request')
            ->startSpan();
        $scope = $span->activate();
        try {
            $registry->meter('lsr/core', '0.4.0')
                ->createCounter('http.server.requests')
                ->add(3, ['http.request.method' => 'GET']);
            $registry->logger('lsr/core', '0.4.0')
                ->logRecordBuilder()
                ->setBody('request handled')
                ->emit();
        } finally {
            $scope->detach();
            $span->end();
        }

        self::assertTrue($lifecycle->forceFlush());

        $spans = $spanExporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('request', $spans[0]->getName());
        self::assertSame('lsr/core', $spans[0]->getInstrumentationScope()->getName());
        self::assertSame('0.4.0', $spans[0]->getInstrumentationScope()->getVersion());

        $metrics = $metricExporter->collect();
        self::assertCount(1, $metrics);
        self::assertSame('http.server.requests', $metrics[0]->name);
        self::assertSame('lsr/core', $metrics[0]->instrumentationScope->getName());
        self::assertInstanceOf(Sum::class, $metrics[0]->data);
        $dataPoints = is_array($metrics[0]->data->dataPoints)
            ? $metrics[0]->data->dataPoints
            : iterator_to_array($metrics[0]->data->dataPoints);
        self::assertCount(1, $dataPoints);
        self::assertSame(3, $dataPoints[0]->value);

        $logs = $logExporter->getStorage()->getArrayCopy();
        self::assertCount(1, $logs);
        self::assertSame('request handled', $logs[0]->getBody());
        self::assertSame('lsr/core', $logs[0]->getInstrumentationScope()->getName());
        self::assertSame($spans[0]->getTraceId(), $logs[0]->getSpanContext()?->getTraceId());
        self::assertSame($spans[0]->getSpanId(), $logs[0]->getSpanContext()?->getSpanId());
    }

    public function test_accepts_composer_names_and_rejects_invalid_names(): void {
        $factory = new ProviderFactory(false);
        $resource = $factory->createResource();
        $meterProvider = $factory->createMeterProvider($resource);
        $registry = new InstrumentationRegistry(
            $factory->createTracerProvider($meterProvider),
            $meterProvider,
            $factory->createLoggerProvider($meterProvider, $resource),
        );

        self::assertInstanceOf(Tracing::class, $registry->tracing('vendor/foo--bar'));
        $metrics = $registry->metrics('vendor/foo--bar');
        self::assertInstanceOf(Metrics::class, $metrics);
        self::assertSame($metrics, $registry->metrics('vendor/foo--bar'));
        self::assertNotSame($metrics, $registry->metrics('vendor/foo--bar', '1.0.0'));
        $metrics->counter('operations');
        try {
            $registry->metrics('vendor/foo--bar')->histogram('operations');
            self::fail('Separate metrics modules were created for one instrumentation scope.');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->tracer('application');
    }
}
