#!/usr/bin/env php
<?php declare(strict_types=1);

$warmup = 2000;
$threshold = 0.01;
$dirs = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--warmup=')) {
        $warmup = max(0, (int) substr($arg, strlen('--warmup=')));
        continue;
    }
    if (str_starts_with($arg, '--threshold=')) {
        $threshold = max(0.0, (float) substr($arg, strlen('--threshold=')));
        continue;
    }
    $dirs[] = $arg;
}

if (count($dirs) !== 3) {
    fwrite(STDERR, "Usage: php scripts/compare_decay_modes.php <reset_dir> <cumulative_dir> <multiplicative_dir> [--warmup=2000] [--threshold=0.01]\n");
    exit(1);
}

$modeNames = ['reset', 'cumulative', 'multiplicative'];
$rows = [];

foreach ($dirs as $index => $dir) {
    $mode = $modeNames[$index];
    $weightsLog = rtrim($dir, '/') . '/operator_weights.jsonl';
    $eventsLog = rtrim($dir, '/') . '/events.csv';
    if (!is_file($weightsLog) || !is_file($eventsLog)) {
        throw new RuntimeException("Missing files for mode '$mode' in $dir");
    }

    $weights = analyzeWeightsLog($weightsLog, $threshold);
    $events = analyzeEventsLog($eventsLog, $warmup);
    $ratio = $weights['uniform_entropy'] > 0.0
        ? ($weights['final_entropy'] / $weights['uniform_entropy'])
        : 0.0;

    $rows[$mode] = [
        'mode' => $mode,
        'dir' => $dir,
        'weights' => $weights,
        'events' => $events,
        'diverged' => $weights['first_non_uniform_iteration'] !== null,
        'entropy_ratio' => $ratio,
    ];
}

echo "=== Decay mode comparison ===\n";
echo "Warmup: $warmup\n";
echo "Threshold: $threshold\n\n";

foreach ($modeNames as $mode) {
    $row = $rows[$mode];
    echo "== $mode ==\n";
    echo "Result dir: {$row['dir']}\n";
    echo 'First non-uniform snapshot: ' . ($row['weights']['first_non_uniform_iteration'] ?? 'NEVER') . "\n";
    echo 'Final weights: ' . formatWeightVector($row['weights']['final_weights']) . "\n";
    echo sprintf(
        "Entropy(final): %.6f | Entropy(uniform): %.6f | Ratio: %.6f\n",
        $row['weights']['final_entropy'],
        $row['weights']['uniform_entropy'],
        $row['entropy_ratio']
    );
    echo 'Top-3: ' . formatRankedWeights($row['weights']['top3']) . "\n";
    echo 'Bottom-3: ' . formatRankedWeights($row['weights']['bottom3']) . "\n";
    echo "Interesting total: {$row['events']['interesting_total']}\n";
    echo "Interesting after warmup: {$row['events']['interesting_after_warmup']}\n";
    echo "Final coverage: {$row['events']['final_coverage']}\n\n";
}

echo "Mode            | Diverged? | Entropy ratio | Interesting | Coverage\n";
echo "----------------|-----------|---------------|-------------|--------\n";
foreach ($modeNames as $mode) {
    $row = $rows[$mode];
    $line = sprintf(
        "%-15s | %-9s | %-13s | %-11d | %-8d\n",
        $mode,
        $row['diverged'] ? 'yes' : 'no',
        sprintf('%.6f', $row['entropy_ratio']),
        $row['events']['interesting_after_warmup'],
        $row['events']['final_coverage']
    );
    echo $line;
}

echo "\nRecommendation:\n";
$cumulativeDiverged = $rows['cumulative']['diverged'];
$multiplicativeDiverged = $rows['multiplicative']['diverged'];
$anyDiverged = $rows['reset']['diverged'] || $cumulativeDiverged || $multiplicativeDiverged;

if (!$anyDiverged) {
    echo "- Ни один режим не расходится от uniform: вероятно проблема в таргете/корпусе, а не в decay.\n";
} elseif ($cumulativeDiverged && !$multiplicativeDiverged) {
    echo "- Для grid search использовать cumulative: он расходится, multiplicative — нет.\n";
} elseif ($cumulativeDiverged && $multiplicativeDiverged) {
    $better = $rows['cumulative']['entropy_ratio'] <= $rows['multiplicative']['entropy_ratio']
        ? 'cumulative'
        : 'multiplicative';
    echo "- Оба режима расходятся; для grid search использовать $better (меньший entropy ratio).\n";
} else {
    echo "- Cumulative не расходится, а multiplicative расходится; использовать multiplicative для grid search.\n";
}

/**
 * @return array{
 *   snapshots_total:int,
 *   first_non_uniform_iteration:?int,
 *   final_weights:array<string,float>,
 *   final_entropy:float,
 *   uniform_entropy:float,
 *   top3:array<string,float>,
 *   bottom3:array<string,float>
 * }
 */
