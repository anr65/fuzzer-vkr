#!/usr/bin/env php
<?php declare(strict_types=1);

$options = getopt('', ['input-dir::', 'output::', 'bootstrap::', 'target-percent::']);
$inputDir = $options['input-dir'] ?? 'operator_policy_runs';
$outputFile = $options['output'] ?? 'operator_policy_summary.csv';
$bootstrapIters = isset($options['bootstrap']) ? max(100, (int) $options['bootstrap']) : 2000;
$targetPercent = isset($options['target-percent']) ? (float) $options['target-percent'] : 0.9;

if (!is_dir($inputDir)) {
    fwrite(STDERR, "Input directory does not exist: $inputDir\n");
    exit(1);
}

$stabilityFiles = glob($inputDir . '/*/repeat_*/stability.csv');
if (empty($stabilityFiles)) {
    fwrite(STDERR, "No stability.csv files found in $inputDir\n");
    exit(1);
}

$runs = [];
$globalMaxCoverage = 0;

foreach ($stabilityFiles as $stabilityFile) {
    if (!preg_match('#/([^/]+)/repeat_([^/]+)/stability\.csv$#', $stabilityFile, $m)) {
        continue;
    }
    $mode = $m[1];
    $repeat = $m[2];
    $dir = dirname($stabilityFile);
    $weightsFile = $dir . '/operator_weights.jsonl';

    $rows = readCsvRows($stabilityFile);
    if (count($rows) === 0) {
        continue;
    }

    $last = $rows[count($rows) - 1];
    $finalCoverage = (int) ($last['unique_features'] ?? 0);
    $finalCorpus = (int) ($last['corpus_size'] ?? 0);
    $globalMaxCoverage = max($globalMaxCoverage, $finalCoverage);

    $runs[] = [
        'mode' => $mode,
        'repeat' => (int) $repeat,
        'final_coverage' => $finalCoverage,
        'final_corpus_size' => $finalCorpus,
        'timeline' => $rows,
        'weights_file' => $weightsFile,
    ];
}

$targetCoverage = (int) floor($globalMaxCoverage * $targetPercent);
foreach ($runs as &$run) {
    $run['time_to_target'] = computeTimeToCoverage($run['timeline'], $targetCoverage);
    $run['avg_weight_entropy'] = computeAverageWeightEntropy($run['weights_file']);
}
unset($run);

$byMode = [];
foreach ($runs as $run) {
    $byMode[$run['mode']][] = $run;
}

$summaryRows = [];
foreach ($byMode as $mode => $modeRuns) {
    $coverageValues = array_map(static fn(array $r): float => (float) $r['final_coverage'], $modeRuns);
    $corpusValues = array_map(static fn(array $r): float => (float) $r['final_corpus_size'], $modeRuns);
    $timeValues = array_map(static fn(array $r): float => (float) $r['time_to_target'], $modeRuns);
    $entropyValues = array_map(static fn(array $r): float => (float) $r['avg_weight_entropy'], $modeRuns);

    $summaryRows[$mode] = [
        'mode' => $mode,
        'runs' => count($modeRuns),
        'final_coverage_mean' => round(avg($coverageValues), 2),
        'final_corpus_mean' => round(avg($corpusValues), 2),
        'time_to_target_mean' => round(avg($timeValues), 2),
        'weight_entropy_mean' => round(avg($entropyValues), 4),
        'target_coverage' => $targetCoverage,
    ];
}

$referenceMode = 'uniform';
if (isset($byMode[$referenceMode])) {
    $ref = array_map(static fn(array $r): float => (float) $r['final_coverage'], $byMode[$referenceMode]);
    foreach ($summaryRows as $mode => &$row) {
        if ($mode === $referenceMode) {
            $row['delta_vs_uniform_mean'] = 0.0;
            $row['delta_vs_uniform_ci_low'] = 0.0;
            $row['delta_vs_uniform_ci_high'] = 0.0;
            $row['mann_whitney_u'] = '';
            continue;
        }
        $cur = array_map(static fn(array $r): float => (float) $r['final_coverage'], $byMode[$mode]);
        $ci = bootstrapDiffCI($cur, $ref, $bootstrapIters);
        $row['delta_vs_uniform_mean'] = round(avg($cur) - avg($ref), 2);
        $row['delta_vs_uniform_ci_low'] = round($ci['low'], 2);
        $row['delta_vs_uniform_ci_high'] = round($ci['high'], 2);
        $row['mann_whitney_u'] = round(mannWhitneyU($cur, $ref), 2);
    }
    unset($row);
}

