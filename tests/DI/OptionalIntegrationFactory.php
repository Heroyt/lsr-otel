<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Core\FpmHandler;
use Lsr\CQRS\CommandBus;
use Lsr\Roadrunner\Tasks\TaskProducer;
use Lsr\Roadrunner\Workers\HttpWorker;
use Lsr\Roadrunner\Workers\JobsWorker;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Application;

final class OptionalIntegrationFactory
{
    public static function fpm(): FpmHandler {
        $handler = (new ReflectionClass(FpmHandler::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(FpmHandler::class, 'asyncHandlers'))->setValue($handler, []);
        (new ReflectionProperty(FpmHandler::class, 'requestLifecycle'))->setValue($handler, null);
        return $handler;
    }

    public static function httpWorker(): HttpWorker {
        return (new ReflectionClass(HttpWorker::class))->newInstanceWithoutConstructor();
    }

    public static function jobsWorker(): JobsWorker {
        return (new ReflectionClass(JobsWorker::class))->newInstanceWithoutConstructor();
    }

    public static function taskProducer(): TaskProducer {
        return (new ReflectionClass(TaskProducer::class))->newInstanceWithoutConstructor();
    }

    public static function commandBus(): CommandBus {
        return (new ReflectionClass(CommandBus::class))->newInstanceWithoutConstructor();
    }

    public static function application(): Application {
        return new Application();
    }
}
