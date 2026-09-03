<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use Lsr\Core\Http\Lifecycle\RequestOperationLifecycleScopeInterface;
use Lsr\Otel\Internal\TelemetryOperation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

final class RequestOperationLifecycleScope implements RequestOperationLifecycleScopeInterface
{
    private readonly TelemetryOperation $operation;
    public function __construct(
        ?SpanInterface $span,
        ?ScopeInterface $scope,
    ) {
        $this->operation = new TelemetryOperation(
            $span,
            $scope,
            null,
            null,
            [],
            hrtime(true),
        );
    }

    public function complete(array $attributes = [], ?Throwable $exception = null): void {
        if ($exception !== null) {
            $this->operation->recordException($exception);
            $attributes['error.type'] = $exception::class;
        }

        $this->operation->complete($attributes);
    }
}
