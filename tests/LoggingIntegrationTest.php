<?php

declare(strict_types=1);

namespace Tests;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LoggingIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable {
        yield 'inject' => ['inject'];
        yield 'export' => ['export'];
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('modes')]
    public function testPsr3Integration(string $mode): void {
        if (!extension_loaded('opentelemetry')) {
            self::markTestSkipped('The ext-opentelemetry extension is required.');
        }

        $result = $this->runFixture($mode);

        self::assertStringContainsString('PSR-3 integration message', $result['file']);

        if ($mode === 'inject') {
            self::assertSame(0, $result['exportedCount']);
            self::assertStringContainsString($result['traceId'], $result['file']);
            self::assertStringContainsString($result['spanId'], $result['file']);
            return;
        }

        self::assertSame(1, $result['exportedCount']);
        self::assertSame('PSR-3 integration message', $result['exportedBody']);
        self::assertSame($result['traceId'], $result['exportedTraceId']);
        self::assertSame($result['spanId'], $result['exportedSpanId']);
    }

    /**
     * @return array{
     *     file: string,
     *     traceId: string,
     *     spanId: string,
     *     exportedCount: int,
     *     exportedBody: ?string,
     *     exportedTraceId: ?string,
     *     exportedSpanId: ?string
     * }
     * @throws JsonException
     */
    private function runFixture(string $mode): array {
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'display_startup_errors=0',
                '-d',
                'display_errors=stderr',
                __DIR__ . '/Fixtures/psr3_integration.php',
                $mode,
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PSR-3 integration fixture.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);

        /** @var array{
         *     file: string,
         *     traceId: string,
         *     spanId: string,
         *     exportedCount: int,
         *     exportedBody: ?string,
         *     exportedTraceId: ?string,
         *     exportedSpanId: ?string
         * } $result
         */
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        return $result;
    }
}
