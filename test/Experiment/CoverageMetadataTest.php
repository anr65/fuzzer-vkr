<?php declare(strict_types=1);

namespace PhpFuzzer\Experiment;

use PhpFuzzer\CorpusEntry;
use PhpFuzzer\Fuzzer;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CoverageMetadataTest extends TestCase {
    public function testLazyFileMetadataAndBlockIdsSurviveWorkerRestart(): void {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('ext-pcntl is required');
        }

        $fuzzer = new Fuzzer();
        $timeout = new \ReflectionProperty($fuzzer, 'timeout');
        $timeout->setValue($fuzzer, 1);

        $loadTarget = new \ReflectionMethod($fuzzer, 'loadTarget');
        $loadTarget->invoke($fuzzer, dirname(__DIR__) . '/fixtures/coverage_metadata_target.php');
        $runInput = new \ReflectionMethod($fuzzer, 'runInput');

        /** @var CorpusEntry $beforeRestart */
        $beforeRestart = $runInput->invoke($fuzzer, 'covered');

        $fileInfos = new \ReflectionProperty($fuzzer, 'fileInfos');
        $subjectPath = dirname(__DIR__) . '/fixtures/coverage_metadata_subject.php';
        $metadataBeforeRestart = $fileInfos->getValue($fuzzer);
        self::assertArrayHasKey($subjectPath, $metadataBeforeRestart);
        self::assertNotSame([], $metadataBeforeRestart[$subjectPath]->blockIndexToPos);

        /** @var CorpusEntry $timeoutEntry */
        $timeoutEntry = $runInput->invoke($fuzzer, 'timeout');
        self::assertNotNull($timeoutEntry->crashInfo);

        /** @var CorpusEntry $afterRestart */
        $afterRestart = $runInput->invoke($fuzzer, 'covered');
        self::assertSame($beforeRestart->features, $afterRestart->features);

        $metadataAfterRestart = $fileInfos->getValue($fuzzer);
        self::assertSame(
            $metadataBeforeRestart[$subjectPath]->blockIndexToPos,
            $metadataAfterRestart[$subjectPath]->blockIndexToPos
        );
    }
}
