<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Scheduler;

use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use Lsr\Scheduler\Lifecycle\SchedulerLifecycleHookInterface;
use Lsr\Scheduler\Lifecycle\SchedulerLifecycleScopeInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class SchedulerLifecycleHook implements SchedulerLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/scheduler') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/scheduler') : null;
        $this->duration = DurationHistogram::create(
            $meter,
            'lsr.scheduler.execution.duration',
            'Duration of scheduled job and command execution.',
        );
        $this->count = $meter?->createCounter(
            'lsr.scheduler.executions',
            '{execution}',
            'Scheduled job and command executions.',
        );
    }

    public function begin(string $kind, string $name): SchedulerLifecycleScopeInterface {
        $metricAttributes = ['lsr.scheduler.kind' => $kind];
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $span = $this->tracer
                    ->spanBuilder('scheduler ' . $kind)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes([
                        'lsr.scheduler.kind' => $kind,
                        'lsr.scheduler.name' => $name,
                    ])
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                try {
                    $scope?->detach();
                    $span?->end();
                } catch (Throwable) {
                    // Ignore cleanup failures from telemetry implementations.
                }
                $span = null;
                $scope = null;
            }
        }

        return new SchedulerLifecycleScope(
            $span,
            $scope,
            $this->duration,
            $this->count,
            $metricAttributes,
        );
    }
}
