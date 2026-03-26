<?php declare(strict_types=1);

namespace PhpFuzzer;

use PhpFuzzer\Diagnostics\CorpusDiagnostics;
use PhpFuzzer\Mutation\RNG;

final class Corpus {
    /** @var CorpusEntry[] */
    private array $entriesByHash = [];
    /** @var CorpusEntry[] Only used to get a random element. */
    private array $entriesByIndex = [];
    /** @var array<int, bool> */
    private array $seenFeatures = [];

    /** @var array<int, bool> */
    private array $seenCrashFeatures = [];

    private int $totalLen = 0;
    private int $maxLen = 0;
    
    private ?CorpusDiagnostics $diagnostics = null;
    /** @var array<string, bool> */
    private array $inactiveHashes = [];

    public function setDiagnostics(?CorpusDiagnostics $diagnostics): void {
        $this->diagnostics = $diagnostics;
    }

    public function computeUniqueFeatures(CorpusEntry $entry): void {
        $entry->uniqueFeatures = [];
        foreach ($entry->features as $feature => $_) {
            if (!isset($this->seenFeatures[$feature])) {
                $entry->uniqueFeatures[$feature] = true;
            }
        }
    }

    public function addEntry(
        CorpusEntry $entry,
        ?string $parentHash = null,
        bool $skipRegistration = false,
        ?string $operatorId = null,
        ?int $wasInteresting = null,
        ?string $seedClass = null,
        ?string $weightSnapshotId = null
    ): void {
        $coverageBefore = $this->getNumFeatures();
        $this->entriesByHash[$entry->hash] = $entry;
        $this->entriesByIndex[] = $entry;
        foreach ($entry->uniqueFeatures as $feature => $_) {
            $this->seenFeatures[$feature] = true;
        }
        $len = \strlen($entry->input);
        $this->totalLen += $len;
        $this->maxLen = max($this->maxLen, $len);
        
        // Register seed in diagnostics (unless already registered during corpus loading)
        if ($this->diagnostics !== null && $this->diagnostics->isEnabled() && !$skipRegistration) {
            $coverageAfter = $this->getNumFeatures();
            $parentSeedId = $parentHash ? $this->diagnostics->getSeedId($parentHash) : null;
            $seedId = $this->diagnostics->registerSeed($entry, $parentHash, $coverageAfter, $seedClass);
            
            // Log candidate seed event
            $this->diagnostics->logCandidateSeed(
                $entry,
                $parentSeedId,
                $coverageBefore,
                $coverageAfter,
                'accepted',
                'new_coverage',
                $operatorId,
                $wasInteresting,
                $seedClass,
                $weightSnapshotId
            );
        }
    }

    // Returns whether the new entry has been added. The old one will always be removed.
    public function replaceEntry(
        CorpusEntry $origEntry,
        CorpusEntry $newEntry,
        ?string $operatorId = null,
        ?int $wasInteresting = null,
        ?string $seedClass = null,
        ?string $weightSnapshotId = null
    ): bool {
        $coverageBefore = $this->getNumFeatures();
        unset($this->entriesByHash[$origEntry->hash]);
        $this->entriesByIndex = array_values($this->entriesByHash); // TODO optimize
        if (isset($this->entriesByHash[$newEntry->hash])) {
            // The new entry is already part of the corpus, nothing to do.
            if ($this->diagnostics !== null && $this->diagnostics->isEnabled()) {
                $parentSeedId = $this->diagnostics->getSeedId($origEntry->hash);
                $this->diagnostics->logCandidateSeed(
                    $newEntry,
                    $parentSeedId,
                    $coverageBefore,
                    $this->getNumFeatures(),
                    'rejected',
                    'duplicate_hash',
                    $operatorId,
                    $wasInteresting,
                    $seedClass,
                    $weightSnapshotId
                );
            }
            return false;
        }

        $this->entriesByHash[$newEntry->hash] = $newEntry;
        $this->entriesByIndex[] = $newEntry;
        $this->totalLen -= \strlen($origEntry->input);
        $this->totalLen += \strlen($newEntry->input);
        
        // Handle seed replacement in diagnostics
        if ($this->diagnostics !== null && $this->diagnostics->isEnabled()) {
            $coverageAfter = $this->getNumFeatures();
            $parentSeedId = $this->diagnostics->getSeedId($origEntry->hash);
            $this->diagnostics->handleSeedReplacement($origEntry, $newEntry, $seedClass);
            $this->diagnostics->logCandidateSeed(
                $newEntry,
                $parentSeedId,
                $coverageBefore,
                $coverageAfter,
                'accepted',
                'minimization',
                $operatorId,
                $wasInteresting,
                $seedClass,
                $weightSnapshotId
            );
        }
        
        return true;
    }

