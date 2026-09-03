<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Core\App;
use Lsr\Core\FpmHandler;
use Lsr\Core\RouteHandler;
use Lsr\Roadrunner\Workers\HttpWorker;
use ReflectionClass;
use ReflectionProperty;

final class CoreOperationFactory
{
    public static function app(): App {
        return (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
    }

    public static function routeHandler(): RouteHandler {
        return (new ReflectionClass(RouteHandler::class))->newInstanceWithoutConstructor();
    }
    public static function fpmHandler(): FpmHandler {
        $handler = (new ReflectionClass(FpmHandler::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(FpmHandler::class, 'asyncHandlers'))->setValue($handler, []);
        (new ReflectionProperty(FpmHandler::class, 'requestLifecycle'))->setValue($handler, null);
        return $handler;
    }

    public static function httpWorker(): HttpWorker {
        return (new ReflectionClass(HttpWorker::class))->newInstanceWithoutConstructor();
    }
}
