<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PHPUnit\Framework\TestCase;

final class StructuralMutatorSelectorTest extends TestCase {
    public function testSelectorReturnsMutatorAndParseStatus(): void {
        $selector = new StructuralMutatorSelector(new RNG());
        $result = $selector->select("a: 1\nb: true\n");

        self::assertNotSame('', $result->mutatorName);
        self::assertTrue($result->parsed);
    }

    public function testSelectorFallsBackOnInvalidYaml(): void {
        $selector = new StructuralMutatorSelector(new RNG());
        $result = $selector->select("a: [\n");
        self::assertFalse($result->parsed);
        self::assertNotSame('', $result->mutatorName);
    }
}

