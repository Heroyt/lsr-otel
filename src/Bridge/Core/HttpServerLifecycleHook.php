<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RequestLifecycleScopeInterface;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Internal\DurationHistogram;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class HttpServerLifecycleHook implements RequestLifecycleHookInterface
{
    private ?TracerInterface $tracer;
    private ?HistogramInterface $duration;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        private TextMapPropagatorInterface $propagator,
        bool $traces = true,
        bool $metrics = true,
        string $instrumentationName = 'lsr/core',
    ) {
        $this->tracer = $traces ? $instrumentation->tracer($instrumentationName) : null;
        $this->duration = $metrics
            ? DurationHistogram::create(
                $instrumentation->meter($instrumentationName),
                'http.server.request.duration',
                'Duration of inbound HTTP requests handled by Laser.',
            )
            : null;
    }

    public function begin(ServerRequestInterface $request): RequestLifecycleScopeInterface {
        $method = strtoupper($request->getMethod());
        $method = $method !== '' ? $method : '_OTHER';
        $span = null;
        $scope = null;

        if ($this->tracer !== null) {
            try {
                $parent = $this->propagator->extract(
                    $request,
                    new PsrRequestHeaderGetter(),
                    Context::getRoot(),
                );
                $span = $this->tracer
                    ->spanBuilder('HTTP ' . $method)
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute('http.request.method', $method)
                    ->startSpan();
                $scope = $span->activate();
            } catch (Throwable) {
                $span = null;
                $scope = null;
            }
        }

        return new HttpServerLifecycleScope(
            $method,
            $span,
            $scope,
            $this->duration,
        );
    }
}
