<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Cqrs;

use Lsr\CQRS\CommandInterface;
use Lsr\CQRS\Lifecycle\CommandLifecycleHookInterface;
use Lsr\CQRS\Lifecycle\CommandLifecycleScopeInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class CommandLifecycleHook implements CommandLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/cqrs') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/cqrs') : null;
        $this->duration = $meter?->createHistogram(
            'lsr.cqrs.command.duration',
            's',
            'Duration of synchronous CQRS command dispatch.',
        );
        $this->count = $meter?->createCounter(
            'lsr.cqrs.commands',
            '{command}',
            'Synchronous CQRS commands dispatched by Laser.',
        );
    }

    /**
     * @template T of mixed
     * @param CommandInterface<T> $command
     */
    public function begin(CommandInterface $command): CommandLifecycleScopeInterface {
        $class = $command::class;
        $separator = strrpos($class, '\\');
        $name = $separator === false ? $class : substr($class, $separator + 1);
        $attributes = ['lsr.cqrs.command' => $name];
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $span = $this->tracer
                    ->spanBuilder('cqrs ' . $name)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes($attributes)
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                $span = null;
                $scope = null;
            }
        }

        return new CommandLifecycleScope(
            $span,
            $scope,
            $this->duration,
            $this->count,
            $attributes,
        );
    }
}
