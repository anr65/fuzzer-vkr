<?php declare(strict_types=1);

function ensureDir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

function appendError(string $resultsDir, string $stage, string $message): void {
    ensureDir($resultsDir);
    $line = sprintf("[%s] [%s] %s\n", date('c'), $stage, $message);
    file_put_contents($resultsDir . '/errors.log', $line, FILE_APPEND);
}

function writeCsv(string $path, array $rows): void {
    ensureDir(dirname($path));
    $h = fopen($path, 'w');
    if ($h === false) {
        throw new RuntimeException("Failed to open $path");
    }
    if (empty($rows)) {
        fclose($h);
        return;
    }
    fputcsv($h, array_keys($rows[0]), ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '\\');
    }
    fclose($h);
}

function readCsv(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $h = fopen($path, 'r');
    if ($h === false) {
        return [];
    }
    $header = fgetcsv($h, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($h);
        return [];
    }
    $rows = [];
    while (($line = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        if (count($line) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $line);
    }
    fclose($h);
    return $rows;
}

function stddev(array $values): float {
    $n = count($values);
    if ($n < 2) {
        return 0.0;
    }
    $mean = array_sum($values) / $n;
    $sum = 0.0;
    foreach ($values as $v) {
        $d = (float) $v - $mean;
        $sum += $d * $d;
    }
    return sqrt($sum / ($n - 1));
}

function avg(array $values): float {
    if (empty($values)) {
        return 0.0;
    }
    return array_sum($values) / count($values);
}

function discoverOperatorIds(string $repoRoot): array {
    $cfg = $repoRoot . '/config/operator_weights.json';
    if (is_file($cfg)) {
        $json = json_decode((string) file_get_contents($cfg), true);
        if (is_array($json) && isset($json['static_default']) && is_array($json['static_default'])) {
            return array_keys($json['static_default']);
        }
    }
    throw new RuntimeException('Failed to discover operator IDs from config/operator_weights.json');
}

function loadBaselineWeights(string $repoRoot, array $operatorIds): array {
    $weights = array_fill_keys($operatorIds, 1.0);
    $cfg = $repoRoot . '/config/operator_weights.json';
    if (is_file($cfg)) {
        $json = json_decode((string) file_get_contents($cfg), true);
        if (is_array($json) && isset($json['static_default']) && is_array($json['static_default'])) {
            foreach ($operatorIds as $id) {
                if (isset($json['static_default'][$id])) {
                    $weights[$id] = (float) $json['static_default'][$id];
                }
            }
        }
    }
    return $weights;
}

function copyCorpus(string $source, string $destination): void {
    ensureDir($destination);
    $items = glob($source . '/*');
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if (is_file($item)) {
            copy($item, $destination . '/' . basename($item));
        }
    }
}

function runFuzzer(array $args, string $cwd): int {
    $cmd = 'php bin/php-fuzzer ' . implode(' ', array_map('escapeshellarg', $args));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        return 1;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc);
}

function extractRunMetrics(string $stabilityCsv): array {
    $rows = readCsv($stabilityCsv);
    if (empty($rows)) {
        return [
            'final_coverage' => 0.0,
            'corpus_size' => 0.0,
            'new_paths_rate' => 0.0,
            'duration_sec' => 0.0,
            'timeseries' => [],
            'active_seeds' => 0.0,
        ];
    }
    $last = $rows[count($rows) - 1];
    $duration = (float) ($last['timestamp'] ?? 0.0);
    $coverage = (float) ($last['unique_features'] ?? 0.0);
    $corpus = (float) ($last['corpus_size'] ?? 0.0);
    $activeSeeds = (float) ($last['active_seeds'] ?? 0.0);
    $newPathsRate = $duration > 0.0 ? $coverage / $duration : 0.0;
    return [
        'final_coverage' => $coverage,
        'corpus_size' => $corpus,
        'new_paths_rate' => $newPathsRate,
        'duration_sec' => $duration,
        'timeseries' => resampleCoverageTimeseries($rows, 10),
        'active_seeds' => $activeSeeds,
    ];
}

function resampleCoverageTimeseries(array $rows, int $stepSec): array {
    if (empty($rows)) {
        return [];
    }
    $duration = (int) floor((float) ($rows[count($rows) - 1]['timestamp'] ?? 0.0));
    $series = [];
    $idx = 0;
    for ($t = 0; $t <= $duration; $t += $stepSec) {
        while ($idx + 1 < count($rows) && (float) $rows[$idx + 1]['timestamp'] <= $t) {
            $idx++;
        }
        $series[] = [
            'time_sec' => $t,
            'coverage' => (float) ($rows[$idx]['unique_features'] ?? 0.0),
            'corpus_size' => (float) ($rows[$idx]['corpus_size'] ?? 0.0),
            'active_seeds' => (float) ($rows[$idx]['active_seeds'] ?? 0.0),
        ];
    }
    return $series;
}
