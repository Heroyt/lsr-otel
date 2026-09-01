<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Cqrs;

use Lsr\CQRS\Lifecycle\CommandLifecycleScopeInterface;
use Lsr\Otel\Internal\TelemetryOperation;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class CommandLifecycleScope implements CommandLifecycleScopeInterface
{
    private readonly TelemetryOperation $operation;

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function __construct(
        ?SpanInterface $span,
        ?ScopeInterface $scope,
        ?HistogramInterface $duration,
        ?CounterInterface $count,
        array $attributes,
    ) {
        $this->operation = new TelemetryOperation(
            $span,
            $scope,
            $duration,
            $count,
            $attributes,
            hrtime(true),
        );
    }

    public function recordException(Throwable $exception): void {
        $this->operation->recordException($exception);
    }

    public function complete(): void {
        $failed = $this->operation->hasFailed();
        $this->operation->complete([
            'lsr.operation.outcome' => $failed ? 'failure' : 'success',
        ], error: $failed);
    }
}
