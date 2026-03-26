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

$validationStats = readCsv($resultsDir . '/validation_stats.csv');
if (empty($validationStats)) {
    throw new RuntimeException('Missing validation_stats.csv. Run validate_profiles.php first.');
}
usort($validationStats, static fn(array $a, array $b): int => ((float) $b['mean_coverage']) <=> ((float) $a['mean_coverage']));
$bestProfileId = (string) $validationStats[0]['profile_id'];
$validationRows = readCsv($resultsDir . '/validation_results.csv');
$bestWeights = null;
foreach ($validationRows as $row) {
    if ((string) $row['profile_id'] === $bestProfileId) {
        $decoded = json_decode((string) $row['operator_weights'], true);
        if (is_array($decoded)) {
            $bestWeights = $decoded;
            break;
        }
    }
}
if (!is_array($bestWeights)) {
    throw new RuntimeException('Failed to obtain best profile weights from validation_results.csv');
}

$operatorIds = discoverOperatorIds($repoRoot);
$baseline = loadBaselineWeights($repoRoot, $operatorIds);

$configs = [
    ['config_id' => 'baseline', 'weights' => $baseline, 'flags' => []],
    ['config_id' => 'best_weights', 'weights' => $bestWeights, 'flags' => []],
    ['config_id' => 'best_weights_cleanup', 'weights' => $bestWeights, 'flags' => ['--cleanup', '--cleanup-every=1000', '--cleanup-stale-window=5000']],
    ['config_id' => 'best_weights_power_schedule', 'weights' => $bestWeights, 'flags' => ['--power-schedule']],
];

$estimated = count($configs) * $repeats * $requestedDuration;
$duration = $requestedDuration;
$runtimeAdjusted = false;
if ($estimated > $timeBudgetSec) {
    $duration = max(30, (int) floor($timeBudgetSec / max(1, count($configs) * $repeats)));
    $runtimeAdjusted = true;
}

$rows = [];
$timeseriesRows = [];
foreach ($configs as $ci => $config) {
    for ($repeat = 1; $repeat <= $repeats; $repeat++) {
        $runId = $config['config_id'] . '_r' . $repeat;
        $runDir = $resultsDir . '/corpus_experiment/' . $runId;
        $runCorpus = $runDir . '/corpus';
        ensureDir($runDir);
        copyCorpus($repoRoot . '/' . $corpus, $runCorpus);

        $weightsPath = $runDir . '/weights.json';
        file_put_contents($weightsPath, json_encode([
            'uniform' => new stdClass(),
            'static_default' => $config['weights'],
            'static_by_class' => new stdClass(),
        ], JSON_PRETTY_PRINT));

        try {
            $args = [
                'fuzz', $target, $runCorpus, $runDir, $runDir . '/log.txt',
                '--seed=' . (30000 + $ci * 100 + $repeat),
                '--max-runs=' . $maxRuns,
                '--max-time=' . $duration,
                '--stability-log=' . $runDir . '/stability.csv',
                '--stability-format=csv',
                '--stability-interval=200',
                '--enable-corpus-diagnostics',
                '--corpus-events-log=' . $runDir . '/events.csv',
                '--operator-policy=static',
                '--operator-weights-config=' . $weightsPath,
            ];
            foreach ($config['flags'] as $flag) {
                $args[] = $flag;
            }
            runFuzzer($args, $repoRoot);
            $metrics = extractRunMetrics($runDir . '/stability.csv');
        } catch (Throwable $e) {
            appendError($resultsDir, 'corpus_experiment', "run_id=$runId failed: " . $e->getMessage());
            $metrics = [
                'final_coverage' => 0.0,
                'corpus_size' => 0.0,
                'new_paths_rate' => 0.0,
                'duration_sec' => 0.0,
                'timeseries' => [],
                'active_seeds' => 0.0,
            ];
        }

        $activeShare = $metrics['corpus_size'] > 0 ? (100.0 * $metrics['active_seeds'] / $metrics['corpus_size']) : 0.0;
        $rows[] = [
            'run_id' => $runId,
            'config_id' => $config['config_id'],
            'repeat' => $repeat,
            'final_coverage' => $metrics['final_coverage'],
            'corpus_size' => $metrics['corpus_size'],
            'active_seed_share' => round($activeShare, 4),
            'new_paths_rate' => round($metrics['new_paths_rate'], 6),
            'duration_sec' => $metrics['duration_sec'],
        ];
        foreach ($metrics['timeseries'] as $point) {
            $timeseriesRows[] = [
                'run_id' => $runId,
                'config_id' => $config['config_id'],
                'repeat' => $repeat,
                'time_sec' => $point['time_sec'],
                'coverage' => $point['coverage'],
                'corpus_size' => $point['corpus_size'],
                'active_seed_share' => $point['corpus_size'] > 0 ? round(100.0 * $point['active_seeds'] / $point['corpus_size'], 4) : 0.0,
            ];
        }
    }
}

writeCsv($resultsDir . '/corpus_experiment_results.csv', $rows);
writeCsv($resultsDir . '/corpus_timeseries.csv', $timeseriesRows);
file_put_contents($resultsDir . '/stage_metadata_corpus_experiment.json', json_encode([
    'best_profile_id' => $bestProfileId,
    'requested_duration_sec' => $requestedDuration,
    'effective_duration_sec' => $duration,
    'repeats' => $repeats,
    'runtime_adjusted' => $runtimeAdjusted,
    'time_budget_sec' => $timeBudgetSec,
], JSON_PRETTY_PRINT));

echo "Corpus experiment done. Results: results/corpus_experiment_results.csv\n";
