<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/**
 * Selected mutator with optional byte range for targeted mutation.
 */
final class StructuralSelectionResult {
    public function __construct(
        public string $mutatorName,
        public ?int $offset,
        public ?int $length,
        public bool $parsed
    ) {}
}

