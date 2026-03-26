<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

use PHPUnit\Framework\TestCase;

final class AdaptiveMutatorSchedulerTest extends TestCase {
    public function testRewardImprovesSelectionScore(): void {
        $scheduler = new AdaptiveMutatorScheduler();
        $pool = ['A', 'B'];

        $scheduler->recordUse('A');
        $scheduler->recordUse('B');
        $scheduler->reward('A');

        $top = $scheduler->getTopMutator(10);
        self::assertSame('A', $top);
        self::assertGreaterThanOrEqual(0.0, $scheduler->getEntropy(10));
        self::assertNotEmpty($scheduler->snapshotScores(10));
    }
}

