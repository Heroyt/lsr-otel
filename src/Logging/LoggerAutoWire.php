<?php

declare(strict_types=1);

namespace Lsr\Otel\Logging;

use Lsr\Logging\Interface\CompositeStorageInterface;
use Lsr\Logging\Interface\LogFilterInterface;
use Lsr\Logging\Interface\StorageInterface;
use Lsr\Logging\Logger;
use Lsr\Logging\LogLevel;
use Lsr\Logging\LogRecord;
use Lsr\Logging\Storage\FilteredStorage;

/** @internal Container-managed LSR loggers only; no global logger interception. */
final class LoggerAutoWire
{
    private readonly StorageInterface $destination;

    /** @param (callable(LogRecord): ?LogRecord)|LogFilterInterface|null $filter */
    public function __construct(
        OtelStorage $storage,
        string|LogLevel $level = LogLevel::DEBUG,
        callable|LogFilterInterface|null $filter = null,
    ) {
        $level = LogLevel::normalize($level);
        $this->destination = $level === LogLevel::DEBUG && $filter === null
            ? $storage
            : new FilteredStorage($storage, $level, $filter);
    }

    public function attach(Logger $logger): void {
        $visited = [];
        if ($this->containsOtelStorage($logger->getStorage(), $visited)) {
            return;
        }

        $logger->addStorage($this->destination);
    }

    /** @param array<int, true> $visited */
    private function containsOtelStorage(StorageInterface $storage, array &$visited): bool {
        if ($storage instanceof OtelStorage) {
            return true;
        }
        $id = spl_object_id($storage);
        if (isset($visited[$id])) {
            return false;
        }
        $visited[$id] = true;

        if ($storage instanceof CompositeStorageInterface) {
            foreach ($storage->getStorages() as $child) {
                if ($this->containsOtelStorage($child, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }
}
