<?php declare(strict_types=1);

namespace PhpFuzzer\Experiment;

use PhpFuzzer\FuzzingContext;
use PhpFuzzer\TargetWorker;
use PHPUnit\Framework\TestCase;

final class TargetWorkerTest extends TestCase {
    public function testWorkerPreservesCoverageAcrossCalls(): void {
        $worker = new TargetWorker(static function (string $input): void {
            FuzzingContext::traceBlock(101, null);
            if ($input === 'two') {
                FuzzingContext::traceBlock(202, null);
            }
        }, 1);

        $first = $worker->run('one');
        self::assertFalse($first->timedOut);
        self::assertNull($first->crashInfo);
        self::assertSame([101 => 1], $first->edgeCounts);

        $second = $worker->run('two');
        self::assertFalse($second->timedOut);
        self::assertNull($second->crashInfo);
        self::assertSame([101 => 1, 202 => 1], $second->edgeCounts);
    }

    public function testWorkerReturnsExceptionsAndRecoversAfterTimeout(): void {
        $worker = new TargetWorker(static function (string $input): void {
            if ($input === 'boom') {
                throw new \RuntimeException('boom');
            }
            if ($input === 'slow') {
                $payload = str_repeat('a', 28) . 'X';
                @preg_match('/^(a+)+$/', $payload);
                while (true) {
                }
            }
            FuzzingContext::traceBlock(303, null);
        }, 1);

        $crash = $worker->run('boom');
        self::assertFalse($crash->timedOut);
        self::assertStringContainsString('RuntimeException: boom', (string) $crash->crashInfo);

        $start = microtime(true);
        $timeout = $worker->run('slow');
        $elapsed = microtime(true) - $start;
        self::assertTrue($timeout->timedOut);
        self::assertStringContainsString('Target wall-time timeout', (string) $timeout->crashInfo);
        self::assertLessThan(3.5, $elapsed);

        $recovered = $worker->run('ok');
        self::assertFalse($recovered->timedOut);
        self::assertNull($recovered->crashInfo);
        self::assertSame([303 => 1], $recovered->edgeCounts);
    }

    public function testWorkerReturnsOnlyNewInstrumentationMetadata(): void {
        $metadata = [];
        $worker = new TargetWorker(
            static function (string $input) use (&$metadata): void {
                if ($input === 'load') {
                    $metadata['/tmp/lazy.php'] = [
                        'source_hash' => hash('sha256', '<?php lazy();'),
                        'instrumented_code' => '<?php traced_lazy();',
                        'block_index_to_pos' => [401 => 6],
                    ];
                }
                FuzzingContext::traceBlock(401, null);
            },
            1,
            static function () use (&$metadata): array {
                return $metadata;
            }
        );

        $first = $worker->run('load');
        self::assertArrayHasKey('/tmp/lazy.php', $first->instrumentedFiles);
        self::assertSame([401 => 6], $first->instrumentedFiles['/tmp/lazy.php']['block_index_to_pos']);

        $second = $worker->run('again');
        self::assertSame([], $second->instrumentedFiles);
    }
}