function analyzeWeightsLog(string $path, float $threshold): array {
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("Failed to open $path");
    }

    $snapshotsTotal = 0;
    $firstNonUniformIteration = null;
    $finalWeights = [];

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['weights']) || !is_array($row['weights'])) {
            continue;
        }
        $weights = normalizeNumericMap($row['weights']);
        if (empty($weights)) {
            continue;
        }

        $snapshotsTotal++;
        $finalWeights = $weights;
        if ($firstNonUniformIteration === null && maxDeviationFromUniform($weights) > $threshold) {
            $firstNonUniformIteration = extractIteration($row);
        }
    }
    fclose($handle);

    $sortedDesc = $finalWeights;
    arsort($sortedDesc);
    $sortedAsc = $finalWeights;
    asort($sortedAsc);

    return [
        'snapshots_total' => $snapshotsTotal,
        'first_non_uniform_iteration' => $firstNonUniformIteration,
        'final_weights' => $finalWeights,
        'final_entropy' => shannonEntropy($finalWeights),
        'uniform_entropy' => uniformEntropy(count($finalWeights)),
        'top3' => array_slice($sortedDesc, 0, 3, true),
        'bottom3' => array_slice($sortedAsc, 0, 3, true),
    ];
}

/**
 * @return array{
 *   iterations_total:int,
 *   interesting_total:int,
 *   interesting_after_warmup:int,
 *   final_coverage:int
 * }
 */
function analyzeEventsLog(string $path, int $warmup): array {
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("Failed to open $path");
    }

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($handle);
        return [
            'iterations_total' => 0,
            'interesting_total' => 0,
            'interesting_after_warmup' => 0,
            'final_coverage' => 0,
        ];
    }

    $index = array_flip($header);
    $maxRun = 0;
    $interestingTotal = 0;
    $interestingAfterWarmup = 0;
    $finalCoverage = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $run = (int) ($row[$index['run']] ?? 0);
        $maxRun = max($maxRun, $run);
        $coverageAfter = (int) ($row[$index['coverage_after']] ?? 0);
        $finalCoverage = max($finalCoverage, $coverageAfter);
        if (!parseBoolish((string) ($row[$index['was_interesting']] ?? ''))) {
            continue;
        }
        $interestingTotal++;
        if ($run > $warmup) {
            $interestingAfterWarmup++;
        }
    }
    fclose($handle);

    return [
        'iterations_total' => $maxRun,
        'interesting_total' => $interestingTotal,
        'interesting_after_warmup' => $interestingAfterWarmup,
        'final_coverage' => $finalCoverage,
    ];
}

/**
 * @param array<mixed> $weights
 * @return array<string,float>
 */
function normalizeNumericMap(array $weights): array {
    $out = [];
    foreach ($weights as $k => $v) {
        $out[(string) $k] = (float) $v;
    }
    return $out;
}

/**
 * @param array<string,float> $weights
 */
function maxDeviationFromUniform(array $weights): float {
    $n = count($weights);
    if ($n === 0) {
        return 0.0;
    }
    $uniform = 1.0 / $n;
    $maxDev = 0.0;
    foreach ($weights as $w) {
        $dev = abs($w - $uniform);
        if ($dev > $maxDev) {
            $maxDev = $dev;
        }
    }
    return $maxDev;
}

/**
 * @param array<string,mixed> $row
 */
function extractIteration(array $row): ?int {
    if (isset($row['iteration'])) {
        return (int) $row['iteration'];
    }
    if (isset($row['run'])) {
        return (int) $row['run'];
    }
    return null;
}

/**
 * @param array<string,float> $weights
 */
function shannonEntropy(array $weights): float {
    $h = 0.0;
    foreach ($weights as $w) {
        if ($w <= 0.0) {
            continue;
        }
        $h -= $w * log($w, 2);
    }
    return $h;
}

function uniformEntropy(int $n): float {
    if ($n <= 0) {
        return 0.0;
    }
    return log((float) $n, 2);
}

function parseBoolish(string $value): bool {
    $value = strtolower(trim($value));
    return in_array($value, ['1', 'true', 'yes'], true);
}

/**
 * @param array<string,float> $weights
 */
function formatWeightVector(array $weights): string {
    if (empty($weights)) {
        return '<none>';
    }
    $parts = [];
    foreach ($weights as $operator => $weight) {
        $parts[] = $operator . '=' . sprintf('%.6f', $weight);
    }
    return implode(', ', $parts);
}

/**
 * @param array<string,float> $weights
 */
function formatRankedWeights(array $weights): string {
    if (empty($weights)) {
        return '<none>';
    }
    $parts = [];
    foreach ($weights as $operator => $weight) {
        $parts[] = $operator . ' (' . sprintf('%.6f', $weight) . ')';
    }
    return implode(', ', $parts);
}
