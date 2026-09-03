<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleHookInterface;
use Lsr\Roadrunner\Lifecycle\TaskLifecycleScopeInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Spiral\RoadRunner\Jobs\Task\ReceivedTaskInterface;
use Throwable;

final readonly class TaskConsumerLifecycleHook implements TaskLifecycleHookInterface
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
            'lsr.roadrunner.job.duration',
            'Duration of RoadRunner job processing.',
        );
        $this->count = $meter?->createCounter(
            'lsr.roadrunner.jobs',
            '{job}',
            'RoadRunner jobs processed by Laser.',
        );
    }

    public function begin(ReceivedTaskInterface $task): TaskLifecycleScopeInterface {
        $attributes = [
            'messaging.system' => 'roadrunner',
            'messaging.destination.name' => $task->getQueue(),
            'messaging.operation.name' => $task->getName(),
            'messaging.operation.type' => 'process',
        ];
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $parent = $this->propagator->extract(
                    $task,
                    new TaskHeaderGetter(),
                    Context::getRoot(),
                );
                $span = $this->tracer
                    ->spanBuilder($task->getName() . ' process')
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_CONSUMER)
                    ->setAttributes($attributes)
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                $span = null;
                $scope = null;
            }
        }

        return new TaskConsumerLifecycleScope(
            $task,
            $span,
            $scope,
            $this->duration,
            $this->count,
            $attributes,
        );
    }
}
