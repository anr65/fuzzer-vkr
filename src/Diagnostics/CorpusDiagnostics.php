<?php declare(strict_types=1);

namespace PhpFuzzer\Diagnostics;

use PhpFuzzer\CorpusEntry;

/**
 * Handles diagnostic logging for corpus evolution analysis.
 */
final class CorpusDiagnostics {
    private bool $enabled = false;
    private string $eventsLogFile;
    private string $snapshotDir;
    private int $snapshotFrequency;
    private int $lastSnapshotRun = 0;
    private int $nextSeedId = 1;

    /** @var array<string, SeedStats> Map of input hash to seed stats */
    private array $seedStats = [];

    /** @var array<string, string> Map of input hash to seed ID */
    private array $hashToSeedId = [];

    private ?\Closure $getCurrentRun = null;
    private ?\Closure $getCurrentCoverage = null;

    public function __construct() {
    }

    public function enable(
        string $eventsLogFile,
        string $snapshotDir,
        int $snapshotFrequency,
        \Closure $getCurrentRun,
        \Closure $getCurrentCoverage
    ): void {
        $this->enabled = true;
        $this->eventsLogFile = $eventsLogFile;
        $this->snapshotDir = $snapshotDir;
        $this->snapshotFrequency = $snapshotFrequency;
        $this->getCurrentRun = $getCurrentRun;
        $this->getCurrentCoverage = $getCurrentCoverage;

        // Ensure directories exist
        $dir = dirname($eventsLogFile);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0755, true);
        }

        // Initialize events log file
        $this->initializeEventsLog();
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Register a new seed in the corpus.
     */
    public function registerSeed(
        CorpusEntry $entry,
        ?string $parentHash,
        int $coverageAtCreation,
        ?string $seedClass = null
    ): string {
        if (!$this->enabled) {
            return '';
        }

        $seedId = 'seed_' . $this->nextSeedId++;
        $originHash = $parentHash ?? $entry->hash;

        $stats = new SeedStats(
            $seedId,
            $entry->hash,
            $originHash,
            $this->getCurrentRun ? ($this->getCurrentRun)() : 0,
            \strlen($entry->input),
            $coverageAtCreation,
            $seedClass ?? 'default'
        );

        $this->seedStats[$entry->hash] = $stats;
        $this->hashToSeedId[$entry->hash] = $seedId;

        return $seedId;
    }

    /**
     * Record that a seed was selected for mutation.
     */
    public function recordSelection(?CorpusEntry $entry): ?string {
        if (!$this->enabled || $entry === null) {
            return null;
        }

        if (isset($this->seedStats[$entry->hash])) {
            $this->seedStats[$entry->hash]->recordSelection();
            return $this->hashToSeedId[$entry->hash] ?? null;
        }

        return null;
    }

    /**
     * Record that a seed contributed to new coverage.
     */
    public function recordContribution(?CorpusEntry $entry): ?string {
        if (!$this->enabled || $entry === null) {
            return null;
        }

        if (isset($this->seedStats[$entry->hash])) {
            $run = $this->getCurrentRun ? ($this->getCurrentRun)() : 0;
            $this->seedStats[$entry->hash]->recordContribution($run);
            return $this->hashToSeedId[$entry->hash] ?? null;
        }

        return null;
    }

    /**
     * Log a candidate seed that could be added to the corpus.
     */
    public function logCandidateSeed(
        CorpusEntry $entry,
        ?string $parentSeedId,
        int $coverageBefore,
        int $coverageAfter,
        string $decision,
        string $reason = '',
        ?string $operatorId = null,
        ?int $wasInteresting = null,
        ?string $seedClass = null,
        ?string $weightSnapshotId = null
    ): void {
        if (!$this->enabled) {
            return;
        }

        $run = $this->getCurrentRun ? ($this->getCurrentRun)() : 0;
        $delta = $coverageAfter - $coverageBefore;

        $line = sprintf(
            "%d,%s,%s,%d,%d,%d,%d,%s,%s,%s,%s,%s,%s\n",
            $run,
            $parentSeedId ?? '',
            $entry->hash,
            \strlen($entry->input),
            $coverageBefore,
            $coverageAfter,
            $delta,
            $decision,
            $reason,
            $operatorId ?? '',
            $wasInteresting === null ? '' : (string) $wasInteresting,
            $seedClass ?? '',
            $weightSnapshotId ?? ''
        );

        file_put_contents($this->eventsLogFile, $line, FILE_APPEND);
    }

    /**
     * Update seed stats when a seed is replaced.
     */
    public function handleSeedReplacement(CorpusEntry $oldEntry, CorpusEntry $newEntry, ?string $seedClass = null): void {
        if (!$this->enabled) {
            return;
        }

        // Transfer stats if hash changes, or mark old as replaced
        if (isset($this->seedStats[$oldEntry->hash])) {
            $oldStats = $this->seedStats[$oldEntry->hash];
            // If new entry has different hash, create new stats
            if ($newEntry->hash !== $oldEntry->hash) {
                $coverage = $this->getCurrentCoverage ? ($this->getCurrentCoverage)() : 0;
                $inheritedClass = $seedClass;
                if ($inheritedClass === null && isset($this->seedStats[$oldEntry->hash])) {
                    $inheritedClass = $this->seedStats[$oldEntry->hash]->seedClass;
                }
                $this->registerSeed($newEntry, $oldEntry->hash, $coverage, $inheritedClass);
            }
        }
    }

    /**
     * Mark seeds as dead based on heuristics (no contribution in last N runs).
     */
    public function updateDeadSeeds(int $recentRunWindow): void {
        if (!$this->enabled) {
            return;
        }

        $currentRun = $this->getCurrentRun ? ($this->getCurrentRun)() : 0;
        $thresholdRun = $currentRun - $recentRunWindow;

        foreach ($this->seedStats as $stats) {
            if ($stats->lastContributionRun === null) {
                // Never contributed
                if ($stats->creationRun < $thresholdRun) {
                    $stats->markDead();
                }
            } elseif ($stats->lastContributionRun < $thresholdRun) {
                $stats->markDead();
            }
        }
    }

    /**
     * Create a corpus snapshot.
     */
    public function createSnapshot(int $run): void {
        if (!$this->enabled) {
            return;
        }

        if ($run - $this->lastSnapshotRun < $this->snapshotFrequency) {
            return;
        }

        $this->lastSnapshotRun = $run;
        $snapshot = [
            'run' => $run,
            'timestamp' => microtime(true),
            'seeds' => [],
        ];

        foreach ($this->seedStats as $stats) {
            $snapshot['seeds'][] = $stats->toArray();
        }

        $snapshotFile = $this->snapshotDir . '/stability_corpus_snapshot_' . $run . '.json';
        file_put_contents($snapshotFile, json_encode($snapshot, JSON_PRETTY_PRINT));

        // Also update dead seeds before snapshot
        $this->updateDeadSeeds($this->snapshotFrequency);
    }

    /**
     * Get seed statistics for extended metrics.
     * @return array<string, SeedStats>
     */
    public function getAllSeedStats(): array {
        return $this->seedStats;
    }

    /**
     * Get seed ID for a given entry hash.
     */
    public function getSeedId(?string $hash): ?string {
        if (!$this->enabled || $hash === null) {
            return null;
        }
        return $this->hashToSeedId[$hash] ?? null;
    }

    private function initializeEventsLog(): void {
        $header = "run,parent_seed_id,input_hash,size,coverage_before,coverage_after,delta,decision,reason,operator_id,was_interesting,seed_class,weight_snapshot_id\n";
        file_put_contents($this->eventsLogFile, $header);
    }
}

