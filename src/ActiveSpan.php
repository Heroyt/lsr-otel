<?php

declare(strict_types=1);

namespace Lsr\Otel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class ActiveSpan
{
    private bool $ended = false;

    public function __construct(
        private readonly SpanInterface $span,
        private readonly ?ScopeInterface $scope,
    ) {
    }

    public function span(): SpanInterface {
        return $this->span;
    }

    public function fail(Throwable $exception): void {
        if ($this->ended) {
            return;
        }
        try {
            $this->span
                ->recordException($exception)
                ->setStatus(StatusCode::STATUS_ERROR);
        } catch (Throwable) {
            // Telemetry must never affect application control flow.
        }
    }

    public function end(): void {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        try {
            $this->scope?->detach();
        } catch (Throwable) {
            // Span ending must still run.
        } finally {
            try {
                $this->span->end();
            } catch (Throwable) {
                // Telemetry must never affect application control flow.
            }
        }
    }
}
