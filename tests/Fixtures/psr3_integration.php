<?php

declare(strict_types=1);

use Lsr\Logging\Logger;
use Lsr\Otel\GlobalSdkRegistration;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\TracerProvider;

$mode = $argv[1] ?? 'inject';
putenv('OTEL_PHP_PSR3_MODE=' . $mode);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logExporter = new InMemoryExporter();
$loggerProvider = LoggerProvider::builder()
    ->addLogRecordProcessor(new SimpleLogRecordProcessor($logExporter))
    ->build();
$tracerProvider = TracerProvider::builder()->build();
$meterProvider = MeterProvider::builder()->build();
$sdk = Sdk::builder()
    ->setTracerProvider($tracerProvider)
    ->setMeterProvider($meterProvider)
    ->setLoggerProvider($loggerProvider)
    ->build();
$registration = new GlobalSdkRegistration($sdk);
$tempDirectory = sys_get_temp_dir() . '/lsr-otel-psr3-' . bin2hex(random_bytes(8));
$logFile = $tempDirectory . '/application-' . date('Y-m-d') . '.log';

$span = $tracerProvider
    ->getTracer('lsr-otel-tests')
    ->spanBuilder('psr3-integration')
    ->startSpan();
$scope = $span->activate();
$spanContext = $span->getContext();

try {
    (new Logger($tempDirectory, 'application'))->info('PSR-3 integration message');
} finally {
    $scope->detach();
    $span->end();
    $loggerProvider->forceFlush();
    $registration->detach();
}

$records = $logExporter->getStorage()->getArrayCopy();
$exportedContext = isset($records[0]) ? $records[0]->getSpanContext() : null;
$result = [
    'file' => file_get_contents($logFile),
    'traceId' => $spanContext->getTraceId(),
    'spanId' => $spanContext->getSpanId(),
    'exportedCount' => count($records),
    'exportedBody' => isset($records[0]) ? (string) $records[0]->getBody() : null,
    'exportedTraceId' => $exportedContext?->getTraceId(),
    'exportedSpanId' => $exportedContext?->getSpanId(),
];

unlink($logFile);
rmdir($tempDirectory);
$tracerProvider->shutdown();
$meterProvider->shutdown();
$loggerProvider->shutdown();

echo json_encode($result, JSON_THROW_ON_ERROR);
