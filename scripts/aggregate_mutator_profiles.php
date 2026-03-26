#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Aggregates mutator profile experiment results.
 * 
 * Reads stability.csv files from mutator_profile_runs/ and produces
 * a summary CSV comparing all profiles.
 */

$inputDir = 'mutator_profile_runs';
$outputFile = 'mutator_profiles_summary.csv';

// Parse command-line arguments
$options = getopt('', ['input-dir:', 'output:']);
if (isset($options['input-dir'])) {
    $inputDir = $options['input-dir'];
}
if (isset($options['output'])) {
    $outputFile = $options['output'];
}

if (!is_dir($inputDir)) {
    echo "Error: Directory '$inputDir' does not exist.\n";
    echo "Run the mutator profile experiment first using scripts/run_mutator_profiles.sh\n";
    exit(1);
}

echo "Scanning $inputDir for profile results...\n";

// Find all stability.csv files
$profileDirs = glob("$inputDir/*/stability.csv");
if (empty($profileDirs)) {
    echo "Error: No stability.csv files found in $inputDir/\n";
    exit(1);
}

// Extract profile names
$profiles = [];
foreach ($profileDirs as $stabilityFile) {
    preg_match('#/([^/]+)/stability\.csv$#', $stabilityFile, $matches);
    if ($matches) {
        $profiles[] = $matches[1];
    }
}

sort($profiles);
echo "Found " . count($profiles) . " profiles\n";

// Load data for each profile
$profileData = [];

foreach ($profiles as $profile) {
    $stabilityFile = "$inputDir/$profile/stability.csv";
    if (!file_exists($stabilityFile)) {
        echo "Warning: $stabilityFile not found, skipping\n";
        continue;
    }
    
    $handle = fopen($stabilityFile, 'r');
    if (!$handle) {
        echo "Warning: Could not open $stabilityFile\n";
        continue;
    }
    
    // Skip header
    fgetcsv($handle);
    
    $firstRow = null;
    $lastRow = null;
    $structuralCount = 0;
    $semanticCount = 0;
    
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 4) {
            continue;
        }
        
        if ($firstRow === null) {
            $firstRow = $row;
        }
        $lastRow = $row;
        
        // Count structural mutations (rough estimate based on corpus growth patterns)
        // This is a simplified heuristic - in a real implementation, you'd track
        // which mutators were actually used
    }
    
    fclose($handle);
    
    if ($firstRow === null || $lastRow === null) {
        echo "Warning: No data found for profile $profile\n";
        continue;
    }
    
    $startTime = (float)$firstRow[0];
    $startCoverage = (int)$firstRow[2];
    $startCorpusSize = (int)$firstRow[3];
    
    $endTime = (float)$lastRow[0];
    $endCoverage = (int)$lastRow[2];
    $endCorpusSize = (int)$lastRow[3];
    
    $duration = $endTime - $startTime;
    $growthRate = $duration > 0 ? ($endCoverage - $startCoverage) / $duration : 0;
    
    // Load profile definition to compute structural/semantic scores
    $profilesFile = 'config/mutator_profiles.json';
    $structuralScore = 0;
    $semanticScore = 0;
    
    if (file_exists($profilesFile)) {
        $allProfiles = json_decode(file_get_contents($profilesFile), true);
        if (isset($allProfiles[$profile])) {
            $mutators = $allProfiles[$profile];
            
            // Count structural mutators
            $structuralMutators = ['InsertByte', 'InsertRepeatedBytes', 'ShuffleBytes', 'CopyPart'];
            foreach ($mutators as $mutator) {
                if (in_array($mutator, $structuralMutators)) {
                    $structuralScore++;
                }
            }
            
            // Count semantic mutators
            $semanticMutators = ['ChangeASCIIInt', 'ChangeBinInt', 'AddWordFromManualDictionary'];
            foreach ($mutators as $mutator) {
                if (in_array($mutator, $semanticMutators)) {
                    $semanticScore++;
                }
            }
        }
    }
    
    $profileData[$profile] = [
        'final_coverage' => $endCoverage,
        'final_corpus_size' => $endCorpusSize,
        'growth_rate' => round($growthRate, 2),
        'structural_score' => $structuralScore,
        'semantic_score' => $semanticScore,
        'start_coverage' => $startCoverage,
        'duration' => round($duration, 2),
    ];
    
    echo "Loaded $profile: coverage=$endCoverage, corpus=$endCorpusSize, growth_rate=$growthRate\n";
}

if (empty($profileData)) {
    echo "Error: No profile data loaded\n";
    exit(1);
}

// Create output file
$output = fopen($outputFile, 'w');
if (!$output) {
    echo "Error: Could not create output file $outputFile\n";
    exit(1);
}

// Write header
fputcsv($output, [
    'profile',
    'final_coverage',
    'final_corpus_size',
    'growth_rate',
    'structural_score',
    'semantic_score',
    'start_coverage',
    'duration'
]);

// Sort by final_coverage descending
uasort($profileData, function($a, $b) {
    return $b['final_coverage'] <=> $a['final_coverage'];
});

// Write data
foreach ($profileData as $profile => $data) {
    fputcsv($output, [
        $profile,
        $data['final_coverage'],
        $data['final_corpus_size'],
        $data['growth_rate'],
        $data['structural_score'],
        $data['semantic_score'],
        $data['start_coverage'],
        $data['duration'],
    ]);
}

fclose($output);

echo "\n=== Aggregation Complete ===\n";
echo "Output file: $outputFile\n";
echo "Total profiles: " . count($profileData) . "\n";
echo "\nProfiles sorted by final_coverage (descending)\n";
