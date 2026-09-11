<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Logging\Formatter\LegacyFormatter;
use Lsr\Logging\Logger;
use Lsr\Logging\Storage\DailyLogStorage;
use Lsr\Logging\Storage\FilteredStorage;
use Lsr\Logging\Storage\StackStorage;
use Lsr\Otel\Logging\OtelStorage;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;

final class LoggingIntegrationFactory
{
    public static function loggerProvider(InMemoryExporter $exporter): LoggerProviderInterface {
        return LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($exporter))
            ->build();
    }

    public static function logger(string $directory): Logger {
        return new Logger($directory, 'factory');
    }

    public static function shared(Logger $logger): Logger {
        return $logger;
    }

    public static function explicit(string $directory, OtelStorage $storage): Logger {
        return new Logger($directory, 'explicit', new StackStorage([
            new DailyLogStorage($directory, 'explicit', new LegacyFormatter()),
            new StackStorage([
                new StackStorage([new FilteredStorage($storage, 'ERROR')]),
            ]),
        ]));
    }
}
