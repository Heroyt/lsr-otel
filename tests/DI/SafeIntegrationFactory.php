<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Caching\Cache;
use Lsr\Core\App;
use Lsr\Core\Auth\Models\User;
use Lsr\Core\Auth\Services\Auth;
use Lsr\Core\Requests\Validation\RequestValidationMapper;
use Lsr\Db\Connection;
use Lsr\Inertia\Services\Inertia;
use Lsr\Scheduler\Internal\ScheduledCommandMessageHandler;
use Lsr\Scheduler\Internal\SchedulerJobMessageHandler;
use ReflectionClass;
use Symfony\Component\Console\Output\BufferedOutput;

final class SafeIntegrationFactory
{
    public static function cache(): Cache {
        return (new ReflectionClass(Cache::class))->newInstanceWithoutConstructor();
    }

    public static function app(): App {
        return (new ReflectionClass(App::class))->newInstanceWithoutConstructor();
    }

    public static function schedulerJobHandler(): SchedulerJobMessageHandler {
        return new SchedulerJobMessageHandler();
    }

    public static function scheduledCommandHandler(): ScheduledCommandMessageHandler {
        return new ScheduledCommandMessageHandler(output: new BufferedOutput());
    }

    /** @return Auth<User> */
    public static function auth(): Auth {
        return (new ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
    }

    public static function requestMapper(): RequestValidationMapper {
        return (new ReflectionClass(RequestValidationMapper::class))->newInstanceWithoutConstructor();
    }

    public static function database(): Connection {
        return (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
    }

    public static function inertia(): Inertia {
        return (new ReflectionClass(Inertia::class))->newInstanceWithoutConstructor();
    }
}
