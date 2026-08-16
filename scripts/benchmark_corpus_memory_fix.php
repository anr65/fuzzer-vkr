<?php declare(strict_types=1);

use PhpFuzzer\Corpus;
use PhpFuzzer\CorpusEntry;

require dirname(__DIR__) . '/vendor/autoload.php';

final class BenchmarkRunState {
    public bool $finished = false;
    public int $completed = 0;
}

if ($argc < 3 || !in_array($argv[1], ['baseline', 'fixed'], true)) {
    fwrite(STDERR, "Usage: php scripts/benchmark_corpus_memory_fix.php baseline|fixed ITERATIONS [FEATURES]\n");
    exit(2);
}

$mode = $argv[1];
$iterations = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$featuresPerExecution = isset($argv[3])
    ? filter_var($argv[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : 2000;
if ($iterations === false || $featuresPerExecution === false) {
    fwrite(STDERR, "ITERATIONS and FEATURES must be positive integers\n");
    exit(2);
}

$seedPath = dirname(__DIR__) . '/corpora/yaml-v1/seeds/B04_depth_width.yaml';
$seed = file_get_contents($seedPath);
if ($seed === false) {
    throw new RuntimeException("Cannot read seed: $seedPath");
}

$baseline = new class {
    /** @var array<string, CorpusEntry> */
    public array $entriesByHash = [];
    /** @var list<CorpusEntry> */
    public array $entriesByIndex = [];
    /** @var array<int, bool> */
    public array $seenFeatures = [];
    public int $totalLen = 0;

    public function computeUniqueFeatures(CorpusEntry $entry): void {
        foreach ($entry->features as $feature => $_) {
            if (!isset($this->seenFeatures[$feature])) {
                $entry->uniqueFeatures[$feature] = true;
            }
        }
    }

    public function add(CorpusEntry $entry): void {
        $this->entriesByHash[$entry->hash] = $entry;
        $this->entriesByIndex[] = $entry;
        foreach ($entry->uniqueFeatures as $feature => $_) {
            $this->seenFeatures[$feature] = true;
        }
        $this->totalLen += strlen($entry->input);
    }
};

$fixed = new Corpus();
$commonFeatures = array_fill_keys(range(1, $featuresPerExecution), true);
$startedUsage = memory_get_usage(false);
$startedAllocated = memory_get_usage(true);
$startedAt = hrtime(true);
$runState = new BenchmarkRunState();

register_shutdown_function(static function () use (
    $runState,
    $mode,
    $iterations,
    $featuresPerExecution,
    $seedPath,
    $seed,
    &$baseline,
    &$fixed,
    $startedUsage,
    $startedAllocated,
    $startedAt
): void {
    if ($runState->finished) {
        return;
    }
    $error = error_get_last();
    $status = $error !== null && str_contains($error['message'], 'Allowed memory size')
        ? 'memory_exhausted'
        : 'aborted';
    emitResult(
        $status,
        $mode,
        $runState->completed,
        $iterations,
        $featuresPerExecution,
        $seedPath,
        $seed,
        $baseline,
        $fixed,
        $startedUsage,
        $startedAllocated,
        $startedAt
    );
});

for ($i = 0; $i < $iterations; $i++) {
    $features = $commonFeatures;
    $features[1_000_000 + $i] = true;
    $entry = new CorpusEntry($seed, $features, null);

    if ($mode === 'baseline') {
        $baseline->computeUniqueFeatures($entry);
        $baseline->add($entry);
    } else {
        $fixed->computeUniqueFeatures($entry);
        $fixed->addEntry($entry);
    }
    $runState->completed++;
}

$runState->finished = true;
emitResult(
    'completed',
    $mode,
    $runState->completed,
    $iterations,
    $featuresPerExecution,
    $seedPath,
    $seed,
    $baseline,
    $fixed,
    $startedUsage,
    $startedAllocated,
    $startedAt
);

function emitResult(
    string $status,
    string $mode,
    int $completed,
    int $iterations,
    int $featuresPerExecution,
    string $seedPath,
    string $seed,
    object $baseline,
    Corpus $fixed,
    int $startedUsage,
    int $startedAllocated,
    int $startedAt
): void {
    $entriesByHash = $mode === 'baseline'
        ? count($baseline->entriesByHash)
        : $fixed->getNumCorpusEntries();
    $entriesByIndex = $mode === 'baseline'
        ? count($baseline->entriesByIndex)
        : count((new ReflectionProperty($fixed, 'entriesByIndex'))->getValue($fixed));
    $seenFeatures = $mode === 'baseline'
        ? count($baseline->seenFeatures)
        : $fixed->getNumFeatures();
    $totalLen = $mode === 'baseline' ? $baseline->totalLen : $fixed->getTotalLen();

    echo json_encode([
        'status' => $status,
        'mode' => $mode,
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'seed' => str_replace(dirname(__DIR__) . '/', '', $seedPath),
        'seed_sha256' => hash('sha256', $seed),
        'seed_bytes' => strlen($seed),
        'planned_observations' => $iterations,
        'completed_observations' => $completed,
        'features_per_execution' => $featuresPerExecution + 1,
        'entries_by_hash' => $entriesByHash,
        'entries_by_index' => $entriesByIndex,
        'seen_features' => $seenFeatures,
        'reported_total_input_bytes' => $totalLen,
        'memory_usage_bytes' => memory_get_usage(false),
        'memory_delta_bytes' => memory_get_usage(false) - $startedUsage,
        'allocated_bytes' => memory_get_usage(true),
        'allocated_delta_bytes' => memory_get_usage(true) - $startedAllocated,
        'peak_allocated_bytes' => memory_get_peak_usage(true),
        'elapsed_seconds' => round((hrtime(true) - $startedAt) / 1e9, 6),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
