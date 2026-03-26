#!/usr/bin/env php
<?php declare(strict_types=1);

$options = getopt('', [
    'weights-log::',
    'events-log::',
    'warmup::',
    'threshold::',
]);

$weightsLog = $options['weights-log'] ?? 'results/trial_100k/operator_weights.jsonl';
$eventsLog = $options['events-log'] ?? 'results/trial_100k/events.csv';
$warmup = isset($options['warmup']) ? max(0, (int) $options['warmup']) : 2000;
$threshold = isset($options['threshold']) ? max(0.0, (float) $options['threshold']) : 0.01;

if (!is_file($weightsLog)) {
    fwrite(STDERR, "Missing weights log: $weightsLog\n");
    exit(1);
}
if (!is_file($eventsLog)) {
    fwrite(STDERR, "Missing events log: $eventsLog\n");
    exit(1);
}

$weightsSummary = analyzeWeightsLog($weightsLog, $threshold);
$eventsSummary = analyzeEventsLog($eventsLog, $warmup);

$uniformEntropy = $weightsSummary['uniform_entropy'];
$finalEntropy = $weightsSummary['final_entropy'];
$entropyDropPass = $uniformEntropy > 0.0 && $finalEntropy < (0.95 * $uniformEntropy);
$divergedPass = $weightsSummary['first_non_uniform_iteration'] !== null;
$interestingAfterWarmupPass = $eventsSummary['interesting_after_warmup'] > 0;

echo "=== Trial run verification ===\n";
echo "Weights log: $weightsLog\n";
echo "Events log : $eventsLog\n";
echo "Warmup     : $warmup\n";
echo "Threshold  : $threshold\n\n";

echo "== operator_weights.jsonl ==\n";
echo "Snapshots total: {$weightsSummary['snapshots_total']}\n";
echo 'First non-uniform iteration: ' . ($weightsSummary['first_non_uniform_iteration'] ?? 'NEVER') . "\n";
echo 'Final weights: ' . formatWeightVector($weightsSummary['final_weights']) . "\n";
echo sprintf(
    "Entropy(final): %.6f | Entropy(uniform): %.6f | Ratio: %.6f\n",
    $finalEntropy,
    $uniformEntropy,
    $uniformEntropy > 0.0 ? ($finalEntropy / $uniformEntropy) : 0.0
);
echo 'Top-3 operators   : ' . formatRankedWeights($weightsSummary['top3']) . "\n";
echo 'Bottom-3 operators: ' . formatRankedWeights($weightsSummary['bottom3']) . "\n\n";

echo "== events.csv ==\n";
echo "Iterations total        : {$eventsSummary['iterations_total']}\n";
echo "Interesting total       : {$eventsSummary['interesting_total']}\n";
echo "Interesting after warmup: {$eventsSummary['interesting_after_warmup']}\n";
echo "Final coverage          : {$eventsSummary['final_coverage']}\n";
echo "Interesting by seed_class:\n";
foreach ($eventsSummary['interesting_by_seed_class'] as $seedClass => $count) {
    echo "  - $seedClass: $count\n";
}
echo "Interesting by operator_id:\n";
foreach ($eventsSummary['interesting_by_operator'] as $operatorId => $count) {
    echo "  - $operatorId: $count\n";
}
echo "\n";

echo "== success criteria ==\n";
echo '[PASS] Weights diverged               : ' . ($divergedPass ? 'yes' : 'no') . "\n";
echo '[PASS] Entropy dropped (<95% uniform) : ' . ($entropyDropPass ? 'yes' : 'no') . "\n";
echo '[PASS] Interesting after warmup > 0   : ' . ($interestingAfterWarmupPass ? 'yes' : 'no') . "\n";

if (!$divergedPass) {
    if ($eventsSummary['interesting_after_warmup'] === 0) {
        echo "Conclusion: weights stayed uniform because interesting_after_warmup == 0; target appears saturated, use another target or expand corpus.\n";
    } else {
        echo "Conclusion: interesting_after_warmup > 0 but weights stayed uniform; likely rebalance() bug, debug adaptive update path.\n";
    }
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
 *   interesting_by_seed_class:array<string,int>,
 *   interesting_by_operator:array<string,int>,
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
            'interesting_by_seed_class' => [],
            'interesting_by_operator' => [],
            'final_coverage' => 0,
        ];
    }

    $index = array_flip($header);
    $required = ['run', 'was_interesting', 'seed_class', 'operator_id', 'coverage_after'];
    foreach ($required as $name) {
        if (!array_key_exists($name, $index)) {
            throw new RuntimeException("events.csv missing required column: $name");
        }
    }

    $maxRun = 0;
    $interestingTotal = 0;
    $interestingAfterWarmup = 0;
    $interestingBySeedClass = [];
    $interestingByOperator = [];
    $finalCoverage = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $run = (int) ($row[$index['run']] ?? 0);
        $maxRun = max($maxRun, $run);
        $coverageAfter = (int) ($row[$index['coverage_after']] ?? 0);
        $finalCoverage = max($finalCoverage, $coverageAfter);

        $wasInteresting = parseBoolish($row[$index['was_interesting']] ?? '');
        if (!$wasInteresting) {
            continue;
        }

        $interestingTotal++;
        if ($run > $warmup) {
            $interestingAfterWarmup++;
        }

        $seedClass = trim((string) ($row[$index['seed_class']] ?? ''));
        $seedClass = $seedClass === '' ? '<empty>' : $seedClass;
        if (!isset($interestingBySeedClass[$seedClass])) {
            $interestingBySeedClass[$seedClass] = 0;
        }
        $interestingBySeedClass[$seedClass]++;

        $operatorId = trim((string) ($row[$index['operator_id']] ?? ''));
        $operatorId = $operatorId === '' ? '<empty>' : $operatorId;
        if (!isset($interestingByOperator[$operatorId])) {
            $interestingByOperator[$operatorId] = 0;
        }
        $interestingByOperator[$operatorId]++;
    }
    fclose($handle);

    arsort($interestingBySeedClass);
    arsort($interestingByOperator);

    return [
        'iterations_total' => $maxRun,
        'interesting_total' => $interestingTotal,
        'interesting_after_warmup' => $interestingAfterWarmup,
        'interesting_by_seed_class' => $interestingBySeedClass,
        'interesting_by_operator' => $interestingByOperator,
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
