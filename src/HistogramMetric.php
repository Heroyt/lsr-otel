<?php

declare(strict_types=1);

namespace Lsr\Otel;

use OpenTelemetry\API\Metrics\HistogramInterface;
use Throwable;

final readonly class HistogramMetric
{
    public function __construct(private ?HistogramInterface $histogram) {
    }

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function record(int|float $value, array $attributes = []): void {
        try {
            $this->histogram?->record($value, $attributes);
        } catch (Throwable) {
            // Telemetry must never affect application control flow.
        }
    }
}
