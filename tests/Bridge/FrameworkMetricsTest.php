<?php

declare(strict_types=1);

namespace Tests\Bridge;

use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\CQRS\Lifecycle\CommandLifecycleHookInterface;
use Lsr\Otel\Bridge\Console\ConsoleTelemetrySubscriber;
use Lsr\Otel\Bridge\Core\HttpServerLifecycleHook;
use Lsr\Otel\Bridge\Cqrs\CommandLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\TaskConsumerLifecycleHook;
use Lsr\Otel\Bridge\RoadRunner\TaskProducerLifecycleHook;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleHookInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\Jobs\Queue\Driver;
use Spiral\RoadRunner\Jobs\Task\PreparedTask;
use Spiral\RoadRunner\Jobs\Task\ReceivedTask;
use Spiral\RoadRunner\WorkerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class FrameworkMetricsTest extends TestCase
{
    protected function setUp(): void {
        if (
            ! interface_exists(RequestLifecycleHookInterface::class)
            || ! interface_exists(CommandLifecycleHookInterface::class)
            || ! interface_exists(TaskDispatchLifecycleHookInterface::class)
            || ! interface_exists(TaskLifecycleHookInterface::class)
            || ! class_exists(PreparedTask::class)
            || ! class_exists(Command::class)
        ) {
            self::markTestSkipped('Optional framework packages are not installed.');
        }
    }

    public function test_adapters_export_framework_metrics(): void {
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
        $propagator = TraceContextPropagator::getInstance();

        $http = new HttpServerLifecycleHook($instrumentation, $propagator, true, true);
        $http->begin(new ServerRequest('GET', '/health'))->complete(new Response(204));

        $cqrs = new CommandLifecycleHook($instrumentation, true, true);
        $cqrs->begin(new MetricsCommand())->complete();

        $producer = new TaskProducerLifecycleHook($instrumentation, $propagator, true, true);
        $producerScope = $producer->begin('metrics', [new PreparedTask('publish', '')]);
        self::assertNotSame('', $producerScope->tasks()[0]->getHeaderLine('traceparent'));
        $producerScope->complete();

        $receivedTask = new ReceivedTask(
            $this->createStub(WorkerInterface::class),
            'task-id',
            Driver::Memory,
            'pipeline',
            'consume',
            'metrics',
            '',
        );
        $consumer = new TaskConsumerLifecycleHook($instrumentation, $propagator, true, true);
        $consumer->begin($receivedTask)->complete();

        $lifecycle = $this->createMock(TelemetryLifecycleInterface::class);
        $lifecycle->expects(self::once())->method('forceFlush')->willReturn(true);
        $console = new ConsoleTelemetrySubscriber($instrumentation, $lifecycle, true, true);
        $command = new Command('metrics:test');
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $console->onCommand(new ConsoleCommandEvent($command, $input, $output));
        $console->onTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        self::assertTrue($tracerProvider->forceFlush());
        self::assertTrue($meterProvider->forceFlush());
        self::assertCount(5, $spanExporter->getSpans());

        $metrics = $metricExporter->collect();
        $names = [];
        foreach ($metrics as $metric) {
            $names[] = $metric->name;
            if ( ! $metric->data instanceof Histogram) {
                continue;
            }

            $dataPoints = is_array($metric->data->dataPoints)
                ? $metric->data->dataPoints
                : iterator_to_array($metric->data->dataPoints);
            foreach ($dataPoints as $dataPoint) {
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
                    $dataPoint->explicitBounds,
                    $metric->name,
                );
            }
        }
        sort($names);
        self::assertSame(
            [
                'http.server.request.duration',
                'lsr.console.command.duration',
                'lsr.console.commands',
                'lsr.cqrs.command.duration',
                'lsr.cqrs.commands',
                'lsr.roadrunner.job.duration',
                'lsr.roadrunner.jobs',
                'lsr.roadrunner.task.publish.duration',
                'lsr.roadrunner.tasks.published',
            ],
            $names,
        );
    }
}
