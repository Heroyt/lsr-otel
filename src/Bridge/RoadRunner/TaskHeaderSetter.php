<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Spiral\RoadRunner\Jobs\Task\PreparedTaskInterface;

final class TaskHeaderSetter implements PropagationSetterInterface
{
    public function set(mixed &$carrier, string $key, string $value): void {
        if ($key !== '' && $value !== '' && $carrier instanceof PreparedTaskInterface) {
            $carrier = $carrier->withHeader($key, $value);
        }
    }
}
