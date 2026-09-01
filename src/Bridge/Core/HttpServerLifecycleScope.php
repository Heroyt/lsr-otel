<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use Lsr\Core\App;
use Lsr\Core\Http\Lifecycle\RequestLifecycleScopeInterface;
use Lsr\Otel\Internal\TelemetryOperation;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class HttpServerLifecycleScope implements RequestLifecycleScopeInterface
{
    private readonly TelemetryOperation $operation;

    public function __construct(
        private readonly string $method,
        ?SpanInterface $span,
        ?ScopeInterface $scope,
        ?HistogramInterface $duration,
    ) {
        $this->operation = new TelemetryOperation(
            $span,
            $scope,
            $duration,
            null,
            ['http.request.method' => $this->method],
            hrtime(true),
        );
    }

    public function recordException(Throwable $exception): void {
        $this->operation->recordException($exception);
    }

    public function complete(?ResponseInterface $response = null): void {
        $attributes = [];
        if ($response !== null) {
            $attributes['http.response.status_code'] = $response->getStatusCode();
        }

        $route = $this->routeTemplate();
        if ($route !== null) {
            $attributes['http.route'] = $route;
        }

        $this->operation->complete(
            $attributes,
            $route !== null ? $this->method . ' ' . $route : 'HTTP ' . $this->method,
            $response !== null && $response->getStatusCode() >= 500,
        );
    }

    private function routeTemplate(): ?string {
        try {
            $params = [];
            $route = App::getInstance()->getRoute($params);
            if ($route === null) {
                return null;
            }

            $path = '/' . implode('/', $route->getPath());
            return $path;
        } catch (Throwable) {
            return null;
        }
    }
}
