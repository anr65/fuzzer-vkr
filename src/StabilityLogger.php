<?php declare(strict_types=1);

namespace PhpFuzzer;

/**
 * Logs stability and degradation metrics for the fuzzing corpus.
 * Tracks coverage over time, corpus contribution, and stagnation periods.
 */
final class StabilityLogger {
    private string $logFile;
    private ?string $graphDataFile = null;
    private string $format; // 'csv' or 'json'
    private float $startTime;
    private int $logInterval; // Log every N runs
    private int $lastLogRun = 0;
    private bool $isFirstJsonEntry = true;
    private ?string $runId = null;
    
    // Current interval tracking
    private int $intervalStartRun = 0;
    private int $intervalStartFeatures = 0;
    private float $intervalStartTime = 0.0;
    
    // Stagnation tracking
    private int $longestStagnationRuns = 0;
    private float $longestStagnationSeconds = 0.0;
    private int $currentStagnationStartRun = 0;
    private float $currentStagnationStartTime = 0.0;
    
    // Corpus entry tracking
    /** @var array<string, bool> Map of corpus entry hashes that produced new coverage in current interval */
    private array $intervalContributingEntries = [];
    
    /** @var array<string, int> Map of corpus entry hashes to last run when they contributed */
    private array $entryLastContribution = [];
    
    public function __construct(string $logFile, string $format = 'csv', int $logInterval = 1000) {
        $this->logFile = $logFile;
        $this->format = $format;
        $this->logInterval = $logInterval;
        
        // Ensure directory exists
        $dir = dirname($logFile);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Initialize log file with header
        $this->initializeLogFile();
    }

    /**
     * Set the graph data file path and run ID for deterministic experiments.
     */
    public function setGraphDataFile(string $graphDataFile, string $runId): void {
        $this->graphDataFile = $graphDataFile;
        $this->runId = $runId;
        
        // Ensure directory exists
        $dir = dirname($graphDataFile);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Initialize graph data file with header
        file_put_contents($this->graphDataFile, "run_id,timestamp_relative_seconds,executions,coverage,corpus_size\n");
    }
    
    public function start(float $startTime): void {
        $this->startTime = $startTime;
        $this->intervalStartTime = $startTime;
        $this->currentStagnationStartTime = $startTime;
    }
    
    /**
     * Record that a corpus entry contributed to new coverage.
     */
    public function recordContribution(string $entryHash, int $currentRun): void {
        $this->intervalContributingEntries[$entryHash] = true;
        $this->entryLastContribution[$entryHash] = $currentRun;
        
        // Reset stagnation tracking if we got new coverage
        if ($currentRun > $this->currentStagnationStartRun) {
            $stagnationRuns = $currentRun - $this->currentStagnationStartRun;
            $stagnationSeconds = (microtime(true) - $this->currentStagnationStartTime);
            
            if ($stagnationRuns > $this->longestStagnationRuns) {
                $this->longestStagnationRuns = $stagnationRuns;
            }
            if ($stagnationSeconds > $this->longestStagnationSeconds) {
                $this->longestStagnationSeconds = $stagnationSeconds;
            }
            
            $this->currentStagnationStartRun = $currentRun;
            $this->currentStagnationStartTime = microtime(true);
        }
    }
    
    /**
     * Log metrics if interval has elapsed.
     * @param array<string, mixed>|null $extendedMetrics
     */
    public function logIfInterval(
        int $runs,
        int $totalFeatures,
        int $corpusSize,
        ?array $extendedMetrics = null
    ): void {
        if ($runs - $this->lastLogRun < $this->logInterval) {
            return;
        }
        
        $this->logMetrics($runs, $totalFeatures, $corpusSize, $extendedMetrics);
        $this->lastLogRun = $runs;
    }
    
