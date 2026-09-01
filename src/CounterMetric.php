<?php

declare(strict_types=1);

namespace Lsr\Otel;

use OpenTelemetry\API\Metrics\CounterInterface;
use Throwable;

final readonly class CounterMetric
{
    public function __construct(private ?CounterInterface $counter) {
    }

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function add(int|float $value = 1, array $attributes = []): void {
        try {
            $this->counter?->add($value, $attributes);
        } catch (Throwable) {
            // Telemetry must never affect application control flow.
        }
    }
}
