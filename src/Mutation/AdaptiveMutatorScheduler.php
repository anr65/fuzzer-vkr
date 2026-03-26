<?php declare(strict_types=1);

namespace PhpFuzzer\Mutation;

/**
 * UCB1 scheduler for adaptive mutator selection.
 */
final class AdaptiveMutatorScheduler {
    /** @var array<string, int> */
    private array $uses = [];
    /** @var array<string, int> */
    private array $gains = [];

    /**
     * @param list<string> $availableMutators
     */
    public function selectMutatorName(array $availableMutators, int $totalRuns): string {
        $best = null;
        $bestScore = -INF;
        $safeRuns = max(1, $totalRuns);

        foreach ($availableMutators as $name) {
            $score = $this->score($name, $safeRuns);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }

        return $best ?? $availableMutators[0];
    }

    public function recordUse(string $mutator): void {
        $this->uses[$mutator] = ($this->uses[$mutator] ?? 0) + 1;
    }

    public function reward(string $mutator): void {
        $this->gains[$mutator] = ($this->gains[$mutator] ?? 0) + 1;
    }

    /**
     * @return array<string, array{uses:int,gains:int,score:float,rate:float}>
     */
    public function snapshotScores(int $totalRuns): array {
        $snapshot = [];
        $mutators = array_keys($this->uses + $this->gains);
        foreach ($mutators as $name) {
            $uses = $this->uses[$name] ?? 0;
            $gains = $this->gains[$name] ?? 0;
            $snapshot[$name] = [
                'uses' => $uses,
                'gains' => $gains,
                'score' => $this->score($name, max(1, $totalRuns)),
                'rate' => ($uses > 0 ? $gains / $uses : 0.0),
            ];
        }
        return $snapshot;
    }

    public function getTopMutator(int $totalRuns): ?string {
        $snapshot = $this->snapshotScores($totalRuns);
        if ($snapshot === []) {
            return null;
        }
        uasort($snapshot, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return (string) array_key_first($snapshot);
    }

    public function getEntropy(int $totalRuns): float {
        $snapshot = $this->snapshotScores($totalRuns);
        if ($snapshot === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($snapshot as $data) {
            $sum += max(0.0, (float) $data['score']);
        }
        if ($sum <= 0.0) {
            return 0.0;
        }
        $entropy = 0.0;
        foreach ($snapshot as $data) {
            $p = max(0.0, (float) $data['score']) / $sum;
            if ($p > 0.0) {
                $entropy -= $p * log($p, 2);
            }
        }
        return $entropy;
    }

    private function score(string $name, int $totalRuns): float {
        $uses = $this->uses[$name] ?? 0;
        $gains = $this->gains[$name] ?? 0;
        $smoothedUses = $uses + 1;
        $smoothedGains = $gains + 1;
        $exploit = $smoothedGains / $smoothedUses;
        $explore = sqrt(2.0 * log(max(1, $totalRuns)) / $smoothedUses);
        return $exploit + $explore;
    }
}

