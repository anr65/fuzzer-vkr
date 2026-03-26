<?php declare(strict_types=1);

namespace PhpFuzzer\Diagnostics;

/**
 * Tracks lifecycle statistics for a single seed in the corpus.
 */
final class SeedStats {
    public string $seedId;
    public string $originHash; // Hash of the input that created this seed
    public int $creationRun;
    public int $timesSelected = 0;
    public int $timesContributed = 0;
    public ?int $lastContributionRun = null;
    public bool $isDead = false;
    public int $coverageAtCreation = 0;
    public int $size;
    public string $inputHash;
    public string $seedClass;

    public function __construct(
        string $seedId,
        string $inputHash,
        string $originHash,
        int $creationRun,
        int $size,
        int $coverageAtCreation,
        string $seedClass = 'default'
    ) {
        $this->seedId = $seedId;
        $this->inputHash = $inputHash;
        $this->originHash = $originHash;
        $this->creationRun = $creationRun;
        $this->size = $size;
        $this->coverageAtCreation = $coverageAtCreation;
        $this->seedClass = $seedClass;
    }

    /**
     * Mark this seed as selected for mutation.
     */
    public function recordSelection(): void {
        $this->timesSelected++;
    }

    /**
     * Mark this seed as having contributed to new coverage.
     */
    public function recordContribution(int $run): void {
        $this->timesContributed++;
        $this->lastContributionRun = $run;
        $this->isDead = false; // Reset dead status if it contributed
    }

    /**
     * Mark seed as dead (no contributions in recent runs).
     */
    public function markDead(): void {
        $this->isDead = true;
    }

    /**
     * Convert to array for JSON serialization.
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'seed_id' => $this->seedId,
            'input_hash' => $this->inputHash,
            'origin_hash' => $this->originHash,
            'creation_run' => $this->creationRun,
            'times_selected' => $this->timesSelected,
            'times_contributed' => $this->timesContributed,
            'last_contribution_run' => $this->lastContributionRun,
            'is_dead' => $this->isDead,
            'coverage_at_creation' => $this->coverageAtCreation,
            'size' => $this->size,
            'seed_class' => $this->seedClass,
        ];
    }
}

