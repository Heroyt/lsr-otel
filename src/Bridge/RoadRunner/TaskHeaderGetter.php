<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\RoadRunner;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Spiral\RoadRunner\Jobs\Task\ProvidesHeadersInterface;

final class TaskHeaderGetter implements PropagationGetterInterface
{
    public function keys(mixed $carrier): array {
        return $carrier instanceof ProvidesHeadersInterface ? array_keys($carrier->getHeaders()) : [];
    }

    public function get(mixed $carrier, string $key): ?string {
        if ($key === '' || ! $carrier instanceof ProvidesHeadersInterface || ! $carrier->hasHeader($key)) {
            return null;
        }

        $value = $carrier->getHeaderLine($key);
        return $value !== '' ? $value : null;
    }
}
