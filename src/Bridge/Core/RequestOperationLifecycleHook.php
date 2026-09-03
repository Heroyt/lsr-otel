<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use Lsr\Core\Http\Lifecycle\RequestOperation;
use Lsr\Core\Http\Lifecycle\RequestOperationLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RequestOperationLifecycleScopeInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

final readonly class RequestOperationLifecycleHook implements RequestOperationLifecycleHookInterface
{
    private TracerInterface $coreTracer;
    private ?TracerInterface $routingTracer;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        bool $traceRouteResolution = true,
    ) {
        $this->coreTracer = $instrumentation->tracer('lsr/core');
        $this->routingTracer = $traceRouteResolution ? $instrumentation->tracer('lsr/routing') : null;
    }

    public function begin(
        RequestOperation $operation,
        array $attributes = [],
    ): RequestOperationLifecycleScopeInterface {
        $span = null;
        $scope = null;
        $tracer = $operation === RequestOperation::RouteResolution
            ? $this->routingTracer
            : $this->coreTracer;

        if ($tracer === null) {
            return new RequestOperationLifecycleScope(null, null);
        }

        try {
            $span = $tracer
                ->spanBuilder($operation->value)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setAttributes($attributes)
                ->startSpan();
            $scope = $span->activate();
        } catch (Throwable) {
            // Return any started span so completion can still end it.
        }

        return new RequestOperationLifecycleScope($span, $scope);
    }
}
