<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/**
 * Runtime context for adaptive and structural mutation modes.
 */
final class MutatorContext {
    public ?int $targetOffset = null;
    public ?int $targetLength = null;
    public ?string $donorInput = null;
    public ?string $lastMutator = null;
    public bool $structuralSelectorEnabled = false;
    public bool $adaptiveSchedulerEnabled = false;
    public bool $structuralCrossOverEnabled = false;
}

