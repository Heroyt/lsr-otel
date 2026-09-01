<?php

declare(strict_types=1);

namespace Tests;

use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Tracing;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TracingTest extends TestCase
{
    public function testTraceReturnsCallbackResultAndActivatesNewSpan(): void {
        [$registry, $tracerProvider, $exporter] = $this->telemetry();
        $tracing = $registry->tracing('heroyt/laser-arena-control', '0.5.1');

        $result = $tracing->trace(
            'result.import',
            function (SpanInterface $span) use ($registry): string {
                self::assertTrue($span->getContext()->isValid());
                $child = $registry->tracer('heroyt/laser-arena-control', '0.5.1')
                    ->spanBuilder('result.parse')
                    ->startSpan();
                $child->end();
                return 'imported';
            },
            ['result.format' => 'lasermaxx'],
        );

        self::assertSame('imported', $result);
        self::assertTrue($tracerProvider->forceFlush());
        $spans = [];
        foreach ($exporter->getSpans() as $span) {
            $spans[$span->getName()] = $span;
        }
        self::assertArrayHasKey('result.import', $spans);
        self::assertArrayHasKey('result.parse', $spans);
        self::assertSame('lasermaxx', $spans['result.import']->getAttributes()->get('result.format'));
        self::assertSame(
            $spans['result.import']->getContext()->getSpanId(),
            $spans['result.parse']->getParentContext()->getSpanId(),
        );
        self::assertSame(
            'heroyt/laser-arena-control',
            $spans['result.import']->getInstrumentationScope()->getName(),
        );
        self::assertSame('0.5.1', $spans['result.import']->getInstrumentationScope()->getVersion());
    }

    public function testTraceRecordsAndRethrowsCallbackException(): void {
        [$registry, $tracerProvider, $exporter] = $this->telemetry();
        $exception = new RuntimeException('Import failed.');

        try {
            $registry->tracing('esoul/hoofvet')->trace(
                'result.import',
                static function (SpanInterface $span) use ($exception): string {
                    if ($span->getContext()->isValid()) {
                        throw $exception;
                    }
                    return 'unexpected';
                },
            );
            self::fail('The callback exception was not rethrown.');
        } catch (RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        self::assertTrue($tracerProvider->forceFlush());
        self::assertCount(1, $exporter->getSpans());
        $span = $exporter->getSpans()[0];
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertCount(1, $span->getEvents());
    }

    public function testManualSpanEndIsIdempotent(): void {
        [$registry, $tracerProvider, $exporter] = $this->telemetry();
        $activeSpan = $registry->tracing('esoul/hoofvet')->start('manual.operation');

        $activeSpan->end();
        $activeSpan->end();

        self::assertTrue($tracerProvider->forceFlush());
        self::assertCount(1, $exporter->getSpans());
    }

    public function testTelemetryStartupFailureDoesNotAffectCallback(): void {
        $tracer = $this->createStub(TracerInterface::class);
        $tracer->method('spanBuilder')->willThrowException(new RuntimeException('Exporter unavailable.'));

        $result = (new Tracing($tracer))->trace('operation', static fn(): string => 'completed');

        self::assertSame('completed', $result);
    }

    /**
     * @return array{InstrumentationRegistry, TracerProviderInterface, InMemoryExporter}
     */
    private function telemetry(): array {
        $exporter = new InMemoryExporter();
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($exporter))
            ->build();
        $registry = new InstrumentationRegistry(
            $tracerProvider,
            new NoopMeterProvider(),
            new NoopLoggerProvider(),
        );

        return [$registry, $tracerProvider, $exporter];
    }
}
