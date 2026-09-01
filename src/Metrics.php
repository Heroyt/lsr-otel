<?php

declare(strict_types=1);

namespace Lsr\Otel;

use InvalidArgumentException;
use LogicException;
use OpenTelemetry\API\Metrics\MeterInterface;
use Throwable;

final class Metrics
{
    /** @var array<non-empty-string, CounterMetric> */
    private array $counters = [];

    /** @var array<non-empty-string, HistogramMetric> */
    private array $histograms = [];

    /**
     * @var array<non-empty-string, array{
     *     type: 'counter'|'histogram',
     *     unit: ?string,
     *     description: ?string
     * }>
     */
    private array $definitions = [];

    public function __construct(private readonly MeterInterface $meter) {
    }

    /**
     * @param non-empty-string $name
     */
    public function counter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
    ): CounterMetric {
        $this->assertDefinition($name, 'counter', $unit, $description);
        if (isset($this->counters[$name])) {
            return $this->counters[$name];
        }

        $counter = null;
        try {
            $counter = $this->meter->createCounter($name, $unit, $description);
        } catch (Throwable) {
            // Return a no-op wrapper when a telemetry implementation fails.
        }

        return $this->counters[$name] = new CounterMetric($counter);
    }

    /**
     * @param non-empty-string $name
     */
    public function histogram(
        string $name,
        ?string $unit = null,
        ?string $description = null,
    ): HistogramMetric {
        $this->assertDefinition($name, 'histogram', $unit, $description);
        if (isset($this->histograms[$name])) {
            return $this->histograms[$name];
        }

        $histogram = null;
        try {
            $histogram = $this->meter->createHistogram($name, $unit, $description);
        } catch (Throwable) {
            // Return a no-op wrapper when a telemetry implementation fails.
        }

        return $this->histograms[$name] = new HistogramMetric($histogram);
    }

    /**
     * @param 'counter'|'histogram' $type
     */
    private function assertDefinition(
        string $name,
        string $type,
        ?string $unit,
        ?string $description,
    ): void {
        if ($name === '') {
            throw new InvalidArgumentException('Metric name must not be empty.');
        }
        $definition = [
            'type' => $type,
            'unit' => $unit,
            'description' => $description,
        ];
        if (isset($this->definitions[$name]) && $this->definitions[$name] !== $definition) {
            throw new LogicException(
                sprintf('Metric "%s" was already declared with different type, unit, or description.', $name),
            );
        }
        $this->definitions[$name] = $definition;
    }
}
