<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use Lsr\Core\Http\Lifecycle\RouteResolutionEvent;
use Lsr\Core\Http\Lifecycle\RouteResolutionHookInterface;
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\Span;
use Throwable;

final readonly class RouteResolutionHook implements RouteResolutionHookInterface
{
    private ?HistogramInterface $duration;
    private ?CounterInterface $count;

    public function __construct(
        InstrumentationRegistry $instrumentation,
        private bool $traces = true,
        bool $metrics = true,
    ) {
        $meter = $metrics ? $instrumentation->meter('lsr/routing') : null;
        $this->duration = $meter?->createHistogram(
            'lsr.routing.match.duration',
            's',
            'Duration of HTTP route resolution.',
        );
        $this->count = $meter?->createCounter(
            'lsr.routing.matches',
            '{match}',
            'HTTP route resolution outcomes.',
        );
    }

    public function record(RouteResolutionEvent $event): void {
        $metricAttributes = [
            'http.request.method' => $event->method->value,
            'lsr.routing.outcome' => $event->outcome(),
        ];

        try {
            $this->duration?->record($event->durationSeconds, $metricAttributes);
            $this->count?->add(1, $metricAttributes);
        } catch (Throwable) {
            // Continue with trace enrichment.
        }

        if (!$this->traces) {
            return;
        }

        try {
            $attributes = $metricAttributes;
            if ($event->errorType !== null) {
                $attributes['error.type'] = $event->errorType;
            }

            $route = $event->route;
            if ($route !== null) {
                $routeTemplate = '/' . implode('/', $route->getPath());
                $attributes['http.route'] = $routeTemplate;
                $routeName = $route->getName();
                if ($routeName !== '') {
                    $attributes['lsr.routing.route.name'] = $routeName;
                }
            }

            $span = Span::getCurrent();
            $span->setAttributes($attributes)->addEvent('lsr.route.resolved', $attributes);
            if ($route !== null) {
                $span->updateName($event->method->value . ' ' . $routeTemplate);
            }
        } catch (Throwable) {
            // Telemetry must never affect route resolution.
        }
    }
}
