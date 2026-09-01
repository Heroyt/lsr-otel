<?php

declare(strict_types=1);

namespace Tests\Bridge;

use Lsr\CQRS\CommandInterface;

/** @implements CommandInterface<string> */
final class MetricsCommand implements CommandInterface
{
    public function getHandler(): string {
        return 'unused';
    }
}
