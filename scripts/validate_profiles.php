#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/experiment_common.php';

$repoRoot = dirname(__DIR__);
$resultsDir = $repoRoot . '/results';
ensureDir($resultsDir);

$target = $argv[1] ?? 'targets/yaml_target.php';
$corpus = $argv[2] ?? 'corpus_yaml';
$requestedDuration = isset($argv[3]) ? max(10, (int) $argv[3]) : 300;
$repeats = isset($argv[4]) ? max(1, (int) $argv[4]) : 3;
$maxRuns = isset($argv[5]) ? max(1, (int) $argv[5]) : 10;
$timeBudgetSec = 30 * 60;

$top3File = $resultsDir . '/top3_profiles.json';
if (!is_file($top3File)) {
    throw new RuntimeException("Missing $top3File. Run grid_search.php first.");
}
$top3 = json_decode((string) file_get_contents($top3File), true);
if (!is_array($top3)) {
    throw new RuntimeException('Failed to parse top3 profiles');
}

$profiles = [];
$operatorIds = discoverOperatorIds($repoRoot);
$baseline = loadBaselineWeights($repoRoot, $operatorIds);
$profiles[] = ['profile_id' => 'baseline', 'weights' => $baseline];
foreach ($top3 as $idx => $row) {
    $weights = json_decode((string) ($row['operator_weights'] ?? '{}'), true);
    if (is_array($weights)) {
        $profiles[] = ['profile_id' => 'top' . ($idx + 1), 'weights' => $weights];
    }
}

$estimated = count($profiles) * $repeats * $requestedDuration;
$duration = $requestedDuration;
$runtimeAdjusted = false;
if ($estimated > $timeBudgetSec) {
    $duration = max(30, (int) floor($timeBudgetSec / max(1, count($profiles) * $repeats)));
    $runtimeAdjusted = true;
}

$rows = [];
$timeseriesRows = [];
foreach ($profiles as $pi => $profile) {
    for ($repeat = 1; $repeat <= $repeats; $repeat++) {
        $runId = $profile['profile_id'] . '_r' . $repeat;
        $runDir = $resultsDir . '/validation/' . $runId;
        $runCorpus = $runDir . '/corpus';
        ensureDir($runDir);
        copyCorpus($repoRoot . '/' . $corpus, $runCorpus);

        $weightsPath = $runDir . '/weights.json';
        file_put_contents($weightsPath, json_encode([
            'uniform' => new stdClass(),
            'static_default' => $profile['weights'],
            'static_by_class' => new stdClass(),
        ], JSON_PRETTY_PRINT));

        try {
            runFuzzer([
                'fuzz',
                $target,
                $runCorpus,
                $runDir,
                $runDir . '/log.txt',
                '--seed=' . (20000 + $pi * 100 + $repeat),
                '--max-runs=' . $maxRuns,
                '--max-time=' . $duration,
                '--stability-log=' . $runDir . '/stability.csv',
                '--stability-format=csv',
                '--stability-interval=200',
                '--enable-corpus-diagnostics',
                '--corpus-events-log=' . $runDir . '/events.csv',
                '--operator-policy=static',
                '--operator-weights-config=' . $weightsPath,
            ], $repoRoot);
            $metrics = extractRunMetrics($runDir . '/stability.csv');
        } catch (Throwable $e) {
            appendError($resultsDir, 'validation', "run_id=$runId failed: " . $e->getMessage());
            $metrics = [
                'final_coverage' => 0.0,
                'corpus_size' => 0.0,
                'new_paths_rate' => 0.0,
                'duration_sec' => 0.0,
                'timeseries' => [],
            ];
        }

        $rows[] = [
            'run_id' => $runId,
            'profile_id' => $profile['profile_id'],
            'repeat' => $repeat,
            'operator_weights' => json_encode($profile['weights'], JSON_UNESCAPED_SLASHES),
            'final_coverage' => $metrics['final_coverage'],
            'corpus_size' => $metrics['corpus_size'],
            'new_paths_rate' => round($metrics['new_paths_rate'], 6),
            'duration_sec' => $metrics['duration_sec'],
        ];

        foreach ($metrics['timeseries'] as $point) {
            $timeseriesRows[] = [
                'run_id' => $runId,
                'profile_id' => $profile['profile_id'],
                'repeat' => $repeat,
                'time_sec' => $point['time_sec'],
                'coverage' => $point['coverage'],
                'corpus_size' => $point['corpus_size'],
            ];
        }
    }
}

writeCsv($resultsDir . '/validation_results.csv', $rows);
writeCsv($resultsDir . '/coverage_timeseries.csv', $timeseriesRows);

$byProfile = [];
foreach ($rows as $row) {
    $byProfile[$row['profile_id']][] = (float) $row['final_coverage'];
}
$stats = [];
foreach ($byProfile as $profileId => $values) {
    $stats[] = [
        'profile_id' => $profileId,
        'mean_coverage' => round(avg($values), 4),
        'std_coverage' => round(stddev($values), 4),
    ];
}
writeCsv($resultsDir . '/validation_stats.csv', $stats);
file_put_contents($resultsDir . '/stage_metadata_validation.json', json_encode([
    'requested_duration_sec' => $requestedDuration,
    'effective_duration_sec' => $duration,
    'repeats' => $repeats,
    'runtime_adjusted' => $runtimeAdjusted,
    'time_budget_sec' => $timeBudgetSec,
], JSON_PRETTY_PRINT));

echo "Validation done. Results: results/validation_results.csv\n";
