<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Cache;

use Lsr\Caching\Lifecycle\CacheLifecycleScopeInterface;
use Lsr\Otel\Internal\TelemetryOperation;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class CacheLifecycleScope implements CacheLifecycleScopeInterface
{
    private readonly TelemetryOperation $operation;

    /**
     * @param array<non-empty-string, bool|int|float|string|list<bool|int|float|string>|null> $attributes
     */
    public function __construct(
        private readonly ?SpanInterface $span,
        ?ScopeInterface $scope,
        ?HistogramInterface $duration,
        ?CounterInterface $operations,
        private readonly ?CounterInterface $items,
        private readonly array $attributes,
    ) {
        $this->operation = new TelemetryOperation(
            $span,
            $scope,
            $duration,
            $operations,
            $attributes,
            hrtime(true),
        );
    }

    public function recordException(Throwable $exception): void {
        $this->operation->recordException($exception);
    }

    public function complete(int $hits, int $misses, int $generated): void {
        try {
            $this->span?->setAttributes([
                'lsr.cache.hit_count' => $hits,
                'lsr.cache.miss_count' => $misses,
                'lsr.cache.generated_count' => $generated,
            ]);
        } catch (Throwable) {
            // Continue with metrics and span completion.
        }

        $plainMisses = max(0, $misses - $generated);
        try {
            $this->recordItems($hits, 'hit');
            $this->recordItems($plainMisses, 'miss');
            $this->recordItems($generated, 'generated');
        } catch (Throwable) {
            // Continue with span completion.
        }

        $failed = $this->operation->hasFailed();
        $this->operation->complete([
            'lsr.operation.outcome' => $failed ? 'failure' : 'success',
        ], error: $failed);
    }

    private function recordItems(int $count, string $result): void {
        if ($count === 0) {
            return;
        }
        $this->items?->add($count, array_merge($this->attributes, ['lsr.cache.result' => $result]));
    }
}
