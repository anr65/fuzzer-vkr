<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PHPUnit\Framework\TestCase;

final class TypeAwareMutatorTest extends TestCase {
    public function testScalarMutationReturnsString(): void {
        $mutator = new TypeAwareMutator();
        $r = new \Random\Randomizer(new \Random\Engine\Mt19937(1234));

        self::assertIsString($mutator->mutateScalar('123', $r));
        self::assertIsString($mutator->mutateScalar('12.5', $r));
        self::assertIsString($mutator->mutateScalar('true', $r));
        self::assertIsString($mutator->mutateScalar('null', $r));
        self::assertIsString($mutator->mutateScalar('hello', $r));
    }
}

