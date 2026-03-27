<?php declare(strict_types=1);

/**
 * Aggregate results/*_stability.csv vs baseline; write comparison_report.json and .csv
 */

$resultsDir = dirname(__DIR__) . '/results';
$files = glob($resultsDir . '/*_stability.csv') ?: [];
$baselinePath = $resultsDir . '/baseline_stability.csv';
$byName = [];
foreach ($files as $f) {
    $base = basename($f, '_stability.csv');
    $byName[$base] = parseStabilityCsv($f);
}

if (!isset($byName['baseline'])) {
    fwrite(STDERR, "Warning: baseline_stability.csv not found; deltas will be empty.\n");
}

$baseline = $byName['baseline'] ?? null;
$intervals = [];
foreach ($byName as $name => $series) {
    $intervals[$name] = inferStabilityInterval($series['rows']);
}

$refInterval = $intervals['baseline'] ?? null;
foreach ($intervals as $name => $iv) {
    if ($refInterval !== null && $iv !== null && abs($iv - $refInterval) > 0.5) {
        fwrite(STDERR, "Warning: stability row spacing for \"$name\" (~{$iv}) differs from baseline (~{$refInterval}).\n");
    }
}

$milestones = [1000, 2500, 5000];
$report = ['baseline' => $baseline ? summarizeSeries($baseline, $milestones) : null, 'experiments' => []];

foreach ($byName as $name => $series) {
    if ($name === 'baseline') {
        continue;
    }
    $sum = summarizeSeries($series, $milestones);
    $b = $report['baseline'];
    $report['experiments'][$name] = array_merge($sum, [
        'delta_final_coverage_vs_baseline' => $b !== null
            ? ($sum['final_coverage'] - $b['final_coverage']) : null,
        'stagnation_reduction_vs_baseline' => $b !== null
            ? ($b['longest_stagnation_runs'] - $sum['longest_stagnation_runs']) : null,
    ]);
}

$jsonPath = $resultsDir . '/comparison_report.json';
$csvPath = $resultsDir . '/comparison_report.csv';
writeAtomic($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$csvLines = ['experiment,final_coverage,delta_vs_baseline,longest_stagnation,corpus_size_final'];
foreach ($report['experiments'] as $name => $e) {
    $csvLines[] = sprintf(
        '%s,%s,%s,%s,%s',
        $name,
        $e['final_coverage'] ?? '',
        $e['delta_final_coverage_vs_baseline'] ?? '',
        $e['longest_stagnation_runs'] ?? '',
        $e['corpus_size_final'] ?? ''
    );
}
writeAtomic($csvPath, implode("\n", $csvLines) . "\n");

echo str_pad('experiment', 14) . ' | ' . str_pad('final_coverage', 14) . ' | ' . str_pad('delta_vs_baseline', 16) . ' | ' . str_pad('longest_stagnation', 18) . ' | corpus_size_final' . "\n";
foreach ($report['experiments'] as $name => $e) {
    echo str_pad($name, 14) . ' | ' . str_pad((string)($e['final_coverage'] ?? ''), 14) . ' | ' . str_pad((string)($e['delta_final_coverage_vs_baseline'] ?? ''), 16) . ' | ' . str_pad((string)($e['longest_stagnation_runs'] ?? ''), 18) . ' | ' . ($e['corpus_size_final'] ?? '') . "\n";
}

function writeAtomic(string $path, string $contents): void {
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = tempnam($dir !== '' && $dir !== '.' ? $dir : sys_get_temp_dir(), 'agg');
    file_put_contents($tmp, $contents);
    rename($tmp, $path);
}

/**
 * @return array{rows: list<array<string, string>>, header: list<string>}
 */
function parseStabilityCsv(string $path): array {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false || $lines === []) {
        return ['rows' => [], 'header' => []];
    }
    $header = str_getcsv($lines[0]);
    $rows = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line);
        $row = [];
        foreach ($header as $j => $h) {
            $row[$h] = $cols[$j] ?? '';
        }
        $rows[] = $row;
    }
    return ['rows' => $rows, 'header' => $header];
}

/**
 * @param list<array<string, string>> $rows
 */
function inferStabilityInterval(array $rows): ?float {
    if (count($rows) < 2) {
        return null;
    }
    $runs = [];
    foreach ($rows as $r) {
        if (isset($r['runs']) && is_numeric($r['runs'])) {
            $runs[] = (float) $r['runs'];
        }
    }
    if (count($runs) < 2) {
        return null;
    }
    sort($runs);
    $d = [];
    for ($i = 1; $i < count($runs); $i++) {
        $d[] = $runs[$i] - $runs[$i - 1];
    }
    return array_sum($d) / count($d);
}

/**
 * @param list<int> $milestones
 * @return array<string, mixed>
 */
function summarizeSeries(array $series, array $milestones): array {
    $rows = $series['rows'];
    if ($rows === []) {
        return [
            'final_coverage' => null,
            'corpus_size_final' => null,
            'longest_stagnation_runs' => null,
            'coverage_at_milestones' => [],
            'corpus_trajectory' => [],
        ];
    }
    $last = $rows[count($rows) - 1];
    $finalCoverage = isset($last['unique_features']) ? (int) $last['unique_features'] : null;
    $corpusFinal = isset($last['corpus_size']) ? (int) $last['corpus_size'] : null;
    $stag = isset($last['longest_stagnation_runs']) ? (int) $last['longest_stagnation_runs'] : null;

    $at = [];
    foreach ($milestones as $m) {
        $at[$m] = valueAtOrBeforeRun($rows, $m, 'unique_features');
    }

    $traj = [];
    foreach ($rows as $r) {
        if (isset($r['runs'], $r['corpus_size'])) {
            $traj[] = ['runs' => (int) $r['runs'], 'corpus_size' => (int) $r['corpus_size']];
        }
    }

    return [
        'final_coverage' => $finalCoverage,
        'corpus_size_final' => $corpusFinal,
        'longest_stagnation_runs' => $stag,
        'coverage_at_milestones' => $at,
        'corpus_trajectory' => $traj,
    ];
}

/**
 * @param list<array<string, string>> $rows sorted by increasing runs in file order
 */
function valueAtOrBeforeRun(array $rows, int $milestone, string $col): ?int {
    $best = null;
    foreach ($rows as $r) {
        if (!isset($r['runs']) || !isset($r[$col])) {
            continue;
        }
        $runs = (int) $r['runs'];
        if ($runs <= $milestone) {
            $best = (int) $r[$col];
        }
    }
    return $best;
}