    public function getRandomEntry(RNG $rng): ?CorpusEntry {
        if (empty($this->entriesByHash)) {
            return null;
        }

        $activeEntries = $this->getActiveEntries();
        if (empty($activeEntries)) {
            return null;
        }
        $entry = $rng->randomElement($activeEntries);
        
        // Record selection in diagnostics
        if ($this->diagnostics !== null && $entry !== null) {
            $this->diagnostics->recordSelection($entry);
        }
        
        return $entry;
    }

    /**
     * @param callable(CorpusEntry): float $weightProvider
     */
    public function getWeightedRandomEntry(RNG $rng, callable $weightProvider): ?CorpusEntry {
        $activeEntries = $this->getActiveEntries();
        if (empty($activeEntries)) {
            return null;
        }

        $weights = [];
        $total = 0.0;
        foreach ($activeEntries as $entry) {
            $weight = max(0.0, (float) $weightProvider($entry));
            $weights[] = $weight;
            $total += $weight;
        }

        if ($total <= 0.0) {
            $entry = $rng->randomElement($activeEntries);
            if ($this->diagnostics !== null && $entry !== null) {
                $this->diagnostics->recordSelection($entry);
            }
            return $entry;
        }

        $threshold = ($rng->randomInt(1000000) / 1000000.0) * $total;
        $acc = 0.0;
        foreach ($activeEntries as $idx => $entry) {
            $acc += $weights[$idx];
            if ($threshold <= $acc) {
                if ($this->diagnostics !== null) {
                    $this->diagnostics->recordSelection($entry);
                }
                return $entry;
            }
        }

        $last = $activeEntries[count($activeEntries) - 1];
        if ($this->diagnostics !== null) {
            $this->diagnostics->recordSelection($last);
        }
        return $last;
    }

    /**
     * @return list<CorpusEntry>
     */
    public function getEntries(): array {
        return $this->entriesByIndex;
    }

    /**
     * @param list<string> $hashes
     */
    public function removeEntriesByHashes(array $hashes, bool $removeFiles = false): int {
        if (empty($hashes)) {
            return 0;
        }
        $removed = 0;
        foreach ($hashes as $hash) {
            if (!isset($this->entriesByHash[$hash])) {
                continue;
            }
            $entry = $this->entriesByHash[$hash];
            unset($this->entriesByHash[$hash], $this->inactiveHashes[$hash]);
            $this->totalLen -= \strlen($entry->input);
            $removed++;

            if ($removeFiles && $entry->path !== null && is_file($entry->path)) {
                @unlink($entry->path);
            }
        }
        if ($removed > 0) {
            $this->entriesByIndex = array_values($this->entriesByHash);
            $this->maxLen = 0;
            foreach ($this->entriesByIndex as $entry) {
                $this->maxLen = max($this->maxLen, \strlen($entry->input));
            }
        }
        return $removed;
    }

    /**
     * @param list<string> $hashes
     */
    public function markInactiveByHashes(array $hashes): void {
        foreach ($hashes as $hash) {
            $this->inactiveHashes[$hash] = true;
        }
    }

    private function getActiveEntries(): array {
        if (empty($this->inactiveHashes)) {
            return $this->entriesByIndex;
        }
        $active = [];
        foreach ($this->entriesByIndex as $entry) {
            if (!isset($this->inactiveHashes[$entry->hash])) {
                $active[] = $entry;
            }
        }
        return $active;
    }

    public function getNumCorpusEntries(): int {
        return \count($this->entriesByHash);
    }

    public function getNumFeatures(): int {
        return \count($this->seenFeatures);
    }

    public function getTotalLen(): int {
        return $this->totalLen;
    }

    public function getMaxLen(): int {
        return $this->maxLen;
    }

    /**
     * @return array<int, bool>
     */
    public function getSeenBlockMap(): array {
        $blocks = [];
        foreach ($this->seenFeatures as $feature => $_) {
            $targetBlock = $feature & ((1 << 28) - 1);
            $blocks[$targetBlock] = true;
        }
        return $blocks;
    }

    public function addCrashEntry(CorpusEntry $entry): bool {
        // TODO: Also handle "absent feature"?
        $hasNewFeature = false;
        foreach ($entry->features as $feature => $_) {
            if (!isset($this->seenCrashFeatures[$feature])) {
                $hasNewFeature = true;
                $this->seenCrashFeatures[$feature] = true;
            }
        }
        return $hasNewFeature;
    }
}
