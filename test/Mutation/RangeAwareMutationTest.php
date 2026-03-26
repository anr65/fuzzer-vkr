<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PHPUnit\Framework\TestCase;

final class RangeAwareMutationTest extends TestCase {
    public function testForcedRangeKeepsPrefixUntouched(): void {
        $rng = new RNG();
        $mutator = new Mutator($rng, new Dictionary());
        $input = "prefix\nvalue: 123\nsuffix";

        $offset = strpos($input, '123');
        self::assertNotFalse($offset);
        $mutator->setForcedSelection('ChangeByte', (int) $offset, 3);
        $result = $mutator->mutate($input, 200, null);

        self::assertSame(substr($input, 0, (int) $offset), substr($result, 0, (int) $offset));
    }
}

