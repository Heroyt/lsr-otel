<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Console;

use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use Lsr\Otel\Internal\TelemetryOperation;
use Lsr\Otel\Lifecycle\TelemetryLifecycleInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final class ConsoleTelemetrySubscriber implements EventSubscriberInterface
{
    private readonly ?TracerInterface $tracer;
    private readonly ?HistogramInterface $duration;
    private readonly ?CounterInterface $count;
    private ?TelemetryOperation $operation = null;
    private string $commandName = 'unknown';

    public function __construct(
        InstrumentationRegistry $instrumentation,
        private readonly TelemetryLifecycleInterface $lifecycle,
        bool $traces = true,
        bool $metrics = true,
    ) {
        $this->tracer = $traces ? $instrumentation->tracer('lsr/console') : null;
        $meter = $metrics ? $instrumentation->meter('lsr/console') : null;
        $this->duration = DurationHistogram::create(
            $meter,
            'lsr.console.command.duration',
            'Duration of Symfony Console commands.',
        );
        $this->count = $meter?->createCounter(
            'lsr.console.commands',
            '{command}',
            'Symfony Console commands executed by Laser.',
        );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array {
        return [
            ConsoleEvents::COMMAND => ['onCommand', 1000],
            ConsoleEvents::ERROR => ['onError', 1000],
            ConsoleEvents::TERMINATE => ['onTerminate', -1000],
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void {
        $this->start($event);
    }

    public function onError(ConsoleErrorEvent $event): void {
        $this->operation ??= $this->createOperation($event);
        $this->operation->recordException($event->getError());
    }

    public function onTerminate(ConsoleTerminateEvent $event): void {
        $this->operation ??= $this->createOperation($event);
        $exitCode = $event->getExitCode();
        $outcome = $exitCode === 0 ? 'success' : 'failure';

        $this->operation->complete(
            [
                'console.command' => $this->commandName,
                'console.exit_code.class' => $exitCode === 0 ? 'zero' : 'nonzero',
                'lsr.operation.outcome' => $outcome,
            ],
            error: $exitCode !== 0,
        );
        $this->operation = null;
        $this->commandName = 'unknown';
        try {
            $this->lifecycle->forceFlush();
        } catch (Throwable) {
            // Telemetry export must never affect command execution.
        }
    }

    private function start(ConsoleEvent $event): void {
        if ($this->operation !== null) {
            $this->operation->complete([
                'console.command' => $this->commandName,
                'lsr.operation.outcome' => 'abandoned',
            ], error: true);
        }

        $this->operation = $this->createOperation($event);
    }

    private function createOperation(ConsoleEvent $event): TelemetryOperation {
        $this->commandName = $event->getCommand()?->getName() ?? 'unknown';
        $attributes = ['console.command' => $this->commandName];
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $span = $this->tracer
                    ->spanBuilder('console ' . $this->commandName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttributes($attributes)
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                $span = null;
                $scope = null;
            }
        }

        return new TelemetryOperation(
            $span,
            $scope,
            $this->duration,
            $this->count,
            $attributes,
            hrtime(true),
        );
    }
}
