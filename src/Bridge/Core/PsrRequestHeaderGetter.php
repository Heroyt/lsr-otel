<?php

declare(strict_types=1);

namespace Lsr\Otel\Bridge\Core;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PsrRequestHeaderGetter implements PropagationGetterInterface
{
    public function keys(mixed $carrier): array {
        return $carrier instanceof ServerRequestInterface ? array_keys($carrier->getHeaders()) : [];
    }

    public function get(mixed $carrier, string $key): ?string {
        if ( ! $carrier instanceof ServerRequestInterface || ! $carrier->hasHeader($key)) {
            return null;
        }

        $value = $carrier->getHeaderLine($key);
        return $value !== '' ? $value : null;
    }
}
