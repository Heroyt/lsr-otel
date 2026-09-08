<?php

declare(strict_types=1);

namespace Tests\Bridge;

use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;
use Lsr\Core\Http\Lifecycle\RequestOperation;
use Lsr\Core\Http\Lifecycle\RequestOperationLifecycleHookInterface;
use Lsr\Otel\Bridge\Core\HttpServerLifecycleHook;
use Lsr\Otel\Bridge\Core\RequestOperationLifecycleHook;
use Lsr\Otel\InstrumentationRegistry;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoadRunnerHttpTraceTest extends TestCase
{
    protected function setUp(): void {
        if (
            ! interface_exists(RequestLifecycleHookInterface::class)
            || ! interface_exists(RequestOperationLifecycleHookInterface::class)
        ) {
            self::markTestSkipped('The compatible lsr/core package is not installed.');
        }
    }

    public function test_sequential_requests_export_isolated_nested_lifecycle_traces(): void {
        $exporter = new InMemoryExporter();
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor($exporter))
            ->build();
        $instrumentation = new InstrumentationRegistry(
            $tracerProvider,
            new NoopMeterProvider(),
            new NoopLoggerProvider(),
        );
        $http = new HttpServerLifecycleHook(
            $instrumentation,
            TraceContextPropagator::getInstance(),
            instrumentationName: 'lsr/roadrunner',
        );
        $operations = new RequestOperationLifecycleHook($instrumentation);

        $firstRequest = new ServerRequest('GET', '/games');
        $firstRequest = $firstRequest->withHeader(
            'traceparent',
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        );
        $requestScope = $http->begin($firstRequest);
        $routeScope = $operations->begin(RequestOperation::RouteResolution);
        $routeScope->complete(['http.route' => '/games']);
        $dispatchScope = $operations->begin(RequestOperation::RouteDispatch);
        $diScope = $operations->begin(
            RequestOperation::DependencyResolution,
            ['lsr.di.service' => 'App\\Http\\Controllers\\Games'],
        );
        $diScope->complete();
        $dispatchScope->complete();
        $requestScope->complete(new Response(200));

        $requestScope = $http->begin(new ServerRequest('POST', '/games'));
        $actionScope = $operations->begin(RequestOperation::ControllerAction);
        $actionScope->complete(exception: new RuntimeException('Action failed.'));
        $requestScope->complete(new Response(500));

        self::assertTrue($tracerProvider->forceFlush());
        $spans = $exporter->getSpans();
        self::assertCount(6, $spans);

        $firstHttp = $this->span($spans, 'HTTP GET');
        $route = $this->span($spans, RequestOperation::RouteResolution->value);
        $dispatch = $this->span($spans, RequestOperation::RouteDispatch->value);
        $di = $this->span($spans, RequestOperation::DependencyResolution->value);
        $secondHttp = $this->span($spans, 'HTTP POST');
        $action = $this->span($spans, RequestOperation::ControllerAction->value);

        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $firstHttp->getContext()->getTraceId());
        self::assertSame('00f067aa0ba902b7', $firstHttp->getParentContext()->getSpanId());
        self::assertSame($firstHttp->getContext()->getSpanId(), $route->getParentContext()->getSpanId());
        self::assertSame($firstHttp->getContext()->getSpanId(), $dispatch->getParentContext()->getSpanId());
        self::assertSame($dispatch->getContext()->getSpanId(), $di->getParentContext()->getSpanId());
        self::assertSame('/games', $route->getAttributes()->get('http.route'));
        self::assertSame(
            'App\\Http\\Controllers\\Games',
            $di->getAttributes()->get('lsr.di.service'),
        );
        self::assertNotSame($firstHttp->getContext()->getTraceId(), $secondHttp->getContext()->getTraceId());
        self::assertSame($secondHttp->getContext()->getSpanId(), $action->getParentContext()->getSpanId());
        self::assertSame(StatusCode::STATUS_ERROR, $action->getStatus()->getCode());
        self::assertSame(RuntimeException::class, $action->getAttributes()->get('error.type'));
    }

    /**
     * @param list<SpanDataInterface> $spans
     */
    private function span(array $spans, string $name): SpanDataInterface {
        foreach ($spans as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        self::fail('Span not found: ' . $name);
    }
}
