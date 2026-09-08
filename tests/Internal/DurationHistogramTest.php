<?php

declare(strict_types=1);

namespace Tests\Internal;

use Lsr\Otel\Internal\DurationHistogram;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\TestCase;

final class DurationHistogramTest extends TestCase
{
    public function test_uses_second_based_latency_boundaries(): void {
        $exporter = new InMemoryExporter();
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($exporter))
            ->build();

        $histogram = DurationHistogram::create(
            $meterProvider->getMeter('lsr/test'),
            'lsr.test.duration',
            'Test duration.',
        );
        $histogram?->record(0.004);
        self::assertTrue($meterProvider->forceFlush());

        $metrics = $exporter->collect();
        self::assertCount(1, $metrics);
        self::assertInstanceOf(Histogram::class, $metrics[0]->data);
        $dataPoints = is_array($metrics[0]->data->dataPoints)
            ? $metrics[0]->data->dataPoints
            : iterator_to_array($metrics[0]->data->dataPoints);
        self::assertCount(1, $dataPoints);
        self::assertSame(
            [
                0.001,
                0.0025,
                0.005,
                0.01,
                0.025,
                0.05,
                0.075,
                0.1,
                0.25,
                0.5,
                0.75,
                1,
                2.5,
                5,
                7.5,
                10,
                30,
                60,
                120,
                300,
            ],
            $dataPoints[0]->explicitBounds,
        );
        self::assertSame(0.004, $dataPoints[0]->sum);
    }
}
