<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PHPUnit\Framework\TestCase;

final class MutatorProfileSelectionTest extends TestCase {
    public function testFlatWeightedProfileIsAccepted(): void {
        $rng = new RNG();
        $rng->setSeed(1234);
        $mutator = new Mutator($rng, new Dictionary(), [
            'ChangeByte' => 1.0,
        ]);

        $result = $mutator->mutate("abc", 16, null);
        self::assertIsString($result);
        self::assertLessThanOrEqual(16, strlen($result));
    }

    public function testTwoLevelWeightedProfileIsAccepted(): void {
        $rng = new RNG();
        $rng->setSeed(7);
        $mutator = new Mutator($rng, new Dictionary(), [
            'byte_mutations' => [
                'weight' => 1.0,
                'operators' => [
                    'ChangeByte' => 1.0,
                ],
            ],
        ]);

        $result = $mutator->mutate("abc", 16, null);
        self::assertIsString($result);
        self::assertLessThanOrEqual(16, strlen($result));
    }

    public function testInvalidProfileFallsBackToDefaultMutators(): void {
        $rng = new RNG();
        $mutator = new Mutator($rng, new Dictionary(), [
            'unknown' => ['weight' => 0, 'operators' => []],
        ]);

        self::assertNotEmpty($mutator->getMutators());
    }

    public function testYamlStructuralOperatorAvailableByName(): void {
        $rng = new RNG();
        $mutator = new Mutator($rng, new Dictionary());

        self::assertContains('yaml_structural', $mutator->getAvailableMutatorNames());
    }

    public function testYamlStructuralOperatorCanBeSelectedViaProfile(): void {
        $rng = new RNG();
        $rng->setSeed(15);
        $mutator = new Mutator($rng, new Dictionary(), [
            'yaml_structural' => 1.0,
        ]);

        $input = "root:\n  item: 1";
        $result = $mutator->mutate($input, 128, null);
        self::assertIsString($result);
    }
}
