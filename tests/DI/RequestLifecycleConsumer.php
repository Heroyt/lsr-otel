<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Core\Http\Lifecycle\RequestLifecycleHookInterface;

final readonly class RequestLifecycleConsumer
{
    public function __construct(public ?RequestLifecycleHookInterface $hook = null) {
    }
}
