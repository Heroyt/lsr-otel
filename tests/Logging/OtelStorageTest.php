<?php

declare(strict_types=1);

namespace Tests\Logging;

use Lsr\Logging\Filter\ContextBlacklistFilter;
use Lsr\Logging\Logger;
use Lsr\Logging\LogLevel;
use Lsr\Logging\LogRecord;
use Lsr\Logging\Storage\FilteredStorage;
use Lsr\Otel\Logging\OtelStorage;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use stdClass;

final class OtelStorageTest extends TestCase
{
    private InMemoryExporter $exporter;
    private LoggerProviderInterface $provider;
    private OtelStorage $storage;

    protected function setUp(): void {
        $this->exporter = new InMemoryExporter();
        $this->provider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($this->exporter))
            ->build();
        $this->storage = new OtelStorage($this->provider->getLogger('lsr/logging'));
    }

    protected function tearDown(): void {
        $this->provider->shutdown();
    }

    public function test_emits_payload_with_protected_identity_and_current_span_without_global_registration(): void {
        $tracerProvider = TracerProvider::builder()->build();
        $span = $tracerProvider->getTracer('tests/logging')->spanBuilder('payment')->startSpan();
        $scope = $span->activate();
        try {
            $logger = new Logger(sys_get_temp_dir(), 'payments', $this->storage);
            $logger->warning('Payment {id} declined', [
                'id' => 42,
                'lsr.logger.name' => 'forged',
            ]);
        } finally {
            $scope->detach();
            $span->end();
            $tracerProvider->shutdown();
        }

        self::assertCount(1, $this->exporter->getStorage());
        $record = $this->record();
        self::assertSame('Payment {id} declined', $record->getBody());
        self::assertSame(Severity::WARN->value, $record->getSeverityNumber());
        self::assertSame('WARNING', $record->getSeverityText());
        self::assertSame('lsr/logging', $record->getInstrumentationScope()->getName());
        self::assertSame('payments', $record->getAttributes()->get('lsr.logger.name'));
        self::assertSame(42, $record->getAttributes()->get('id'));
        self::assertSame($span->getContext()->getTraceId(), $record->getSpanContext()?->getTraceId());
        self::assertSame($span->getContext()->getSpanId(), $record->getSpanContext()->getSpanId());
    }

    public function test_normalizes_arbitrary_context_and_exports_exception_stacktrace(): void {
        $exception = new RuntimeException('Payment failed', 17);
        $recursive = new stdClass();
        $recursive->visible = 'kept';
        $recursive->self = $recursive;
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);

        try {
            $this->storage->store('error', 'Failure', [
                'exception' => $exception,
                'object' => $recursive,
                'numbers' => [1, 2, 3],
                'mixed' => [1, 'two', null, ['three' => true]],
                'resource' => $resource,
                'infinity' => INF,
                'null' => null,
            ]);
        } finally {
            fclose($resource);
        }

        $attributes = $this->record()->getAttributes();
        self::assertSame(RuntimeException::class, $attributes->get('exception.type'));
        self::assertSame('Payment failed', $attributes->get('exception.message'));
        self::assertSame($exception->getTraceAsString(), $attributes->get('exception.stacktrace'));
        self::assertSame([1, 2, 3], $attributes->get('numbers'));
        self::assertSame('[resource stream]', $attributes->get('resource'));
        self::assertSame('[non-finite float INF]', $attributes->get('infinity'));
        self::assertArrayNotHasKey('null', $attributes->toArray());
        $object = $attributes->get('object');
        $mixed = $attributes->get('mixed');
        self::assertIsString($object);
        self::assertIsString($mixed);
        self::assertSame(
            ['visible' => 'kept', 'self' => '[recursive stdClass]'],
            json_decode($object, true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            [1, 'two', null, ['three' => true]],
            json_decode($mixed, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_exception_mapping_respects_normalized_destination_filter(): void {
        $filtered = new FilteredStorage(
            $this->storage,
            LogLevel::DEBUG,
            new ContextBlacklistFilter(['message', 'trace', 'secret']),
        );
        $filtered->storeRecord(new LogRecord('error', 'Failure', [
            'exception' => new RuntimeException('Sensitive exception message'),
            'details' => ['secret' => 'credential', 'public' => 'retained'],
        ], 'secure'));

        $attributes = $this->record()->getAttributes();
        self::assertSame('secure', $attributes->get('lsr.logger.name'));
        self::assertSame(RuntimeException::class, $attributes->get('exception.type'));
        self::assertArrayNotHasKey('exception.message', $attributes->toArray());
        self::assertArrayNotHasKey('exception.stacktrace', $attributes->toArray());
        $details = $attributes->get('details');
        self::assertIsString($details);
        self::assertSame(['public' => 'retained'], json_decode($details, true, flags: JSON_THROW_ON_ERROR));
        $exception = $attributes->get('exception');
        self::assertIsString($exception);
        self::assertArrayNotHasKey('message', json_decode($exception, true, flags: JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('trace', json_decode($exception, true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_anonymous_calls_preserve_scalar_context_but_cannot_forge_logger_identity(): void {
        $this->storage->store(LogLevel::INFO, 'Scalar context', 42);
        $this->storage->store('info', 'Untrusted identity', ['lsr.logger.name' => 'forged']);

        self::assertCount(2, $this->exporter->getStorage());
        self::assertSame(42, $this->record()->getAttributes()->get('context'));
        self::assertArrayNotHasKey('lsr.logger.name', $this->record()->getAttributes()->toArray());
        self::assertArrayNotHasKey('lsr.logger.name', $this->record(1)->getAttributes()->toArray());
    }

    public function test_rejects_invalid_level_with_psr_exception(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->storage->store('verbose', 'Invalid level');
    }

    private function record(int $index = 0): ReadableLogRecord {
        $record = $this->exporter->getStorage()->offsetGet($index);
        self::assertInstanceOf(ReadableLogRecord::class, $record);
        return $record;
    }
}
