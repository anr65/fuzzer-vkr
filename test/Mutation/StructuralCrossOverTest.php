<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PhpFuzzer\Corpus;
use PHPUnit\Framework\TestCase;

final class StructuralCrossOverTest extends TestCase {
    public function testFallbackWithoutDonorReturnsInput(): void {
        $corpus = new Corpus();
        $mutator = new StructuralCrossOver(
            $corpus,
            new RNG(),
            static fn(string $a, string $b): string => $a
        );
        $r = new \Random\Randomizer(new \Random\Engine\Mt19937(1));
        $input = "k: 1\n";
        self::assertSame($input, $mutator->mutate($input, $r));
    }
}

