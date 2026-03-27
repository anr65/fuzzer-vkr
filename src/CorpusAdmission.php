<?php declare(strict_types=1);

namespace PhpFuzzer;

use PhpFuzzer\Util\AtomicFile;

/**
 * Optional corpus admission policy and byte-level deduplication (sha256).
 */
final class CorpusAdmission {
    /** @var array<string, true> */
    private array $sha256Hashes = [];

    public function __construct() {
    }

    public function isDuplicate(string $input): bool {
        $h = hash('sha256', $input);
        return isset($this->sha256Hashes[$h]);
    }

    public function shouldAdmitStrict(CorpusEntry $entry): bool {
        return $entry->uniqueFeatures !== [];
    }

    public function shouldAdmitRelaxed(CorpusEntry $entry, int $minDeltaFeatures): bool {
        return \count($entry->uniqueFeatures) >= $minDeltaFeatures;
    }

    public function admit(string $input): void {
        $this->sha256Hashes[hash('sha256', $input)] = true;
    }

    public function removeHashForInput(string $input): void {
        unset($this->sha256Hashes[hash('sha256', $input)]);
    }

    public function exportAdmissionLog(string $path, int $run, string $inputHash, int $inputLen, int $deltaFeatures, string $mode, string $decision): void {
        $line = sprintf("%d,%s,%d,%d,%s,%s\n", $run, $inputHash, $inputLen, $deltaFeatures, $mode, $decision);
        AtomicFile::appendLine($path, $line);
    }
}
