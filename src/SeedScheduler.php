<?php declare(strict_types=1);

namespace PhpFuzzer;

use PhpFuzzer\Mutation\RNG;
use PhpFuzzer\Util\AtomicFile;

/**
 * Power scheduling: weighted random seed selection by usefulness score.
 */
final class SeedScheduler {
    /** @var array<string, array{times_selected: int, times_contributed: int, last_contribution_run: ?int, age: int}> */
    private array $meta = [];

    public function registerSeed(string $seedId, int $runWhenAdded): void {
        if (!isset($this->meta[$seedId])) {
            $this->meta[$seedId] = [
                'times_selected' => 0,
                'times_contributed' => 0,
                'last_contribution_run' => null,
                'age' => $runWhenAdded,
            ];
        }
    }

    /**
     * When a corpus entry is replaced by minimization, move stats from oldId to newId.
     * If newId already exists, merge: sum counts, max last contribution run, min age (earlier creation).
     */
    public function replaceSeed(string $oldId, string $newId, int $run): void {
        if ($oldId === $newId) {
            return;
        }
        if (!isset($this->meta[$oldId])) {
            $this->registerSeed($oldId, $run);
        }
        $old = $this->meta[$oldId];
        unset($this->meta[$oldId]);

        if (isset($this->meta[$newId])) {
            $n = &$this->meta[$newId];
            $n['times_selected'] += $old['times_selected'];
            $n['times_contributed'] += $old['times_contributed'];
            if ($old['last_contribution_run'] !== null) {
                $n['last_contribution_run'] = max(
                    $n['last_contribution_run'] ?? 0,
                    $old['last_contribution_run']
                );
            }
            $n['age'] = min($n['age'], $old['age']);
        } else {
            $this->meta[$newId] = $old;
        }
    }

    public function recordSelection(string $seedId, int $run): void {
        $this->registerSeed($seedId, $run);
        $this->meta[$seedId]['times_selected']++;
    }

    public function recordContribution(string $seedId, int $run): void {
        $this->registerSeed($seedId, $run);
        $this->meta[$seedId]['times_contributed']++;
        $this->meta[$seedId]['last_contribution_run'] = $run;
    }

    /**
     * @param array{times_selected: int, times_contributed: int, last_contribution_run: ?int, age: int} $seedMeta
     */
    public function computeScore(array $seedMeta, int $currentRun): float {
        $age = $seedMeta['age'];
        $lastContrib = $seedMeta['last_contribution_run'] ?? $age;
        $agePenalty = log(max(1, $currentRun - $age) + 1);
        if ($agePenalty <= 0.0) {
            $agePenalty = 1e-9;
        }
        $recencyBonus = 1.0 / (log(max(1, $currentRun - $lastContrib) + 1) + 1);
        if ($recencyBonus <= 0.0) {
            $recencyBonus = 1e-9;
        }
        return ($seedMeta['times_contributed'] + 1) / ($agePenalty * $recencyBonus);
    }

    /**
     * @param list<string> $seedIds
     */
    public function selectSeedId(RNG $rng, array $seedIds, int $currentRun): string {
        if ($seedIds === []) {
            throw new \InvalidArgumentException('empty seed list');
        }
        $weights = [];
        $total = 0.0;
        foreach ($seedIds as $id) {
            if (!isset($this->meta[$id])) {
                $this->registerSeed($id, $currentRun);
            }
            $w = $this->computeScore($this->meta[$id], $currentRun);
            $weights[$id] = $w;
            $total += $w;
        }
        $r = $rng->randomUnitFloat() * $total;
        $acc = 0.0;
        foreach ($seedIds as $id) {
            $acc += $weights[$id];
            if ($r <= $acc) {
                return $id;
            }
        }
        return $seedIds[\count($seedIds) - 1];
    }

    public function exportSeedLifecycleCsv(string $path, int $currentRun): void {
        $lines = ['seed_id,times_selected,times_contributed,last_contribution_run,age,usefulness_score'];
        $ids = array_keys($this->meta);
        sort($ids);
        foreach ($ids as $id) {
            $m = $this->meta[$id];
            $score = $this->computeScore($m, $currentRun);
            $lines[] = sprintf(
                '%s,%d,%d,%s,%d,%.8f',
                $id,
                $m['times_selected'],
                $m['times_contributed'],
                $m['last_contribution_run'] === null ? '' : (string) $m['last_contribution_run'],
                $m['age'],
                $score
            );
        }
        AtomicFile::writeString($path, implode("\n", $lines) . "\n");
    }
}
