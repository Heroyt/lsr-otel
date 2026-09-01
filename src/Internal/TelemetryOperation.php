<?php

declare(strict_types=1);

namespace Lsr\Otel\Internal;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class TelemetryOperation
{
    private bool $completed = false;
    private bool $failed = false;

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function __construct(
        private readonly ?SpanInterface $span,
        private readonly ?ScopeInterface $scope,
        private readonly ?HistogramInterface $duration,
        private readonly ?CounterInterface $count,
        private readonly array $attributes,
        private readonly int $startedAt,
    ) {
    }

    public function recordException(Throwable $exception): void {
        if ($this->completed) {
            return;
        }

        $this->failed = true;

        try {
            $this->span?->recordException($exception)->setStatus(StatusCode::STATUS_ERROR);
        } catch (Throwable) {
            // Telemetry must never affect application control flow.
        }
    }

    public function hasFailed(): bool {
        return $this->failed;
    }

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function complete(
        array $attributes = [],
        ?string $spanName = null,
        bool $error = false,
    ): void {
        if ($this->completed) {
            return;
        }
        $this->completed = true;

        $attributes = array_merge($this->attributes, $attributes);
        $error = $error || $this->failed;

        try {
            if ($spanName !== null && $spanName !== '') {
                $this->span?->updateName($spanName);
            }
            $this->span?->setAttributes($attributes);
            if ($error) {
                $this->span?->setStatus(StatusCode::STATUS_ERROR);
            }
        } catch (Throwable) {
            // Continue with metrics and context cleanup.
        }

        $elapsed = (hrtime(true) - $this->startedAt) / 1_000_000_000;

        try {
            $this->duration?->record($elapsed, $attributes);
            $this->count?->add(1, $attributes);
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
                // Telemetry must never affect application control flow.
            }
        }
    }
}
