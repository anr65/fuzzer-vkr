<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/**
 * Interface for opt-in mutators used by experimental scheduling.
 */
interface MutatorInterface {
    public function mutate(string $input, \Random\Randomizer $r): string;
}

