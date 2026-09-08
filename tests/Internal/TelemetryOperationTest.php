<?php

declare(strict_types=1);

namespace Tests\Internal;

use Lsr\Otel\Internal\TelemetryOperation;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelemetryOperationTest extends TestCase
{
    public function test_failure_completes_span_and_metrics_exactly_once(): void {
        $spanExporter = new InMemorySpanExporter();
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($spanExporter))
            ->build();
        $span = $tracerProvider->getTracer('lsr/test')->spanBuilder('initial')->startSpan();
        $scope = $span->activate();

        $metricExporter = new InMemoryMetricExporter();
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($metricExporter))
            ->build();
        $meter = $meterProvider->getMeter('lsr/test');
        $operation = new TelemetryOperation(
            $span,
            $scope,
            $meter->createHistogram('lsr.test.duration', 's'),
            $meter->createCounter('lsr.test.operations', '{operation}'),
            ['lsr.test.name' => 'contract'],
            hrtime(true),
        );

        $operation->recordException(new RuntimeException('failed'));
        $operation->complete(['lsr.operation.outcome' => 'failure'], 'renamed');
        $operation->complete(['lsr.operation.outcome' => 'duplicate'], 'duplicate');
        self::assertTrue($tracerProvider->forceFlush());
        self::assertTrue($meterProvider->forceFlush());

        $spans = $spanExporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('renamed', $spans[0]->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        self::assertSame('failure', $spans[0]->getAttributes()->get('lsr.operation.outcome'));
        self::assertCount(1, $spans[0]->getEvents());

        $metrics = $metricExporter->collect();
        self::assertCount(2, $metrics);
        $byName = [];
        foreach ($metrics as $metric) {
            $byName[$metric->name] = $metric;
        }
        self::assertArrayHasKey('lsr.test.duration', $byName);
        self::assertArrayHasKey('lsr.test.operations', $byName);

        foreach ($byName as $metric) {
            self::assertTrue($metric->data instanceof Histogram || $metric->data instanceof Sum);
            $dataPoints = is_array($metric->data->dataPoints)
                ? $metric->data->dataPoints
                : iterator_to_array($metric->data->dataPoints);
            self::assertCount(1, $dataPoints);
            self::assertSame('failure', $dataPoints[0]->attributes->get('lsr.operation.outcome'));
        }
    }
}
