#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Aggregates determinism experiment results from multiple runs.
 * 
 * Scans determinism_runs/ directory, loads all graph_data.csv files,
 * and produces a unified determinism_summary.csv file with aligned timeline.
 */

$determinismDir = 'determinism_runs';
$outputFile = 'determinism_summary.csv';

// Parse command-line arguments
$options = getopt('', ['input-dir:', 'output:']);
if (isset($options['input-dir'])) {
    $determinismDir = $options['input-dir'];
}
if (isset($options['output'])) {
    $outputFile = $options['output'];
}

if (!is_dir($determinismDir)) {
    echo "Error: Directory '$determinismDir' does not exist.\n";
    echo "Run the determinism experiment first using scripts/run_determinism_experiment.sh\n";
    exit(1);
}

echo "Scanning $determinismDir for run results...\n";

// Find all graph_data.csv files
$runDirs = glob("$determinismDir/run_*/graph_data.csv");
if (empty($runDirs)) {
    echo "Error: No graph_data.csv files found in $determinismDir/\n";
    exit(1);
}

// Sort by run number
usort($runDirs, function($a, $b) {
    preg_match('/run_(\d+)/', $a, $matchA);
    preg_match('/run_(\d+)/', $b, $matchB);
    return (int)($matchA[1] ?? 0) <=> (int)($matchB[1] ?? 0);
});

echo "Found " . count($runDirs) . " runs\n";

// Load all data
$allData = [];
$allTimestamps = [];

foreach ($runDirs as $graphFile) {
    preg_match('/run_(\d+)/', $graphFile, $matches);
    $runId = $matches[1] ?? 'unknown';
    
    $handle = fopen($graphFile, 'r');
    if (!$handle) {
        echo "Warning: Could not open $graphFile\n";
        continue;
    }
    
    // Skip header
    fgetcsv($handle);
    
    $runData = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 5) {
            continue;
        }
        
        $runIdFromFile = $row[0];
        $timestamp = (float)$row[1];
        $executions = (int)$row[2];
        $coverage = (int)$row[3];
        $corpusSize = (int)$row[4];
        
        $runData[] = [
            't' => $timestamp,
            'executions' => $executions,
            'coverage' => $coverage,
            'corpus_size' => $corpusSize,
        ];
        
        $allTimestamps[(string)$timestamp] = true;
    }
    
    fclose($handle);
    
    if (!empty($runData)) {
        $allData["run_$runId"] = $runData;
        echo "Loaded run_$runId: " . count($runData) . " data points\n";
    }
}

if (empty($allData)) {
    echo "Error: No data loaded from any runs\n";
    exit(1);
}

// Create unified timeline (all unique timestamps, sorted)
$timeline = array_map('floatval', array_keys($allTimestamps));
sort($timeline, SORT_NUMERIC);

echo "Unified timeline: " . count($timeline) . " time points\n";

// Create output file
$output = fopen($outputFile, 'w');
if (!$output) {
    echo "Error: Could not create output file $outputFile\n";
    exit(1);
}

// Write header
fputcsv($output, ['run_id', 't', 'coverage', 'corpus_size']);

// For each timestamp, write data from all runs
// Use last-known-value filling for missing samples
foreach ($timeline as $t) {
    foreach ($allData as $runId => $runData) {
        // Find the closest data point <= t (last-known-value)
        $bestData = null;
        $bestT = -1.0;
        
        foreach ($runData as $point) {
            if ($point['t'] <= $t && $point['t'] > $bestT) {
                $bestT = $point['t'];
                $bestData = $point;
            }
        }
        
        if ($bestData !== null) {
            fputcsv($output, [
                $runId,
                $t,
                $bestData['coverage'],
                $bestData['corpus_size'],
            ]);
        }
    }
}

fclose($output);

echo "\n=== Aggregation Complete ===\n";
echo "Output file: $outputFile\n";
echo "Total rows: " . (count($timeline) * count($allData)) . "\n";
echo "\nThe file can be used for plotting:\n";
echo "  - coverage(t) overlay for all runs\n";
echo "  - variance(t) across runs\n";
echo "  - corpus_size(t)\n";
echo "  - delta_coverage(t)\n";
