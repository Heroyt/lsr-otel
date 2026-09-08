<?php

declare(strict_types=1);

namespace Tests;

use LogicException;
use Lsr\Otel\CounterMetric;
use Lsr\Otel\HistogramMetric;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Metrics;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\NoopTracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MetricsTest extends TestCase
{
    public function test_creates_stable_instruments_and_exports_measurements(): void {
        $exporter = new InMemoryExporter();
        $meterProvider = MeterProvider::builder()
            ->addReader(new ExportingReader($exporter))
            ->build();
        $metrics = (new InstrumentationRegistry(
            new NoopTracerProvider(),
            $meterProvider,
            new NoopLoggerProvider(),
        ))->metrics('heroyt/laser-arena-control', '0.5.1');

        $counter = $metrics->counter('result.imports', '{result}', 'Imported result files.');
        self::assertSame(
            $counter,
            $metrics->counter('result.imports', '{result}', 'Imported result files.'),
        );
        $counter->add(2, ['result.outcome' => 'success']);
        $metrics->histogram('result.import.duration', 's', 'Result import duration.')
            ->record(0.25, ['result.outcome' => 'success']);

        self::assertTrue($meterProvider->forceFlush());
        $exported = [];
        foreach ($exporter->collect() as $metric) {
            $exported[$metric->name] = $metric;
        }

        self::assertCount(2, $exported);
        $counterMetric = $exported['result.imports'];
        self::assertSame('{result}', $counterMetric->unit);
        self::assertSame('Imported result files.', $counterMetric->description);
        self::assertSame('heroyt/laser-arena-control', $counterMetric->instrumentationScope->getName());
        self::assertSame('0.5.1', $counterMetric->instrumentationScope->getVersion());
        self::assertInstanceOf(Sum::class, $counterMetric->data);
        $counterPoints = is_array($counterMetric->data->dataPoints)
            ? $counterMetric->data->dataPoints
            : iterator_to_array($counterMetric->data->dataPoints);
        self::assertCount(1, $counterPoints);
        self::assertSame(2, $counterPoints[0]->value);
        self::assertSame('success', $counterPoints[0]->attributes->get('result.outcome'));

        $histogramMetric = $exported['result.import.duration'];
        self::assertInstanceOf(Histogram::class, $histogramMetric->data);
        $histogramPoints = is_array($histogramMetric->data->dataPoints)
            ? $histogramMetric->data->dataPoints
            : iterator_to_array($histogramMetric->data->dataPoints);
        self::assertCount(1, $histogramPoints);
        self::assertSame(1, $histogramPoints[0]->count);
        self::assertSame(0.25, $histogramPoints[0]->sum);
        self::assertSame('success', $histogramPoints[0]->attributes->get('result.outcome'));
    }

    public function test_rejects_conflicting_instrument_definitions(): void {
        $metrics = new Metrics((new NoopMeterProvider())->getMeter('tests/metrics'));
        $metrics->counter('result.imports', '{result}', 'Imported result files.');

        try {
            $metrics->counter('result.imports', '1', 'Imported result files.');
            self::fail('A conflicting counter definition was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('result.imports', $exception->getMessage());
        }

        $otherMetrics = new Metrics((new NoopMeterProvider())->getMeter('tests/metrics'));
        $otherMetrics->counter('result.imports');
        $this->expectException(LogicException::class);
        $otherMetrics->histogram('result.imports');
    }

    public function test_telemetry_failures_do_not_affect_application_control_flow(): void {
        $this->expectNotToPerformAssertions();

        $meter = $this->createStub(MeterInterface::class);
        $meter->method('createCounter')->willThrowException(new RuntimeException('Meter unavailable.'));
        (new Metrics($meter))->counter('operations')->add();

        $counter = $this->createStub(CounterInterface::class);
        $counter->method('add')->willThrowException(new RuntimeException('Counter unavailable.'));
        (new CounterMetric($counter))->add();

        $histogram = $this->createStub(HistogramInterface::class);
        $histogram->method('record')->willThrowException(new RuntimeException('Histogram unavailable.'));
        (new HistogramMetric($histogram))->record(1);
    }
}