writeSummary($outputFile, array_values($summaryRows));
echo "Wrote summary: $outputFile\n";

function readCsvRows(string $path): array {
    $h = fopen($path, 'r');
    if ($h === false) {
        return [];
    }
    $header = fgetcsv($h);
    if ($header === false) {
        fclose($h);
        return [];
    }
    $rows = [];
    while (($row = fgetcsv($h)) !== false) {
        if (count($row) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $row);
    }
    fclose($h);
    return $rows;
}

function computeTimeToCoverage(array $rows, int $targetCoverage): float {
    foreach ($rows as $row) {
        $coverage = (int) ($row['unique_features'] ?? 0);
        if ($coverage >= $targetCoverage) {
            return (float) ($row['timestamp'] ?? 0.0);
        }
    }
    $last = $rows[count($rows) - 1] ?? ['timestamp' => 0.0];
    return (float) $last['timestamp'];
}

function computeAverageWeightEntropy(string $path): float {
    if (!is_file($path)) {
        return 0.0;
    }
    $h = fopen($path, 'r');
    if ($h === false) {
        return 0.0;
    }
    $entropies = [];
    while (($line = fgets($h)) !== false) {
        $obj = json_decode(trim($line), true);
        if (!is_array($obj) || !isset($obj['weights']) || !is_array($obj['weights'])) {
            continue;
        }
        $entropies[] = entropy($obj['weights']);
    }
    fclose($h);
    if (empty($entropies)) {
        return 0.0;
    }
    return avg($entropies);
}

function entropy(array $weights): float {
    $h = 0.0;
    foreach ($weights as $w) {
        $p = (float) $w;
        if ($p <= 0.0) {
            continue;
        }
        $h -= $p * log($p, 2);
    }
    return $h;
}

function avg(array $values): float {
    if (empty($values)) {
        return 0.0;
    }
    return array_sum($values) / count($values);
}

function bootstrapDiffCI(array $sampleA, array $sampleB, int $iters): array {
    $diffs = [];
    $nA = count($sampleA);
    $nB = count($sampleB);
    if ($nA === 0 || $nB === 0) {
        return ['low' => 0.0, 'high' => 0.0];
    }
    for ($i = 0; $i < $iters; $i++) {
        $resampleA = [];
        $resampleB = [];
        for ($j = 0; $j < $nA; $j++) {
            $resampleA[] = $sampleA[random_int(0, $nA - 1)];
        }
        for ($j = 0; $j < $nB; $j++) {
            $resampleB[] = $sampleB[random_int(0, $nB - 1)];
        }
        $diffs[] = avg($resampleA) - avg($resampleB);
    }
    sort($diffs);
    $lowIdx = (int) floor(0.025 * ($iters - 1));
    $highIdx = (int) floor(0.975 * ($iters - 1));
    return ['low' => $diffs[$lowIdx], 'high' => $diffs[$highIdx]];
}

function mannWhitneyU(array $a, array $b): float {
    $combined = [];
    foreach ($a as $v) {
        $combined[] = ['v' => $v, 'group' => 'a'];
    }
    foreach ($b as $v) {
        $combined[] = ['v' => $v, 'group' => 'b'];
    }
    usort($combined, static fn(array $x, array $y): int => $x['v'] <=> $y['v']);

    $rank = 1.0;
    $rankSumA = 0.0;
    foreach ($combined as $item) {
        if ($item['group'] === 'a') {
            $rankSumA += $rank;
        }
        $rank += 1.0;
    }

    $n1 = count($a);
    $n2 = count($b);
    if ($n1 === 0 || $n2 === 0) {
        return 0.0;
    }
    return $rankSumA - ($n1 * ($n1 + 1) / 2.0);
}

function writeSummary(string $path, array $rows): void {
    $h = fopen($path, 'w');
    if ($h === false) {
        throw new RuntimeException("Failed to open output file: $path");
    }
    if (empty($rows)) {
        fclose($h);
        return;
    }
    fputcsv($h, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($h, $row);
    }
    fclose($h);
}
