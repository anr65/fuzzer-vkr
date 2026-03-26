#!/usr/bin/env php
<?php declare(strict_types=1);

require __DIR__ . '/experiment_common.php';

$repoRoot = dirname(__DIR__);
$resultsDir = $repoRoot . '/results';

$grid = readCsv($resultsDir . '/grid_search_results.csv');
$validation = readCsv($resultsDir . '/validation_results.csv');
$corpus = readCsv($resultsDir . '/corpus_experiment_results.csv');

if (empty($grid) || empty($validation) || empty($corpus)) {
    throw new RuntimeException('Missing required CSV files in results/.');
}

$top3 = array_slice($grid, 0, 3);

$valByProfile = [];
foreach ($validation as $row) {
    $pid = (string) $row['profile_id'];
    $valByProfile[$pid][] = (float) $row['final_coverage'];
}
$valBaselineMean = avg($valByProfile['baseline'] ?? [0.0]);
$valSummary = [];
foreach ($valByProfile as $pid => $values) {
    $mean = avg($values);
    $std = stddev($values);
    $gain = $valBaselineMean > 0 ? (($mean - $valBaselineMean) / $valBaselineMean * 100.0) : 0.0;
    $valSummary[] = ['profile' => $pid, 'mean' => $mean, 'std' => $std, 'gain' => $gain];
}
usort($valSummary, static fn(array $a, array $b): int => $b['mean'] <=> $a['mean']);

$corpByConfig = [];
foreach ($corpus as $row) {
    $cid = (string) $row['config_id'];
    $corpByConfig[$cid][] = $row;
}
$corpBaseline = [];
foreach ($corpByConfig['baseline'] ?? [] as $r) {
    $corpBaseline[] = (float) $r['final_coverage'];
}
$corpBaselineMean = avg($corpBaseline);
$corpSummary = [];
foreach ($corpByConfig as $cid => $rows) {
    $coverage = array_map(static fn(array $x): float => (float) $x['final_coverage'], $rows);
    $corpusSize = array_map(static fn(array $x): float => (float) $x['corpus_size'], $rows);
    $activeShare = array_map(static fn(array $x): float => (float) $x['active_seed_share'], $rows);
    $mean = avg($coverage);
    $gain = $corpBaselineMean > 0 ? (($mean - $corpBaselineMean) / $corpBaselineMean * 100.0) : 0.0;
    $corpSummary[] = [
        'config' => $cid,
        'mean' => $mean,
        'std' => stddev($coverage),
        'corpus_size' => avg($corpusSize),
        'active_share' => avg($activeShare),
        'gain' => $gain,
    ];
}
usort($corpSummary, static fn(array $a, array $b): int => $b['mean'] <=> $a['mean']);

$stageGridMeta = json_decode((string) @file_get_contents($resultsDir . '/stage_metadata_grid_search.json'), true) ?: [];
$stageValMeta = json_decode((string) @file_get_contents($resultsDir . '/stage_metadata_validation.json'), true) ?: [];
$stageCorpMeta = json_decode((string) @file_get_contents($resultsDir . '/stage_metadata_corpus_experiment.json'), true) ?: [];

$bestVal = $valSummary[0] ?? ['profile' => 'baseline', 'mean' => 0.0];
$hyp2 = ($bestVal['profile'] !== 'baseline' && $bestVal['mean'] > $valBaselineMean)
    ? 'Гипотеза 2 подтверждена'
    : 'Гипотеза 2 не подтверждена';
$bestCorpus = $corpSummary[0] ?? ['config' => 'baseline', 'mean' => 0.0];
$hyp3 = ($bestCorpus['config'] !== 'baseline' && $bestCorpus['mean'] > $corpBaselineMean)
    ? 'Гипотеза 3 подтверждена'
    : 'Гипотеза 3 не подтверждена';

