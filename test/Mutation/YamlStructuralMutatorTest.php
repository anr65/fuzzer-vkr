<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PHPUnit\Framework\TestCase;

final class YamlStructuralMutatorTest extends TestCase {
    public function testMutateIndentationSkipsFirstLine(): void {
        $rng = new RNG();
        $rng->setSeed(1);
        $mutator = new YamlStructuralMutator($rng, [
            'mutateIndentation' => 1.0,
        ]);

        $input = "root:\n  child: value\n  child2: 2";
        $result = $mutator->mutate($input);

        $inLines = explode("\n", $input);
        $outLines = explode("\n", $result);
        self::assertSame($inLines[0], $outLines[0]);
    }

    public function testMutateReturnsStringForTypicalYaml(): void {
        $rng = new RNG();
        $rng->setSeed(99);
        $mutator = new YamlStructuralMutator($rng);

        $input = "name: test\nitems:\n  - one\n  - two\nenabled: true";
        $result = $mutator->mutate($input);

        self::assertIsString($result);
        self::assertNotSame('', $result);
    }
}
