<?php declare(strict_types=1);

namespace PhpFuzzer;

use PhpFuzzer\Util\AtomicFile;

/**
 * Tracks per-mutator invocation outcomes for adaptive disabling.
 *
 * Attribution convention (mutation depth > 1): Only the **last** mutator applied in a single
 * inner mutation-depth loop may receive recordInvocation(..., true) when that loop finds new
 * coverage (non-empty unique features). All earlier mutators in the same chain receive false
 * for that outcome, even if the final execution discovered new coverage. If the final execution
 * does not gain coverage, every mutator in the chain records false.
 */
final class MutatorStats {
    private int $evalWindowSize;
    private int $reenableIntervalWindows;

    /** @var array<string, array{total_calls: int, coverage_gains: int, window: list<bool>}> */
    private array $byMutator = [];

    /** @var array<string, bool> */
    private array $allMutatorNames = [];

    public function __construct(int $evalWindowSize = 500, int $reenableIntervalWindows = 3) {
        $this->evalWindowSize = max(1, $evalWindowSize);
        $this->reenableIntervalWindows = max(1, $reenableIntervalWindows);
    }

    /**
     * Register mutator names that exist in the current profile (for export rows).
     * @param list<string> $names
     */
    public function registerMutatorNames(array $names): void {
        foreach ($names as $n) {
            $this->allMutatorNames[$n] = true;
        }
    }

    public function recordInvocation(string $mutatorName, bool $producedNewCoverage): void {
        if (!isset($this->byMutator[$mutatorName])) {
            $this->byMutator[$mutatorName] = [
                'total_calls' => 0,
                'coverage_gains' => 0,
                'window' => [],
            ];
        }
        $row = &$this->byMutator[$mutatorName];
        $row['total_calls']++;
        if ($producedNewCoverage) {
            $row['coverage_gains']++;
        }
        $row['window'][] = $producedNewCoverage;
        while (\count($row['window']) > $this->evalWindowSize) {
            array_shift($row['window']);
        }
    }

    public function gainRate(string $mutatorName): float {
        if (!isset($this->byMutator[$mutatorName])) {
            return 0.0;
        }
        $w = $this->byMutator[$mutatorName]['window'];
        if ($w === []) {
            return 0.0;
        }
        $gains = 0;
        foreach ($w as $b) {
            if ($b) {
                $gains++;
            }
        }
        return $gains / \count($w);
    }

    /**
     * Mutators with gain_rate < threshold and at least one invocation in the rolling window.
     * Mutators with an empty window are not disabled (cold start).
     *
     * @return list<string>
     */
    public function getDisabledMutators(float $threshold): array {
        $out = [];
        foreach ($this->byMutator as $name => $row) {
            if ($row['window'] === []) {
                continue;
            }
            if ($this->gainRate($name) < $threshold) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * $currentWindow is 1-based index of completed eval windows (e.g. after 500 runs with window 500, value is 1).
     * Every $reenableIntervalWindows completed windows, return true so the **next** eval window runs with all mutators enabled.
     */
    public function shouldForceReenableAll(int $currentWindow): bool {
        if ($currentWindow <= 0) {
            return false;
        }
        return $currentWindow % $this->reenableIntervalWindows === 0;
    }

    public function getEvalWindowSize(): int {
        return $this->evalWindowSize;
    }

    public function getReenableIntervalWindows(): int {
        return $this->reenableIntervalWindows;
    }

    /**
     * @return array<string, array{total_calls: int, coverage_gains: int, gain_rate: float}>
     */
    public function snapshotPerMutator(): array {
        $names = array_unique(array_merge(array_keys($this->allMutatorNames), array_keys($this->byMutator)));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            $out[$name] = [
                'total_calls' => $this->byMutator[$name]['total_calls'] ?? 0,
                'coverage_gains' => $this->byMutator[$name]['coverage_gains'] ?? 0,
                'gain_rate' => $this->gainRate($name),
            ];
        }
        return $out;
    }

    public function exportCsv(string $path): void {
        $snap = $this->snapshotPerMutator();
        $lines = ['mutator_name,total_calls,coverage_gains,gain_rate'];
        foreach ($snap as $name => $row) {
            $lines[] = sprintf(
                '%s,%d,%d,%.6f',
                $name,
                $row['total_calls'],
                $row['coverage_gains'],
                $row['gain_rate']
            );
        }
        AtomicFile::writeString($path, implode("\n", $lines) . "\n");
    }
}
