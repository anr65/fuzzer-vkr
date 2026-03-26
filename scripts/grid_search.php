#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/experiment_common.php';

$repoRoot = dirname(__DIR__);
$resultsDir = $repoRoot . '/results';
ensureDir($resultsDir);

$target = $argv[1] ?? 'targets/yaml_target.php';
$corpus = $argv[2] ?? 'corpus_yaml';
$requestedDuration = isset($argv[3]) ? max(5, (int) $argv[3]) : 120;
$maxRuns = isset($argv[4]) ? max(1, (int) $argv[4]) : 10;
$timeBudgetSec = 30 * 60;

$operatorIds = discoverOperatorIds($repoRoot);
$baseline = loadBaselineWeights($repoRoot, $operatorIds);
$grid = [0.0, 0.25, 0.5, 1.0, 2.0];
$runs = [];

$estimatedFull = count($operatorIds) * count($grid) * $requestedDuration;
$duration = $requestedDuration;
$runtimeAdjusted = false;
if ($estimatedFull > $timeBudgetSec) {
    $duration = max(20, (int) floor($timeBudgetSec / (count($operatorIds) * count($grid))));
    $runtimeAdjusted = true;
}

$runId = 0;
foreach ($operatorIds as $operatorId) {
    foreach ($grid as $weight) {
        $runId++;
        $weights = $baseline;
        $weights[$operatorId] = $weight;

        $runDir = $resultsDir . '/grid_search/run_' . $runId;
        $runCorpus = $runDir . '/corpus';
        ensureDir($runDir);
        copyCorpus($repoRoot . '/' . $corpus, $runCorpus);

        $weightConfig = $runDir . '/weights.json';
        file_put_contents($weightConfig, json_encode([
            'uniform' => new stdClass(),
            'static_default' => $weights,
            'static_by_class' => new stdClass(),
        ], JSON_PRETTY_PRINT));

        try {
            runFuzzer([
                'fuzz',
                $target,
                $runCorpus,
                $runDir,
                $runDir . '/log.txt',
                '--seed=' . (10000 + $runId),
                '--max-runs=' . $maxRuns,
                '--max-time=' . $duration,
                '--stability-log=' . $runDir . '/stability.csv',
                '--stability-format=csv',
                '--stability-interval=200',
                '--enable-corpus-diagnostics',
                '--corpus-events-log=' . $runDir . '/events.csv',
                '--operator-policy=static',
                '--operator-weights-config=' . $weightConfig,
            ], $repoRoot);
            $metrics = extractRunMetrics($runDir . '/stability.csv');
        } catch (Throwable $e) {
            appendError($resultsDir, 'grid_search', "run_id=$runId failed: " . $e->getMessage());
            $metrics = [
                'final_coverage' => 0.0,
                'corpus_size' => 0.0,
                'new_paths_rate' => 0.0,
                'duration_sec' => 0.0,
            ];
        }

        $runs[] = [
            'run_id' => $runId,
            'operator_weights' => json_encode($weights, JSON_UNESCAPED_SLASHES),
            'final_coverage' => $metrics['final_coverage'],
            'corpus_size' => $metrics['corpus_size'],
            'new_paths_rate' => round($metrics['new_paths_rate'], 6),
            'duration_sec' => $metrics['duration_sec'],
        ];
    }
}

usort($runs, static fn(array $a, array $b): int => ((float) $b['final_coverage']) <=> ((float) $a['final_coverage']));
$top3 = array_slice($runs, 0, 3);

writeCsv($resultsDir . '/grid_search_results.csv', $runs);
file_put_contents($resultsDir . '/top3_profiles.json', json_encode($top3, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($resultsDir . '/stage_metadata_grid_search.json', json_encode([
    'requested_duration_sec' => $requestedDuration,
    'effective_duration_sec' => $duration,
    'runtime_adjusted' => $runtimeAdjusted,
    'time_budget_sec' => $timeBudgetSec,
], JSON_PRETTY_PRINT));

echo "Grid search done. Results: results/grid_search_results.csv\n";
