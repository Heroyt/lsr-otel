<?php

declare(strict_types=1);

namespace Lsr\Otel\Internal;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;

final class DurationHistogram
{
    private const array BOUNDARIES_SECONDS = [
        0.001,
        0.0025,
        0.005,
        0.01,
        0.025,
        0.05,
        0.075,
        0.1,
        0.25,
        0.5,
        0.75,
        1,
        2.5,
        5,
        7.5,
        10,
        30,
        60,
        120,
        300,
    ];

    /**
     * @param non-empty-string $name
     */
    public static function create(
        ?MeterInterface $meter,
        string $name,
        string $description,
    ): ?HistogramInterface {
        return $meter?->createHistogram(
            $name,
            's',
            $description,
            ['ExplicitBucketBoundaries' => self::BOUNDARIES_SECONDS],
        );
    }
}
