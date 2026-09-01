<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Orm;

use Lsr\Orm\Lifecycle\ModelLifecycleEvent;
use Lsr\Orm\Lifecycle\ModelLifecycleScopeInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class ModelLifecycleScope implements ModelLifecycleScopeInterface
{
    private bool $completed = false;
    private int $startedAt;

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $traceAttributes
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $metricAttributes
     */
    public function __construct(
        private readonly ?SpanInterface $span,
        private readonly ?ScopeInterface $scope,
        private readonly ?HistogramInterface $duration,
        private readonly ?CounterInterface $count,
        private readonly array $traceAttributes,
        private readonly array $metricAttributes,
    ) {
        $this->startedAt = (int) hrtime(true);
    }

    public function complete(
        string $outcome,
        ?int $resultCount = null,
        ?string $errorType = null,
    ): void {
        if ($this->completed) {
            return;
        }
        $this->completed = true;

        $traceAttributes = $this->traceAttributes + ['lsr.orm.outcome' => $outcome];
        if ($resultCount !== null) {
            $traceAttributes['lsr.orm.result.count'] = $resultCount;
        }
        if ($errorType !== null) {
            $traceAttributes['error.type'] = $errorType;
        }
        $metricAttributes = $this->metricAttributes + ['lsr.orm.outcome' => $outcome];

        try {
            $this->span?->setAttributes($traceAttributes);
            if ($outcome !== ModelLifecycleEvent::SUCCESS) {
                $this->span?->setStatus(StatusCode::STATUS_ERROR);
            }
        } catch (Throwable) {
            // Continue with metrics and context cleanup.
        }

        $elapsed = max(0.0, ((int) hrtime(true) - $this->startedAt) / 1_000_000_000);
        try {
            $this->duration?->record($elapsed, $metricAttributes);
            $this->count?->add(1, $metricAttributes);
        } catch (Throwable) {
            // Continue with context cleanup.
        }

        try {
            $this->scope?->detach();
        } catch (Throwable) {
            // Span ending must still run.
        } finally {
            try {
                $this->span?->end();
            } catch (Throwable) {
                // Telemetry must never affect model operations.
            }
        }
    }
}
