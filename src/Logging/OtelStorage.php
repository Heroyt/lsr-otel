<?php

declare(strict_types=1);

namespace Lsr\Otel\Logging;

use Lsr\Logging\ContextNormalizer;
use Lsr\Logging\Interface\RecordStorageInterface;
use Lsr\Logging\LogLevel;
use Lsr\Logging\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\Context;
use Throwable;

final class OtelStorage implements RecordStorageInterface
{
    private readonly ContextNormalizer $normalizer;

    public function __construct(private readonly LoggerInterface $logger) {
        $this->normalizer = new ContextNormalizer();
    }

    public function store(string|LogLevel $level, string $message, mixed $context = null): void {
        $this->storeRecord(new LogRecord($level, $message, $context));
    }

    public function storeRecord(LogRecord $record): void {
        $severity = Severity::fromPsr3($record->level->value);
        $activeContext = Context::getCurrent();
        if ( ! $this->logger->isEnabled($activeContext, $severity->value)) {
            return;
        }

        $context = $this->normalizer->normalize($record->context);
        $attributes = [];
        if ($record->loggerName !== null) {
            $attributes['lsr.logger.name'] = $record->loggerName;
        }

        $exception = $record->context instanceof Throwable
            ? $context
            : (is_array($context) ? ($context['exception'] ?? null) : null);
        if (is_array($exception)) {
            foreach (['type' => 'type', 'message' => 'message', 'trace' => 'stacktrace'] as $key => $attribute) {
                if (isset($exception[$key]) && is_string($exception[$key])) {
                    $attributes['exception.' . $attribute] = $exception[$key];
                }
            }
        }

        if ( ! is_array($context)) {
            $context = $context === null ? [] : ['context' => $context];
        }
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if ($key === 'lsr.logger.name' || array_key_exists($key, $attributes) || $value === null) {
                continue;
            }
            $attributes[$key] = $this->attributeValue($value);
        }

        $this->logger->logRecordBuilder()
            ->setContext($activeContext)
            ->setSeverityNumber($severity)
            ->setSeverityText($record->level->value)
            ->setBody($record->message)
            ->setAttributes($attributes)
            ->emit();
    }

    private function attributeValue(mixed $value): mixed {
        if ( ! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $type = null;
            $homogeneous = true;
            foreach ($value as $item) {
                if ( ! is_scalar($item) || ($type !== null && get_debug_type($item) !== $type)) {
                    $homogeneous = false;
                    break;
                }
                $type ??= get_debug_type($item);
            }
            if ($homogeneous) {
                return $value;
            }
        }

        // Preserve nested keys and mixed values without relying on exporter-specific array support.
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
