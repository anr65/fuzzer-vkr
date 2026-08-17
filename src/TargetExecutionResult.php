<?php declare(strict_types=1);

namespace PhpFuzzer;

final class TargetExecutionResult {
    /**
     * @param array<int, int> $edgeCounts
     * @param array<string, array{source_hash: string, instrumented_code: string, block_index_to_pos: array<int, int>}> $instrumentedFiles
     */
    public function __construct(
        public array $edgeCounts,
        public ?string $crashInfo,
        public ?string $throwableClass = null,
        public bool $timedOut = false,
        public array $instrumentedFiles = [],
    ) {
    }
}
