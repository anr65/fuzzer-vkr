<?php declare(strict_types=1);

/**
 * Run repeated fuzzing experiments and aggregate metrics.
 *
 * Contract for input CSV fields:
 * - stability.csv columns used:
 *   - timestamp
 *   - unique_features
 *   - corpus_size
 *   - active_seeds
 *   - dead_seeds
 * - events.csv is only checked for existence to ensure corpus diagnostics were enabled.
 */
final class ExperimentRunner {
    private const DEFAULT_RUNS = 5;
    private const DEFAULT_DURATION = 300;
    private const DEFAULT_CORPUS = 'corpus_yaml';
    private const STABILITY_REQUIRED_FIELDS = [
        'timestamp',
        'unique_features',
        'corpus_size',
        'active_seeds',
        'dead_seeds',
    ];

    public function run(array $argv): int {
        $options = getopt('', ['profile:', 'runs::', 'duration::', 'target:', 'corpus::']);
        if (!isset($options['profile'], $options['target'])) {
            $this->printUsage();
            return 1;
        }

        $profile = (string) $options['profile'];
        $target = (string) $options['target'];
        $runs = isset($options['runs']) ? (int) $options['runs'] : self::DEFAULT_RUNS;
        $duration = isset($options['duration']) ? (int) $options['duration'] : self::DEFAULT_DURATION;
        $corpus = isset($options['corpus']) ? (string) $options['corpus'] : self::DEFAULT_CORPUS;

        if (!is_file($target) || !is_file($profile) || !is_dir($corpus) || $runs <= 0 || $duration <= 0) {
            fwrite(STDERR, "Invalid input arguments.\n");
            $this->printUsage();
            return 1;
        }

        $timestamp = date('Ymd_His');
        $profileName = pathinfo($profile, PATHINFO_FILENAME);
        $resultsDir = getcwd() . '/results/experiments/' . $profileName . '_' . $timestamp;
        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0755, true);
        }

        $rows = [];
        for ($i = 1; $i <= $runs; $i++) {
            $runDir = $resultsDir . '/run_' . $i;
            mkdir($runDir, 0755, true);

            $runCorpusDir = $runDir . '/corpus';
            mkdir($runCorpusDir, 0755, true);
            $this->copyDirectory($corpus, $runCorpusDir);

            $logFile = $runDir . '/log.txt';
            $stabilityFile = $runDir . '/stability.csv';
            $eventsFile = $runDir . '/events.csv';

            $command = sprintf(
                'php %s fuzz %s %s %s %s --profile=%s --max-time=%d --stability-log=%s --stability-format=csv --enable-corpus-diagnostics --corpus-events-log=%s',
                escapeshellarg(getcwd() . '/bin/php-fuzzer'),
                escapeshellarg($target),
                escapeshellarg($runCorpusDir),
                escapeshellarg($runDir),
                escapeshellarg($logFile),
                escapeshellarg($profile),
                $duration,
                escapeshellarg($stabilityFile),
                escapeshellarg($eventsFile),
            );
            exec($command);

            $metrics = $this->collectRunMetrics($stabilityFile, $eventsFile, $runCorpusDir);
            $metrics['run'] = $i;
            $rows[] = $metrics;
        }

        $outputCsv = getcwd() . '/results/' . $profileName . '_' . $timestamp . '.csv';
        $this->writeResultsCsv($outputCsv, $rows);
        $this->printSummary($rows);
        echo "Saved results to: {$outputCsv}\n";

        return 0;
    }

    private function printUsage(): void {
        echo "Usage: php scripts/run_experiment.php --profile=<path> --target=<path> [--runs=5] [--duration=300] [--corpus=corpus_yaml]\n";
    }

    /**
     * @return array<string, float|int>
     */
    private function collectRunMetrics(string $stabilityFile, string $eventsFile, string $corpusDir): array {
        if (!is_file($eventsFile)) {
            throw new RuntimeException("Missing events.csv file: {$eventsFile}");
        }
        [$header, $rows] = $this->readCsv($stabilityFile);
        $indexes = [];
        foreach (self::STABILITY_REQUIRED_FIELDS as $field) {
            $idx = array_search($field, $header, true);
            if ($idx === false) {
                throw new RuntimeException("Missing required column in stability.csv: {$field}");
            }
            $indexes[$field] = $idx;
        }

        if (empty($rows)) {
            return [
                'final_coverage' => 0,
                'final_corpus_size' => 0,
                'active_seeds' => 0,
                'dead_seeds' => 0,
                'time_to_stagnation_seconds' => 0.0,
            ];
        }

        $lastRow = $rows[count($rows) - 1];
        $lastCoverage = 0;
        $lastIncreaseTimestamp = 0.0;
        foreach ($rows as $row) {
            $coverage = (int) $row[$indexes['unique_features']];
            if ($coverage > $lastCoverage) {
                $lastCoverage = $coverage;
                $lastIncreaseTimestamp = (float) $row[$indexes['timestamp']];
            }
        }

        return [
            'final_coverage' => (int) $lastRow[$indexes['unique_features']],
            'final_corpus_size' => $this->countCorpusEntries($corpusDir, (int) $lastRow[$indexes['corpus_size']]),
            'active_seeds' => (int) $lastRow[$indexes['active_seeds']],
            'dead_seeds' => (int) $lastRow[$indexes['dead_seeds']],
            'time_to_stagnation_seconds' => $lastIncreaseTimestamp,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function readCsv(string $path): array {
        if (!is_file($path)) {
            throw new RuntimeException("Missing CSV file: {$path}");
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CSV file: {$path}");
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException("CSV file has no header: {$path}");
        }

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        return [$header, $rows];
    }

    /**
     * @param list<array<string, float|int>> $rows
     */
    private function writeResultsCsv(string $path, array $rows): void {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new RuntimeException("Failed to write results CSV: {$path}");
        }

        fputcsv($fh, ['run', 'final_coverage', 'final_corpus_size', 'active_seeds', 'dead_seeds', 'time_to_stagnation_seconds']);
        foreach ($rows as $row) {
            fputcsv($fh, [
                $row['run'],
                $row['final_coverage'],
                $row['final_corpus_size'],
                $row['active_seeds'],
                $row['dead_seeds'],
                $row['time_to_stagnation_seconds'],
            ]);
        }
        fclose($fh);
    }

    /**
     * @param list<array<string, float|int>> $rows
     */
    private function printSummary(array $rows): void {
        $metrics = ['final_coverage', 'final_corpus_size', 'active_seeds', 'dead_seeds', 'time_to_stagnation_seconds'];
        echo "metric,mean,median,stddev\n";
        foreach ($metrics as $metric) {
            $values = array_map(static fn(array $row): float => (float) $row[$metric], $rows);
            printf(
                "%s,%.4f,%.4f,%.4f\n",
                $metric,
                $this->mean($values),
                $this->median($values),
                $this->stddev($values)
            );
        }
    }

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): float {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }
        return $values[$mid];
    }

    /**
     * @param list<float> $values
     */
    private function stddev(array $values): float {
        $count = count($values);
        if ($count <= 1) {
            return 0.0;
        }
        $mean = $this->mean($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        return sqrt($variance / $count);
    }

    private function copyDirectory(string $source, string $destination): void {
        $items = scandir($source);
        if ($items === false) {
            throw new RuntimeException("Failed to read directory: {$source}");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $source . '/' . $item;
            $to = $destination . '/' . $item;
            if (is_dir($from)) {
                if (!is_dir($to)) {
                    mkdir($to, 0755, true);
                }
                $this->copyDirectory($from, $to);
                continue;
            }
            copy($from, $to);
        }
    }

    private function countCorpusEntries(string $corpusDir, int $fallback): int {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($corpusDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $count = 0;
        foreach ($it as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count > 0 ? $count : $fallback;
    }
}

$runner = new ExperimentRunner();
exit($runner->run($argv));