    /**
     * Force log current metrics (e.g., at end of fuzzing).
     * @param array<string, mixed>|null $extendedMetrics
     */
    public function logMetrics(
        int $runs,
        int $totalFeatures,
        int $corpusSize,
        ?array $extendedMetrics = null
    ): void {
        $now = microtime(true);
        $timestamp = $now - $this->startTime;
        
        // Calculate metrics for current interval
        $intervalRuns = $runs - $this->intervalStartRun;
        $intervalFeatures = $totalFeatures - $this->intervalStartFeatures;
        $intervalSeconds = $now - $this->intervalStartTime;
        
        // Calculate % of corpus entries that contributed in this interval
        $contributingCount = count($this->intervalContributingEntries);
        $contributionPercentage = $corpusSize > 0 
            ? (100.0 * $contributingCount / $corpusSize) 
            : 0.0;
        
        // Calculate current stagnation
        $currentStagnationRuns = $runs - $this->currentStagnationStartRun;
        $currentStagnationSeconds = $now - $this->currentStagnationStartTime;
        
        // Merge extended metrics if available
        $baseData = [
            'timestamp' => round($timestamp, 2),
            'runs' => $runs,
            'unique_features' => $totalFeatures,
            'corpus_size' => $corpusSize,
            'interval_runs' => $intervalRuns,
            'interval_features' => $intervalFeatures,
            'interval_seconds' => round($intervalSeconds, 2),
            'contributing_entries' => $contributingCount,
            'contribution_percentage' => round($contributionPercentage, 2),
            'longest_stagnation_runs' => $this->longestStagnationRuns,
            'longest_stagnation_seconds' => round($this->longestStagnationSeconds, 2),
            'current_stagnation_runs' => $currentStagnationRuns,
            'current_stagnation_seconds' => round($currentStagnationSeconds, 2),
        ];
        
        if ($extendedMetrics !== null) {
            $baseData = array_merge($baseData, $extendedMetrics);
        }
        
        if ($this->format === 'json') {
            $this->logJson($baseData);
        } else {
            // For CSV, we need to maintain consistent column order
            $csvData = [
                round($timestamp, 2),
                $runs,
                $totalFeatures,
                $corpusSize,
                $intervalRuns,
                $intervalFeatures,
                round($intervalSeconds, 2),
                $contributingCount,
                round($contributionPercentage, 2),
                $this->longestStagnationRuns,
                round($this->longestStagnationSeconds, 2),
                $currentStagnationRuns,
                round($currentStagnationSeconds, 2),
            ];
            
            // Append extended metrics if available
            if ($extendedMetrics !== null) {
                $csvData[] = $extendedMetrics['avg_seed_age'] ?? '';
                $csvData[] = $extendedMetrics['active_seeds'] ?? '';
                $csvData[] = $extendedMetrics['dead_seeds'] ?? '';
                $csvData[] = $extendedMetrics['dead_selection_percentage'] ?? '';
                $csvData[] = $extendedMetrics['contribution_rate'] ?? '';
            }
            
            $this->logCsv($csvData);
        }
        
        // Write to graph_data.csv if configured
        if ($this->graphDataFile !== null && $this->runId !== null) {
            $graphLine = sprintf(
                "%s,%.2f,%d,%d,%d\n",
                $this->runId,
                round($timestamp, 2),
                $runs,
                $totalFeatures,
                $corpusSize
            );
            file_put_contents($this->graphDataFile, $graphLine, FILE_APPEND);
        }
        
        // Reset interval tracking
        $this->intervalStartRun = $runs;
        $this->intervalStartFeatures = $totalFeatures;
        $this->intervalStartTime = $now;
        $this->intervalContributingEntries = [];
    }
    
    private function initializeLogFile(): void {
        if ($this->format === 'json') {
            // JSON format: array of objects
            file_put_contents($this->logFile, "[\n");
        } else {
            // CSV format: header row (with extended metrics columns)
            $header = [
                'timestamp',
                'runs',
                'unique_features',
                'corpus_size',
                'interval_runs',
                'interval_features',
                'interval_seconds',
                'contributing_entries',
                'contribution_percentage',
                'longest_stagnation_runs',
                'longest_stagnation_seconds',
                'current_stagnation_runs',
                'current_stagnation_seconds',
                'avg_seed_age',
                'active_seeds',
                'dead_seeds',
                'dead_selection_percentage',
                'contribution_rate',
            ];
            file_put_contents($this->logFile, implode(',', $header) . "\n");
        }
    }
    
    private function logCsv(array $values): void {
        $line = implode(',', array_map(function($v) {
            return is_string($v) ? '"' . str_replace('"', '""', $v) . '"' : (string)$v;
        }, $values)) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
    
    private function logJson(array $data): void {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $prefix = $this->isFirstJsonEntry ? "" : ",\n";
        $this->isFirstJsonEntry = false;
        file_put_contents($this->logFile, $prefix . $json, FILE_APPEND);
    }
    
    /**
     * Finalize log file (close JSON array, etc.)
     */
    public function finalize(): void {
        if ($this->format === 'json') {
            file_put_contents($this->logFile, "\n]\n", FILE_APPEND);
        }
    }
    
    /**
     * Get statistics about corpus entry contributions.
     * @return array<string, mixed>
     */
    public function getContributionStats(): array {
        return [
            'total_tracked_entries' => count($this->entryLastContribution),
            'entries_contributing_in_interval' => count($this->intervalContributingEntries),
        ];
    }
}

