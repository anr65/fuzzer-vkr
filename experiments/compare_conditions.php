#!/usr/bin/env php
<?php declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php experiments/compare_conditions.php OUTPUT_BASE\n");
    exit(1);
}

$base = $argv[1];
$conditions = ['baseline', 'structural', 'type_aware', 'str_crossover', 'adaptive'];

echo "| Condition | Final Coverage | Corpus Size | Max Stagnation | Top Mutator | Coverage/Second | Coverage/1000Runs |\n";
echo "|---|---:|---:|---:|---|---:|---:|\n";

foreach ($conditions as $condition) {
    $csv = $base . '/' . $condition . '/stability.csv';
    if (!is_file($csv)) {
        echo "| {$condition} | n/a | n/a | n/a | n/a | n/a | n/a |\n";
        continue;
    }

    $rows = array_map('str_getcsv', file($csv));
    if (count($rows) < 2) {
        echo "| {$condition} | n/a | n/a | n/a | n/a | n/a | n/a |\n";
        continue;
    }

    $header = $rows[0];
    $last = $rows[count($rows) - 1];
    $firstData = $rows[1];
    $idx = static fn(string $name): ?int => array_search($name, $header, true) !== false ? (int) array_search($name, $header, true) : null;

    $coverageIdx = $idx('unique_features');
    $runsIdx = $idx('runs');
    $timeIdx = $idx('timestamp');
    $corpusIdx = $idx('corpus_size');
    $stagnationIdx = $idx('longest_stagnation_runs');
    $topMutatorIdx = $idx('active_top_mutator');

    $finalCoverage = $coverageIdx !== null ? (int) ($last[$coverageIdx] ?? 0) : 0;
    $finalRuns = $runsIdx !== null ? (int) ($last[$runsIdx] ?? 0) : 0;
    $finalTime = $timeIdx !== null ? (float) ($last[$timeIdx] ?? 0.0) : 0.0;
    $startCoverage = $coverageIdx !== null ? (int) ($firstData[$coverageIdx] ?? 0) : 0;
    $coverageDelta = max(0, $finalCoverage - $startCoverage);
    $covPerSec = $finalTime > 0 ? round($coverageDelta / $finalTime, 4) : 0.0;
    $covPer1k = $finalRuns > 0 ? round(($coverageDelta / $finalRuns) * 1000.0, 4) : 0.0;
    $corpusSize = $corpusIdx !== null ? (int) ($last[$corpusIdx] ?? 0) : 0;
    $maxStagnation = $stagnationIdx !== null ? (int) ($last[$stagnationIdx] ?? 0) : 0;
    $topMutator = $topMutatorIdx !== null ? (string) ($last[$topMutatorIdx] ?? '') : '';
    if ($topMutator === '') {
        $topMutator = '-';
    }

    echo sprintf(
        "| %s | %d | %d | %d | %s | %.4f | %.4f |\n",
        $condition,
        $finalCoverage,
        $corpusSize,
        $maxStagnation,
        $topMutator,
        $covPerSec,
        $covPer1k
    );
}

