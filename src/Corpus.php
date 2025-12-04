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

    public function addEntry(CorpusEntry $entry, ?string $parentHash = null, bool $skipRegistration = false): void {
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
            $seedId = $this->diagnostics->registerSeed($entry, $parentHash, $coverageAfter);
            
            // Log candidate seed event
            $this->diagnostics->logCandidateSeed(
                $entry,
                $parentSeedId,
                $coverageBefore,
                $coverageAfter,
                'accepted',
                'new_coverage'
            );
        }
    }

    // Returns whether the new entry has been added. The old one will always be removed.
    public function replaceEntry(CorpusEntry $origEntry, CorpusEntry $newEntry): bool {
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
                    'duplicate_hash'
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
            $this->diagnostics->handleSeedReplacement($origEntry, $newEntry);
            $this->diagnostics->logCandidateSeed(
                $newEntry,
                $parentSeedId,
                $coverageBefore,
                $coverageAfter,
                'accepted',
                'minimization'
            );
        }
        
        return true;
    }

    public function getRandomEntry(RNG $rng): ?CorpusEntry {
        if (empty($this->entriesByHash)) {
            return null;
        }

        $entry = $rng->randomElement($this->entriesByIndex);
        
        // Record selection in diagnostics
        if ($this->diagnostics !== null && $entry !== null) {
            $this->diagnostics->recordSelection($entry);
        }
        
        return $entry;
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
