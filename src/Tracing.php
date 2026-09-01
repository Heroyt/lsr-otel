<?php

declare(strict_types=1);

namespace Lsr\Otel;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class Tracing
{
    public function __construct(private TracerInterface $tracer) {
    }

    /**
     * @template T
     *
     * @param non-empty-string $name
     * @param callable(SpanInterface): T $callback
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     * @param (0|1|2|3|4) $kind
     * @return T
     */
    public function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): mixed {
        $activeSpan = $this->start($name, $attributes, $kind);
        try {
            return $callback($activeSpan->span());
        } catch (Throwable $exception) {
            $activeSpan->fail($exception);
            throw $exception;
        } finally {
            $activeSpan->end();
        }
    }

    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     * @param (0|1|2|3|4) $kind
     */
    public function start(
        string $name,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): ActiveSpan {
        $span = Span::getInvalid();
        $scope = null;
        try {
            $span = $this->tracer
                ->spanBuilder($name)
                ->setSpanKind($kind)
                ->setAttributes($attributes)
                ->startSpan();
            $scope = $span->activate();
        } catch (Throwable) {
            try {
                $span->end();
            } catch (Throwable) {
                // Ignore cleanup failures from telemetry implementations.
            }
            $span = Span::getInvalid();
        }

        return new ActiveSpan($span, $scope);
    }
}
