<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use Lsr\Otel\Internal\TelemetryOperation;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskDispatchLifecycleScopeInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;
use Throwable;

final readonly class TaskProducerLifecycleHook implements TaskDispatchLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        private TextMapPropagatorInterface $propagator,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/roadrunner') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/roadrunner') : null;
        $this->duration = DurationHistogram::create(
            $meter,
            'lsr.roadrunner.task.publish.duration',
            'Duration of RoadRunner task publishing.',
        );
        $this->count = $meter?->createCounter(
            'lsr.roadrunner.tasks.published',
            '{task}',
            'RoadRunner tasks published by Laser.',
        );
    }

    /**
     * @param non-empty-list<\Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface> $tasks
     */
    public function begin(string $queue, array $tasks): TaskDispatchLifecycleScopeInterface {
        $operations = [];
        $preparedTasks = [];
        $parent = Context::getCurrent();
        $setter = new TaskHeaderSetter();

        foreach ($tasks as $task) {
            $attributes = [
                'messaging.system' => 'roadrunner',
                'messaging.destination.name' => $queue,
                'messaging.operation.name' => $task->getName(),
                'messaging.operation.type' => 'publish',
            ];
            $span = null;
            $preparedTask = $task;

            if ($this->tracer !== null) {
                try {
                    $span = $this->tracer
                        ->spanBuilder($task->getName() . ' publish')
                        ->setParent($parent)
                        ->setSpanKind(SpanKind::KIND_PRODUCER)
                        ->setAttributes($attributes)
                        ->startSpan();
                    $context = $span->storeInContext($parent);
                    $this->propagator->inject($preparedTask, $setter, $context);
                } catch (Throwable $exception) {
                    try {
                        $span?->recordException($exception);
                    } catch (Throwable) {
                        // Continue without propagation.
                    }
                }
            }

            $preparedTasks[] = $preparedTask;
            $operations[] = new TelemetryOperation(
                $span,
                null,
                $this->duration,
                $this->count,
                $attributes,
                hrtime(true),
            );
        }

        return new TaskProducerLifecycleScope($preparedTasks, $operations);
    }
}
