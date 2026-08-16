<?php declare(strict_types=1);

namespace PhpFuzzer;

use PhpFuzzer\Diagnostics\CorpusDiagnostics;
use PhpFuzzer\Mutation\RNG;

/**
 * Experimental control for the memory-leak A/B test.
 *
 * This class intentionally preserves the pre-fix corpus retention behavior,
 * while keeping the current public method signatures so it can be preloaded
 * into a separate fuzzer process. It must never be used in production runs.
 */
final class Corpus {
    /** @var CorpusEntry[] */
    private array $entriesByHash = [];
    /** @var CorpusEntry[] */
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

    public function addEntry(CorpusEntry $entry, ?string $parentHash = null, bool $skipRegistration = false): bool {
        $coverageBefore = $this->getNumFeatures();
        $this->entriesByHash[$entry->hash] = $entry;
        $this->entriesByIndex[] = $entry;
        foreach ($entry->uniqueFeatures as $feature => $_) {
            $this->seenFeatures[$feature] = true;
        }
        $len = strlen($entry->input);
        $this->totalLen += $len;
        $this->maxLen = max($this->maxLen, $len);

        if ($this->diagnostics !== null && $this->diagnostics->isEnabled() && !$skipRegistration) {
            $coverageAfter = $this->getNumFeatures();
            $parentSeedId = $parentHash ? $this->diagnostics->getSeedId($parentHash) : null;
            $this->diagnostics->registerSeed($entry, $parentHash, $coverageAfter);
            $this->diagnostics->logCandidateSeed(
                $entry,
                $parentSeedId,
                $coverageBefore,
                $coverageAfter,
                'accepted',
                'new_coverage'
            );
        }

        return true;
    }

    public function replaceEntry(CorpusEntry $origEntry, CorpusEntry $newEntry): bool {
        $coverageBefore = $this->getNumFeatures();
        unset($this->entriesByHash[$origEntry->hash]);
        $this->entriesByIndex = array_values($this->entriesByHash);
        if (isset($this->entriesByHash[$newEntry->hash])) {
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
        $this->totalLen -= strlen($origEntry->input);
        $this->totalLen += strlen($newEntry->input);

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

    public function getEntryByHash(string $hash): ?CorpusEntry {
        return $this->entriesByHash[$hash] ?? null;
    }

    public function getRandomEntry(RNG $rng): ?CorpusEntry {
        if ($this->entriesByHash === []) {
            return null;
        }
        $entry = $rng->randomElement($this->entriesByIndex);
        if ($this->diagnostics !== null) {
            $this->diagnostics->recordSelection($entry);
        }
        return $entry;
    }

    /** @return list<string> */
    public function getAllSeedHashes(): array {
        return array_keys($this->entriesByHash);
    }

    public function getNumCorpusEntries(): int {
        return count($this->entriesByHash);
    }

    public function getNumFeatures(): int {
        return count($this->seenFeatures);
    }

    public function getTotalLen(): int {
        return $this->totalLen;
    }

    public function getMaxLen(): int {
        return $this->maxLen;
    }

    /** @return array<int, bool> */
    public function getSeenBlockMap(): array {
        $blocks = [];
        foreach ($this->seenFeatures as $feature => $_) {
            $blocks[$feature & ((1 << 28) - 1)] = true;
        }
        return $blocks;
    }

    public function addCrashEntry(CorpusEntry $entry): bool {
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