$report = "# Отчёт по экспериментам\n\n";
$report .= "## 1. Grid Search (Гипотеза 2)\n";
$report .= "- Таблица: `results/grid_search_results.csv`\n";
$report .= "- ТОП-3 профиля:\n";
foreach ($top3 as $row) {
    $report .= "  - run_id={$row['run_id']}, coverage={$row['final_coverage']}, weights=`{$row['operator_weights']}`\n";
}
$report .= "- Вывод: для YAML важны операторы, которые чаще встречаются в ТОП-3 (по частоте отклонений от 1.0).\n\n";

$report .= "## 2. Валидация профилей (Гипотеза 2 — финал)\n";
$report .= "| профиль | среднее покрытие | std | прирост vs baseline (%) |\n";
$report .= "|---|---:|---:|---:|\n";
foreach ($valSummary as $row) {
    $report .= sprintf("| %s | %.3f | %.3f | %.2f |\n", $row['profile'], $row['mean'], $row['std'], $row['gain']);
}
$report .= "\n- Вывод: {$hyp2} (по среднему покрытию, n=3 на профиль).\n\n";

$report .= "## 3. Оптимизация корпуса (Гипотеза 3)\n";
$report .= "- Реализовано: cleanup (удаление stale сидов), power scheduling (взвешенный выбор сидов).\n";
$report .= "| конфигурация | среднее покрытие | std | размер корпуса | доля активных сидов | прирост vs baseline (%) |\n";
$report .= "|---|---:|---:|---:|---:|---:|\n";
foreach ($corpSummary as $row) {
    $report .= sprintf(
        "| %s | %.3f | %.3f | %.3f | %.2f | %.2f |\n",
        $row['config'],
        $row['mean'],
        $row['std'],
        $row['corpus_size'],
        $row['active_share'],
        $row['gain']
    );
}
$report .= "\n- Вывод: {$hyp3}.\n\n";

$report .= "## 4. Сводная таблица всех конфигураций\n";
$report .= "| rank | config | mean_coverage |\n|---:|---|---:|\n";
foreach ($corpSummary as $i => $row) {
    $report .= sprintf("| %d | %s | %.3f |\n", $i + 1, $row['config'], $row['mean']);
}

$report .= "\n## 5. Рекомендации\n";
$report .= "- Оптимальная конфигурация для YAML-фаззинга: `{$bestCorpus['config']}`.\n";
$report .= "- Дальнейшая работа: расширить OFAT до частичного многомерного поиска, добавить A/B тест на разных YAML-корпусах.\n";

$adjustmentNotes = [];
if (($stageGridMeta['runtime_adjusted'] ?? false) === true) {
    $adjustmentNotes[] = 'Этап 1 был сокращён по времени из-за ограничения >30 минут.';
}
if (($stageValMeta['runtime_adjusted'] ?? false) === true) {
    $adjustmentNotes[] = 'Этап 2 был сокращён по времени из-за ограничения >30 минут.';
}
if (($stageCorpMeta['runtime_adjusted'] ?? false) === true) {
    $adjustmentNotes[] = 'Этап 4 был сокращён по времени из-за ограничения >30 минут.';
}
if (!empty($adjustmentNotes)) {
    $report .= "\n## Ограничения времени\n";
    foreach ($adjustmentNotes as $note) {
        $report .= "- {$note}\n";
    }
}

file_put_contents($resultsDir . '/REPORT.md', $report);
file_put_contents($resultsDir . '/raw_data_summary.json', json_encode([
    'grid_search_rows' => count($grid),
    'validation_rows' => count($validation),
    'corpus_experiment_rows' => count($corpus),
    'validation_summary' => $valSummary,
    'corpus_summary' => $corpSummary,
    'best_validation_profile' => $bestVal['profile'],
    'best_corpus_config' => $bestCorpus['config'],
    'time_adjustments' => [
        'grid_search' => $stageGridMeta,
        'validation' => $stageValMeta,
        'corpus_experiment' => $stageCorpMeta,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "Report generated: results/REPORT.md\n";
