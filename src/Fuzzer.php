<?php declare(strict_types=1);

namespace PhpFuzzer;

use GetOpt\ArgumentException;
use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use Nikic\IncludeInterceptor\FileFilter;
use Nikic\IncludeInterceptor\Interceptor;
use PhpFuzzer\Diagnostics\CorpusDiagnostics;
use PhpFuzzer\Instrumentation\FileInfo;
use PhpFuzzer\Instrumentation\Instrumentor;
use PhpFuzzer\Mutation\Dictionary;
use PhpFuzzer\Mutation\Mutator;
use PhpFuzzer\Mutation\RNG;
use PhpFuzzer\Util\AtomicFile;
use PhpParser\PhpVersion;

final class Fuzzer {
    private Interceptor $interceptor;
    private Instrumentor $instrumentor;
    private Corpus $corpus;
    private string $corpusDir;
    private string $outputDir;
    private string $logFile;
    private Mutator $mutator;
    private RNG $rng;
    private Config $config;
    public ?string $targetPath = null;

    private ?string $coverageDir = null;
    /** @var array<string, FileInfo> */
    private array $fileInfos = [];
    private ?string $lastInput = null;

    private int $runs = 0;
    private int $lastInterestingRun = 0;
    private int $initialFeatures;
    private float $startTime;
    private int $mutationDepthLimit = 5;
    private int $maxRuns = PHP_INT_MAX;
    private int $lenControlFactor = 200;
    private int $timeout = 3;
    private int $memory_limit = 999;
    private ?int $maxTimeSeconds = null;

    // Counts all crashes, including duplicates
    private int $crashes = 0;
    private int $maxCrashes = 100;
    private ?StabilityLogger $stabilityLogger = null;
    private ?CorpusDiagnostics $corpusDiagnostics = null;
    private ?array $mutatorProfile = null;

    private bool $adaptiveMutators = false;
    private float $mutatorDisableThreshold = 0.01;
    private int $mutatorEvalWindow = 500;
    private int $mutatorReenableIntervalWindows = 3;
    private ?string $mutatorStatsLogPath = null;
    private int $mutatorStatsExportInterval = 500;
    private ?MutatorStats $mutatorStats = null;
    /** @var list<string> */
    private array $lastDisabledMutators = [];
    private ?int $mutatorForceRefreshStartExclusive = null;
    private ?int $mutatorForceRefreshEndInclusive = null;

    private string $seedScheduling = 'uniform';
    private ?SeedScheduler $seedScheduler = null;
    private ?string $seedLifecycleLogPath = null;
    private int $seedLifecycleInterval = 500;

    private ?string $corpusAdmissionMode = null;
    private int $corpusAdmissionRelaxedThreshold = 1;
    private ?string $corpusAdmissionLogPath = null;
    private ?CorpusAdmission $corpusAdmission = null;
    private int $intervalCorpusRejectedDuplicates = 0;
    private int $intervalCorpusAdmittedRelaxed = 0;

    private bool $yamlCacheEnabled = false;
    private int $yamlCacheStatsInterval = 1000;
    private ?string $yamlCacheStatsLogPath = null;

    public function __construct() {
//        $this->outputDir = getcwd();
        $this->instrumentor = new Instrumentor(
            FuzzingContext::class, PhpVersion::getHostVersion());
        $this->rng = new RNG();
        $this->config = new Config();
        // Mutator will be created after profile is set (if provided) or use default
        $this->mutator = new Mutator($this->rng, $this->config->dictionary, null);
        $this->corpus = new Corpus();

        // Instrument everything apart from our src/ directory.
        $fileFilter = FileFilter::createAllWhitelisted();
        $fileFilter->addBlackList(__DIR__);
        // Only intercept file:// streams. Interception of phar:// streams may run into
        // incorrect stat() handling during path resolution in PHP.
        $protocols = ['file'];
        $this->interceptor = new Interceptor(function(string $path) use($fileFilter) {
            if (!$fileFilter->test($path)) {
                return null;
            }

            $code = file_get_contents($path);
            $fileInfo = new FileInfo();
            $instrumentedCode = $this->instrumentor->instrument($code, $fileInfo);
            $this->fileInfos[$path] = $fileInfo;
            return $instrumentedCode;
        }, $protocols);
    }

    private function loadTarget(string $path): void {
        if (!is_file($path)) {
            throw new FuzzerException('Target "' . $path . '" does not exist');
        }

        $this->targetPath = $path;
        $this->startInstrumentation();
        // Unbind $this and make config available as $config variable.
        (static function(Config $config) use($path) {
            $fuzzer = $config; // For backwards compatibility.
            require $path;
        })($this->config);
    }

    public function setCorpusDir(string $path): void {
        $this->corpusDir = $path;
        if (!is_dir($this->corpusDir)) {
            throw new FuzzerException('Corpus directory "' . $this->corpusDir . '" does not exist');
        }
    }

    public function setCoverageDir(string $path): void {
        $this->coverageDir = $path;
    }

    public function setOutputDir(string $path): void {
        $this->outputDir = $path;
    }

    public function setLogFile(string $path): void {
        $this->logFile = $path;
    }

    public function setStabilityLogFile(string $path, string $format = 'csv', int $logInterval = 1000): void {
        $this->stabilityLogger = new StabilityLogger($path, $format, $logInterval);
    }

    public function setStabilityGraphDataFile(string $graphDataFile, string $runId): void {
        if ($this->stabilityLogger !== null) {
            $this->stabilityLogger->setGraphDataFile($graphDataFile, $runId);
        }
    }

    public function setMutatorProfile(?array $mutatorProfile): void {
        $this->mutatorProfile = $mutatorProfile;
        // Recreate mutator with new profile
        $this->mutator = new Mutator($this->rng, $this->config->dictionary, $this->mutatorProfile);
        if ($this->mutatorStats !== null) {
            $this->mutatorStats->registerMutatorNames($this->mutator->getMutatorNames());
        }
    }

    public function setCorpusDiagnostics(
        string $eventsLogFile,
        string $snapshotDir,
        int $snapshotFrequency
    ): void {
        $this->corpusDiagnostics = new CorpusDiagnostics();
        $this->corpusDiagnostics->enable(
            $eventsLogFile,
            $snapshotDir,
            $snapshotFrequency,
            function() { return $this->runs; },
            function() { return $this->corpus->getNumFeatures(); }
        );
        $this->corpus->setDiagnostics($this->corpusDiagnostics);
    }


    public function startInstrumentation(): void {
        $this->interceptor->setUp();
    }

    public function fuzz(): void {
        if (!$this->loadCorpus()) {
            return;
        }

        // Start with a short maximum length, increase if we fail to make progress.
        $maxLen = min($this->config->maxLen, max(4, $this->corpus->getMaxLen()));

        // Don't count runs while loading the corpus.
        $this->runs = 0;
        $this->startTime = microtime(true);
        
        // Initialize stability logger if configured
        if ($this->stabilityLogger !== null) {
            $this->stabilityLogger->start($this->startTime);
        }
        if ($this->adaptiveMutators && $this->mutatorStatsLogPath !== null && is_file($this->mutatorStatsLogPath)) {
            // keep existing file; do not duplicate header
        } elseif ($this->adaptiveMutators && $this->mutatorStatsLogPath !== null) {
            AtomicFile::writeString($this->mutatorStatsLogPath, "run,mutator_name,action,gain_rate\n");
        }
        if ($this->corpusAdmissionLogPath !== null && !is_file($this->corpusAdmissionLogPath)) {
            AtomicFile::writeString(
                $this->corpusAdmissionLogPath,
                "run,input_hash,input_len,delta_features,mode,decision\n"
            );
        }
        
        while ($this->runs < $this->maxRuns) {
            // Check time limit if set
            if ($this->maxTimeSeconds !== null) {
                $elapsed = microtime(true) - $this->startTime;
                if ($elapsed >= $this->maxTimeSeconds) {
                    echo "Time limit of {$this->maxTimeSeconds} seconds reached, stopping\n";
                    file_put_contents($this->logFile, "Time limit of {$this->maxTimeSeconds} seconds reached, stopping\n", FILE_APPEND);
                    break;
                }
            }

            if (memory_get_usage(true) / 1024 / 1024 >= $this->memory_limit) {
                echo "Memory limit of {$this->memory_limit} MB exceeded, aborting\n";
                file_put_contents($this->logFile, "Memory limit of {$this->memory_limit} MB exceeded, aborting\n", FILE_APPEND);
                exit(42);
            }


            if ($this->adaptiveMutators && $this->mutatorStats !== null) {
                if ($this->mutatorForceRefreshEndInclusive !== null
                    && $this->runs > $this->mutatorForceRefreshEndInclusive
                ) {
                    $this->mutatorForceRefreshStartExclusive = null;
                    $this->mutatorForceRefreshEndInclusive = null;
                }
                $inRefresh = $this->mutatorForceRefreshStartExclusive !== null
                    && $this->runs > $this->mutatorForceRefreshStartExclusive
                    && $this->mutatorForceRefreshEndInclusive !== null
                    && $this->runs <= $this->mutatorForceRefreshEndInclusive;
                $this->mutator->setForceAllEnabled($inRefresh);
            }

            $origEntry = $this->pickCorpusEntry();
            $input = $origEntry !== null ? $origEntry->input : "";
            $crossOverEntry = $this->pickCorpusEntry();
            $crossOverInput = $crossOverEntry !== null ? $crossOverEntry->input : null;
            $chainMutatorNames = [];
            $innerBrokeEarly = false;
            for ($m = 0; $m < $this->mutationDepthLimit; $m++) {
                if ($this->adaptiveMutators && $this->mutatorStats !== null) {
                    [$input, $mName] = $this->mutator->mutateWithMeta($input, $maxLen, $crossOverInput);
                    $chainMutatorNames[] = $mName;
                } else {
                    $input = $this->mutator->mutate($input, $maxLen, $crossOverInput);
                }
                $entry = $this->runInput($input);
                if ($entry->crashInfo) {
                    $this->recordMutatorChainOutcome($chainMutatorNames, false);
                    if ($this->corpus->addCrashEntry($entry)) {
                        $entry->storeAtPath($this->outputDir . '/crash-' . $entry->hash . '.txt');
                        $this->printCrash('CRASH', $entry);
                    } else {
                        echo "DUPLICATE CRASH\n";
                    }
                    if (++$this->crashes >= $this->maxCrashes) {
                        echo "Maximum of {$this->maxCrashes} crashes reached, aborting\n";
                        return;
                    }
                    $innerBrokeEarly = true;
                    break;
                }

                $coverageBefore = $this->corpus->getNumFeatures();
                $this->corpus->computeUniqueFeatures($entry);
                if ($entry->uniqueFeatures) {
                    if ($this->corpusAdmission !== null) {
                        $deltaFeatures = \count($entry->uniqueFeatures);
                        $inputHash = hash('sha256', $entry->input);
                        if ($this->corpusAdmission->isDuplicate($entry->input)) {
                            $this->intervalCorpusRejectedDuplicates++;
                            $this->recordMutatorChainOutcome($chainMutatorNames, false);
                            if ($this->corpusAdmissionLogPath !== null) {
                                $this->corpusAdmission->exportAdmissionLog(
                                    $this->corpusAdmissionLogPath,
                                    $this->runs,
                                    $inputHash,
                                    \strlen($entry->input),
                                    $deltaFeatures,
                                    (string) $this->corpusAdmissionMode,
                                    'rejected_duplicate'
                                );
                            }
                            $innerBrokeEarly = true;
                            break;
                        }
                        $admit = $this->corpusAdmissionMode === 'strict'
                            ? $this->corpusAdmission->shouldAdmitStrict($entry)
                            : $this->corpusAdmission->shouldAdmitRelaxed($entry, $this->corpusAdmissionRelaxedThreshold);
                        if (!$admit) {
                            $this->recordMutatorChainOutcome($chainMutatorNames, false);
                            if ($this->corpusAdmissionLogPath !== null) {
                                $this->corpusAdmission->exportAdmissionLog(
                                    $this->corpusAdmissionLogPath,
                                    $this->runs,
                                    $inputHash,
                                    \strlen($entry->input),
                                    $deltaFeatures,
                                    (string) $this->corpusAdmissionMode,
                                    'rejected_threshold'
                                );
                            }
                            $innerBrokeEarly = true;
                            break;
                        }
                        if ($this->corpusAdmissionMode === 'relaxed') {
                            $this->intervalCorpusAdmittedRelaxed++;
                        }
                        $this->corpusAdmission->admit($entry->input);
                        if ($this->corpusAdmissionLogPath !== null) {
                            $this->corpusAdmission->exportAdmissionLog(
                                $this->corpusAdmissionLogPath,
                                $this->runs,
                                $inputHash,
                                \strlen($entry->input),
                                $deltaFeatures,
                                (string) $this->corpusAdmissionMode,
                                'accepted'
                            );
                        }
                    }
                    $this->recordMutatorChainOutcome($chainMutatorNames, true);
                    $parentHash = $origEntry !== null ? $origEntry->hash : null;
                    $this->corpus->addEntry($entry, $parentHash);
                    if ($this->seedScheduler !== null) {
                        $this->seedScheduler->registerSeed($entry->hash, $this->runs);
                    }
                    $entry->storeAtPath($this->corpusDir . '/' . $entry->hash . '.txt');

                    $this->lastInterestingRun = $this->runs;
                    
                    // Track contribution: the original entry led to new coverage
                    if ($this->stabilityLogger !== null && $origEntry !== null) {
                        $this->stabilityLogger->recordContribution($origEntry->hash, $this->runs);
                    }
                    if ($this->seedScheduler !== null && $origEntry !== null) {
                        $this->seedScheduler->recordContribution($origEntry->hash, $this->runs);
                    }
                    
                    // Record contribution in diagnostics
                    if ($this->corpusDiagnostics !== null && $origEntry !== null) {
                        $this->corpusDiagnostics->recordContribution($origEntry);
                    }
                    
                    $this->printAction('NEW', $entry);
                    $innerBrokeEarly = true;
                    break;
                } else {
                    // Log rejected candidate (has features but no unique features)
                    if ($this->corpusDiagnostics !== null && $origEntry !== null) {
                        $coverageAfter = $this->corpus->getNumFeatures();
                        $parentSeedId = $this->corpusDiagnostics->getSeedId($origEntry->hash);
                        $this->corpusDiagnostics->logCandidateSeed(
                            $entry,
                            $parentSeedId,
                            $coverageBefore,
                            $coverageAfter,
                            'rejected',
                            'no_unique_features'
                        );
                    }
                }

                if ($origEntry !== null &&
                    \strlen($entry->input) < \strlen($origEntry->input) &&
                    $entry->hasAllUniqueFeaturesOf($origEntry)
                ) {
                    // Preserve unique features of original entry,
                    // even if they are not unique anymore at this point.
                    $entry->uniqueFeatures = $origEntry->uniqueFeatures;
                    if ($this->corpus->replaceEntry($origEntry, $entry)) {
                        $this->recordMutatorChainOutcome($chainMutatorNames, true);
                        $entry->storeAtPath($this->corpusDir . '/' . $entry->hash . '.txt');
                        if ($this->seedScheduler !== null) {
                            $this->seedScheduler->replaceSeed($origEntry->hash, $entry->hash, $this->runs);
                            $this->seedScheduler->recordContribution($entry->hash, $this->runs);
                        }
                    } else {
                        $this->recordMutatorChainOutcome($chainMutatorNames, false);
                    }
                    unlink($origEntry->path);
                    if ($this->config->getYamlCache() !== null) {
                        $this->config->getYamlCache()->invalidate($origEntry->input);
                    }

                    $this->lastInterestingRun = $this->runs;
                    
                    // Track contribution: the original entry led to a reduction (also counts as contribution)
                    if ($this->stabilityLogger !== null) {
                        $this->stabilityLogger->recordContribution($origEntry->hash, $this->runs);
                    }
                    
                    // Record contribution in diagnostics
                    if ($this->corpusDiagnostics !== null) {
                        $this->corpusDiagnostics->recordContribution($origEntry);
                    }
                    
                    $this->printAction('REDUCE', $entry);
                    $innerBrokeEarly = true;
                    break;
                }
            }
            if (!$innerBrokeEarly && $this->adaptiveMutators && $this->mutatorStats !== null && $chainMutatorNames !== []) {
                $this->recordMutatorChainOutcome($chainMutatorNames, false);
            }

            if ($maxLen < $this->config->maxLen) {
                // Increase max length if we haven't made progress in a while.
                $logMaxLen = (int) log($maxLen, 2);
                if (($this->runs - $this->lastInterestingRun) > $this->lenControlFactor * $logMaxLen) {
                    $maxLen = min($this->config->maxLen, $maxLen + $logMaxLen);
                    $this->lastInterestingRun = $this->runs;
                }
            }
            
            if ($this->yamlCacheEnabled && $this->config->getYamlCache() !== null && $this->yamlCacheStatsLogPath !== null
                && $this->runs > 0 && $this->yamlCacheStatsInterval > 0 && $this->runs % $this->yamlCacheStatsInterval === 0
            ) {
                $this->config->getYamlCache()->exportStatsJson($this->yamlCacheStatsLogPath);
            }

            if ($this->seedScheduler !== null && $this->seedLifecycleLogPath !== null && $this->runs > 0
                && $this->seedLifecycleInterval > 0 && $this->runs % $this->seedLifecycleInterval === 0
            ) {
                $this->seedScheduler->exportSeedLifecycleCsv($this->seedLifecycleLogPath, $this->runs);
            }

            // Log stability metrics periodically
            if ($this->stabilityLogger !== null) {
                $extendedMetrics = $this->computeExtendedMetrics();
                if ($extendedMetrics === null) {
                    $extendedMetrics = [];
                }
                $extendedMetrics['corpus_rejected_duplicates'] = $this->intervalCorpusRejectedDuplicates;
                $extendedMetrics['corpus_admitted_relaxed'] = $this->intervalCorpusAdmittedRelaxed;
                $this->intervalCorpusRejectedDuplicates = 0;
                $this->intervalCorpusAdmittedRelaxed = 0;
                $this->stabilityLogger->logIfInterval(
                    $this->runs,
                    $this->corpus->getNumFeatures(),
                    $this->corpus->getNumCorpusEntries(),
                    $extendedMetrics
                );
            }
            
            // Create corpus snapshot periodically
            if ($this->corpusDiagnostics !== null) {
                $this->corpusDiagnostics->createSnapshot($this->runs);
            }

            if ($this->adaptiveMutators && $this->mutatorStats !== null && $this->runs > 0) {
                if ($this->mutatorStatsExportInterval > 0 && $this->runs % $this->mutatorStatsExportInterval === 0) {
                    $base = $this->mutatorStatsLogPath ?? 'mutator_stats.csv';
                    $exportPath = preg_match('/\.[^.]+$/', $base)
                        ? preg_replace('/\.[^.]+$/', '.per_mutator.csv', $base)
                        : $base . '.per_mutator.csv';
                    $this->mutatorStats->exportCsv($exportPath);
                }
                if ($this->runs % $this->mutatorEvalWindow === 0) {
                    $this->applyMutatorEvalWindowBoundary();
                }
            }
        }
        
        // Final log at end of fuzzing
        if ($this->stabilityLogger !== null) {
            $extendedMetrics = $this->computeExtendedMetrics();
            if ($extendedMetrics === null) {
                $extendedMetrics = [];
            }
            $extendedMetrics['corpus_rejected_duplicates'] = $this->intervalCorpusRejectedDuplicates;
            $extendedMetrics['corpus_admitted_relaxed'] = $this->intervalCorpusAdmittedRelaxed;
            $this->stabilityLogger->logMetrics(
                $this->runs,
                $this->corpus->getNumFeatures(),
                $this->corpus->getNumCorpusEntries(),
                $extendedMetrics
            );
            $this->stabilityLogger->finalize();
        }
        
        // Final snapshot
        if ($this->corpusDiagnostics !== null) {
            $this->corpusDiagnostics->createSnapshot($this->runs);
        }

        if ($this->seedScheduler !== null && $this->seedLifecycleLogPath !== null) {
            $this->seedScheduler->exportSeedLifecycleCsv($this->seedLifecycleLogPath, $this->runs);
        }
        if ($this->yamlCacheEnabled && $this->config->getYamlCache() !== null && $this->yamlCacheStatsLogPath !== null) {
            $this->config->getYamlCache()->exportStatsJson($this->yamlCacheStatsLogPath);
        }
        if ($this->adaptiveMutators && $this->mutatorStats !== null && $this->mutatorStatsLogPath !== null) {
            $base = $this->mutatorStatsLogPath;
            $exportPath = preg_match('/\.[^.]+$/', $base)
                ? preg_replace('/\.[^.]+$/', '.per_mutator.csv', $base)
                : $base . '.per_mutator.csv';
            $this->mutatorStats->exportCsv($exportPath);
        }
    }

    /**
     * @param list<string> $names
     */
    private function pickCorpusEntry(): ?CorpusEntry {
        if ($this->seedScheduling === 'weighted' && $this->seedScheduler !== null) {
            $ids = $this->corpus->getAllSeedHashes();
            if ($ids === []) {
                return null;
            }
            $id = $this->seedScheduler->selectSeedId($this->rng, $ids, $this->runs);
            $this->seedScheduler->recordSelection($id, $this->runs);
            $e = $this->corpus->getEntryByHash($id);
            if ($this->corpusDiagnostics !== null && $e !== null) {
                $this->corpusDiagnostics->recordSelection($e);
            }
            return $e;
        }
        return $this->corpus->getRandomEntry($this->rng);
    }

    private function recordMutatorChainOutcome(array $names, bool $lastMutatorProducedGain): void {
        if (!$this->adaptiveMutators || $this->mutatorStats === null || $names === []) {
            return;
        }
        $last = \count($names) - 1;
        foreach ($names as $i => $n) {
            $this->mutatorStats->recordInvocation($n, $lastMutatorProducedGain && $i === $last);
        }
    }

    private function applyMutatorEvalWindowBoundary(): void {
        if ($this->mutatorStats === null) {
            return;
        }
        $currentWindow = (int) ($this->runs / $this->mutatorEvalWindow);
        if ($this->mutatorStats->shouldForceReenableAll($currentWindow)) {
            $this->mutatorForceRefreshStartExclusive = $this->runs;
            $this->mutatorForceRefreshEndInclusive = $this->runs + $this->mutatorEvalWindow;
            $this->mutator->setDisabledMutators([]);
            $this->mutator->setForceAllEnabled(false);
            $this->logMutatorEvent($this->runs, '_all_', 'force_refresh_window', 0.0);
        } else {
            $disabled = $this->mutatorStats->getDisabledMutators($this->mutatorDisableThreshold);
            $prev = array_fill_keys($this->lastDisabledMutators, true);
            $new = array_fill_keys($disabled, true);
            $allNames = array_unique(array_merge(array_keys($prev), array_keys($new)));
            foreach ($allNames as $name) {
                $was = isset($prev[$name]);
                $now = isset($new[$name]);
                if ($was && !$now) {
                    $this->logMutatorEvent($this->runs, $name, 're_enable', $this->mutatorStats->gainRate($name));
                } elseif (!$was && $now) {
                    $this->logMutatorEvent($this->runs, $name, 'disable', $this->mutatorStats->gainRate($name));
                }
            }
            $this->lastDisabledMutators = $disabled;
            $this->mutator->setDisabledMutators($disabled);
        }
    }

    private function logMutatorEvent(int $run, string $mutatorName, string $action, float $gainRate): void {
        if ($this->mutatorStatsLogPath === null) {
            return;
        }
        $line = sprintf(
            "%d,%s,%s,%.6f\n",
            $run,
            str_replace(["\n", "\r", ','], [' ', ' ', ';'], $mutatorName),
            $action,
            $gainRate
        );
        AtomicFile::appendLine($this->mutatorStatsLogPath, $line);
    }

    /**
     * Compute extended metrics from corpus diagnostics.
     * @return array<string, mixed>|null
     */
    private function computeExtendedMetrics(): ?array {
        if ($this->corpusDiagnostics === null) {
            return null;
        }

        $allStats = $this->corpusDiagnostics->getAllSeedStats();
        if (empty($allStats)) {
            return null;
        }

        $currentRun = $this->runs;
        $recentWindow = min(1000, $currentRun / 2); // Look back at most 1000 runs or half of total runs
        $thresholdRun = $currentRun - $recentWindow;

        $totalSeeds = count($allStats);
        $deadSeeds = 0;
        $activeSeeds = 0;
        $totalAge = 0;
        $totalSelected = 0;
        $totalContributed = 0;
        $deadSelections = 0;

        foreach ($allStats as $stats) {
            $age = $currentRun - $stats->creationRun;
            $totalAge += $age;
            $totalSelected += $stats->timesSelected;
            $totalContributed += $stats->timesContributed;

            if ($stats->isDead) {
                $deadSeeds++;
                $deadSelections += $stats->timesSelected;
            }

            if ($stats->lastContributionRun !== null && $stats->lastContributionRun >= $thresholdRun) {
                $activeSeeds++;
            }
        }

        $avgSeedAge = $totalSeeds > 0 ? $totalAge / $totalSeeds : 0;
        $deadSelectionPercentage = $totalSelected > 0 ? (100.0 * $deadSelections / $totalSelected) : 0;
        $contributionRate = $currentRun > 0 ? (100.0 * $totalContributed / $currentRun) : 0;

        return [
            'avg_seed_age' => round($avgSeedAge, 2),
            'active_seeds' => $activeSeeds,
            'dead_seeds' => $deadSeeds,
            'dead_selection_percentage' => round($deadSelectionPercentage, 2),
            'contribution_rate' => round($contributionRate, 2),
        ];
    }

    private function isAllowedException(\Throwable $e): bool {
        foreach ($this->config->allowedExceptions as $allowedException) {
            if ($e instanceof $allowedException) {
                return true;
            }
        }
        return false;
    }

    private function runInput(string $input): CorpusEntry {
        $this->runs++;
        if (\extension_loaded('pcntl')) {
            \pcntl_alarm($this->timeout);
        }

        // Remember the last input in case PHP generates a fatal error.
        $this->lastInput = $input;
        FuzzingContext::reset();
        $crashInfo = null;
        try {
            ($this->config->target)($input);
        } catch (\ParseError $e) {
            echo "PARSE ERROR $e\n";
            echo "INSTRUMENTATION BROKEN? -- ABORTING";
            exit(-1);
        } catch (\Throwable $e) {
            if (!$this->isAllowedException($e)) {
                $crashInfo = (string) $e;
            }
        }

        $features = $this->edgeCountsToFeatures(FuzzingContext::$edges);
        return new CorpusEntry($input, $features, $crashInfo);
    }

    /**
     * @param array<int, int> $edgeCounts
     * @return array<int, bool>
     */
    private function edgeCountsToFeatures(array $edgeCounts): array {
        $features = [];
        foreach ($edgeCounts as $edge => $count) {
            $feature = $this->edgeCountToFeature($edge, $count);
            $features[$feature] = true;
        }
        return $features;
    }

    private function edgeCountToFeature(int $edge, int $count): int {
        if ($count < 4) {
            $encodedCount = $count - 1;
        } else if ($count < 8) {
            $encodedCount = 3;
        } else if ($count < 16) {
            $encodedCount = 4;
        } else if ($count < 32) {
            $encodedCount = 5;
        } else if ($count < 128) {
            $encodedCount = 6;
        } else {
            $encodedCount = 7;
        }
        return $encodedCount << 56 | $edge;
    }

    private function loadCorpus(): bool {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->corpusDir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $entries = [];
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $input = file_get_contents($path);
            $entry = $this->runInput($input);
            $entry->path = $path;
            if ($entry->crashInfo) {
                $this->printCrash("CORPUS CRASH", $entry);
                return false;
            }

            $entries[] = $entry;
        }

        // Favor short entries.
        usort($entries, function (CorpusEntry $a, CorpusEntry $b) {
            return \strlen($a->input) <=> \strlen($b->input);
        });
        foreach ($entries as $entry) {
            $this->corpus->computeUniqueFeatures($entry);
            if ($entry->uniqueFeatures) {
                // Register initial seeds in diagnostics if enabled (before addEntry to avoid double registration)
                // Note: runs will be reset to 0 after loading, so initial seeds are effectively at run 0
                if ($this->corpusDiagnostics !== null) {
                    $coverage = $this->corpus->getNumFeatures();
                    // Temporarily set runs to 0 for seed registration (since runs will be reset after loading)
                    $savedRuns = $this->runs;
                    $this->runs = 0;
                    $this->corpusDiagnostics->registerSeed($entry, null, $coverage);
                    $this->runs = $savedRuns;
                }
                $this->corpus->addEntry($entry, null, true); // Skip registration in addEntry
                if ($this->seedScheduler !== null) {
                    $this->seedScheduler->registerSeed($entry->hash, 0);
                }
            }
        }
        $this->initialFeatures = $this->corpus->getNumFeatures();
        if ($this->corpusAdmission !== null) {
            foreach ($this->corpus->getAllSeedHashes() as $h) {
                $e = $this->corpus->getEntryByHash($h);
                if ($e !== null) {
                    $this->corpusAdmission->admit($e->input);
                }
            }
        }
        return true;
    }

    private function printAction(string $action, CorpusEntry $entry): void {
        $time = microtime(true) - $this->startTime;
        $mem = memory_get_usage();
        $numFeatures = $this->corpus->getNumFeatures();
        $numNewFeatures = $numFeatures - $this->initialFeatures;
        $maxLen = $this->corpus->getMaxLen();
        $maxLenLen = \strlen((string) $maxLen);
        $line = sprintf(
            "%-6s run: %d (%4.0f/s), ft: %d (%.0f/s), corp: %d (%s), len: %{$maxLenLen}d/%d, t: %.0fs, mem: %s\n",
            $action, $this->runs, $this->runs / $time,
            $numFeatures, $numNewFeatures / $time,
            $this->corpus->getNumCorpusEntries(),
            $this->formatBytes($this->corpus->getTotalLen()),
            \strlen($entry->input), $maxLen,
            $time, $this->formatBytes($mem)
        );

        echo $line;

        // Запись в файл
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    private function formatBytes(int $bytes): string {
        if ($bytes < 10 * 1024) {
            return $bytes . 'b';
        } else if ($bytes < 10 * 1024 * 1024) {
            $kiloBytes = (int) round($bytes / 1024);
            return $kiloBytes . 'kb';
        } else {
            $megaBytes = (int) round($bytes / (1024 * 1024));
            return $megaBytes . 'mb';
        }
    }

    private function printCrash(string $prefix, CorpusEntry $entry): void {
        echo "$prefix in $entry->path!\n";
        echo $entry->crashInfo . "\n";
        file_put_contents($this->logFile, "$prefix in $entry->path!\n", FILE_APPEND);
        file_put_contents($this->logFile, $entry->crashInfo . "\n", FILE_APPEND);
    }

    public function renderCoverage(): void {
        if ($this->coverageDir === null) {
            throw new FuzzerException('Missing coverage directory');
        }

        $renderer = new CoverageRenderer($this->coverageDir);
        $renderer->render($this->fileInfos, $this->corpus->getSeenBlockMap());
    }

    private function minimizeCrash(string $path): void {
        if (!is_file($path)) {
            throw new FuzzerException("Crash input \"$path\" does not exist");
        }

        $input = file_get_contents($path);
        $entry = $this->runInput($input);
        if (!$entry->crashInfo) {
            throw new FuzzerException("Crash input did not crash");
        }

        while ($this->runs < $this->maxRuns) {
            $newInput = $input;
            for ($m = 0; $m < $this->mutationDepthLimit; $m++) {
                $newInput = $this->mutator->mutate($newInput, $this->config->maxLen, null);
                if (\strlen($newInput) >= \strlen($input)) {
                    continue;
                }

                $newEntry = $this->runInput($newInput);
                if (!$newEntry->crashInfo) {
                    continue;
                }

                $newEntry->storeAtPath(getcwd() . '/minimized-' . md5($newInput) . '.txt');

                $len = \strlen($newInput);
                $this->printCrash("CRASH with length $len", $newEntry);
                $input = $newInput;
            }
        }
    }

    public function handleCliArgs(): int {
        $getOpt = new GetOpt([
            Option::create('h', 'help', GetOpt::NO_ARGUMENT)
                ->setDescription('Display this help'),
            Option::create(null, 'dict', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Use dictionary file'),
            Option::create(null, 'max-runs', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('num')
                ->setDescription('Limit maximum target executions'),
            Option::create(null, 'timeout', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('seconds')
                ->setDescription('Timeout for one target execution'),
            Option::create(null, 'memory-limit', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('megabytes')
                ->setDescription('RAM limit for one target execution'),
            Option::create(null, 'len-control-factor', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('num')
                ->setDescription('A higher value will increase the maximum length more slowly'),
            Option::create(null, 'stability-log', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Enable stability logging to file (CSV or JSON)'),
            Option::create(null, 'stability-format', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('format')
                ->setDescription('Stability log format: csv or json (default: csv)'),
            Option::create(null, 'stability-interval', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('runs')
                ->setDescription('Log stability metrics every N runs (default: 1000)'),
            Option::create(null, 'enable-corpus-diagnostics', GetOpt::NO_ARGUMENT)
                ->setDescription('Enable deep corpus diagnostic instrumentation'),
            Option::create(null, 'corpus-events-log', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Path to corpus events log file (CSV)'),
            Option::create(null, 'corpus-snapshot-frequency', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('runs')
                ->setDescription('Create corpus snapshot every N runs (default: 2000)'),
            Option::create(null, 'seed', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('int')
                ->setDescription('Seed for deterministic random number generation. If seed and corpus are identical, the fuzzer generates identical mutation sequences.'),
            Option::create(null, 'max-time', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('seconds')
                ->setDescription('Maximum fuzzing time in seconds'),
            Option::create(null, 'mutator-profile', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('profile_id')
                ->setDescription('Mutator profile ID from config/mutator_profiles.json. Use "default" for all mutators.'),
            Option::create(null, 'adaptive-mutators', GetOpt::NO_ARGUMENT)
                ->setDescription('Track per-mutator coverage gain and disable low performers (eval windows).'),
            Option::create(null, 'mutator-disable-threshold', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('float')
                ->setDescription('Disable mutators with rolling gain rate below this (default: 0.01).'),
            Option::create(null, 'mutator-stats-log', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Append mutator disable/re-enable events (CSV). Per-mutator snapshot: same path with .per_mutator.csv suffix.'),
            Option::create(null, 'mutator-eval-window', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('runs')
                ->setDescription('Rolling window size and eval period for adaptive mutators (default: 500).'),
            Option::create(null, 'mutator-reenable-interval', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('windows')
                ->setDescription('Every N eval windows, force all mutators on for one window (default: 3).'),
            Option::create(null, 'mutator-stats-interval', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('runs')
                ->setDescription('Export per-mutator stats CSV every N runs (default: same as mutator-eval-window).'),
            Option::create(null, 'seed-scheduling', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('mode')
                ->setDescription('uniform (default) or weighted (power scheduling by usefulness score).'),
            Option::create(null, 'seed-lifecycle-log', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Export seed lifecycle CSV periodically when using weighted scheduling.'),
            Option::create(null, 'seed-lifecycle-interval', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('runs')
                ->setDescription('Export seed lifecycle CSV every N runs (default: 500).'),
            Option::create(null, 'corpus-admission', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('mode')
                ->setDescription('strict (default corpus gate + dedup) or relaxed (min delta features). Omit for legacy behavior.'),
            Option::create(null, 'corpus-admission-threshold', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('int')
                ->setDescription('Minimum delta features to admit in relaxed mode (default: 1).'),
            Option::create(null, 'corpus-admission-log', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Append corpus admission decisions (CSV).'),
            Option::create(null, 'yaml-cache', GetOpt::NO_ARGUMENT)
                ->setDescription('Enable YAML parse cache (targets use Config::getYamlCache()->getOrParse()).'),
            Option::create(null, 'yaml-cache-stats-log', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('file')
                ->setDescription('Write YAML cache stats JSON every N runs.'),
            Option::create(null, 'yaml-cache-stats-interval', GetOpt::REQUIRED_ARGUMENT)
                ->setArgumentName('runs')
                ->setDescription('YAML cache stats export interval (default: 1000).'),
        ]);
        $getOpt->addOperand(Operand::create('target', Operand::REQUIRED));

        $getOpt->addCommand(Command::create('fuzz', [$this, 'handleFuzzCommand'])
            ->addOperand(Operand::create('corpus', Operand::OPTIONAL))
            ->addOperand(Operand::create('output-dir', Operand::OPTIONAL))
            ->addOperand(Operand::create('logfile', Operand::OPTIONAL))
            ->setDescription('Fuzz the target to find bugs'));
        $getOpt->addCommand(Command::create('minimize-crash', [$this, 'handleMinimizeCrashCommand'])
            ->addOperand(Operand::create('input', Operand::REQUIRED))
            ->setDescription('Reduce the size of a crashing input'));
        $getOpt->addCommand(Command::create('run-single', [$this, 'handleRunSingleCommand'])
            ->addOperand(Operand::create('input', Operand::REQUIRED))
            ->setDescription('Run single input through target'));
        $getOpt->addCommand(Command::create('report-coverage', [$this, 'handleReportCoverage'])
            ->addOperand(Operand::create('corpus', Operand::REQUIRED))
            ->addOperand(Operand::create('coverage-dir', Operand::REQUIRED))
            ->setDescription('Generate a HTML coverage report'));

        try {
            $getOpt->process();
        } catch (ArgumentException $e) {
            echo $e->getMessage() . PHP_EOL;
            echo PHP_EOL . $getOpt->getHelpText();
            return 1;
        }

        if ($getOpt->getOption('help')) {
            echo $getOpt->getHelpText();
            return 0;
        }

        /** @var Command|null $command The CommandInterface is missing the getHandler() method. */
        $command = $getOpt->getCommand();
        if (!$command) {
            echo 'Missing command' . PHP_EOL;
            echo PHP_EOL . $getOpt->getHelpText();
            return 1;
        }

        $opts = $getOpt->getOptions();
        if (isset($opts['max-runs'])) {
            $this->maxRuns = (int) $opts['max-runs'];
        }
        if (isset($opts['timeout'])) {
            $this->timeout = (int) $opts['timeout'];
        }
        if (isset($opts['len-control-factor'])) {
            $this->lenControlFactor = (int) $opts['len-control-factor'];
        }

        if (isset($opts['memory-limit'])) {
            $this->memory_limit = (int) $opts['memory-limit'];
        }

        // PHP CLI default memory_limit (often 128M) must cover the fuzzer budget; otherwise
        // the engine fatals before the soft check in fuzz() can stop cleanly.
        $phpMemoryLimitMb = $this->memory_limit + 128;
        ini_set('memory_limit', $phpMemoryLimitMb . 'M');

        if (isset($opts['max-time'])) {
            $this->maxTimeSeconds = (int) $opts['max-time'];
        }

        // Setup deterministic seed if provided
        if (isset($opts['seed'])) {
            $seed = (int) $opts['seed'];
            $this->rng->setSeed($seed);
        }

        // Setup mutator profile if provided
        if (isset($opts['mutator-profile'])) {
            $profileId = $opts['mutator-profile'];
            $profilePath = __DIR__ . '/../config/mutator_profiles.json';
            
            if (!file_exists($profilePath)) {
                throw new FuzzerException("Mutator profiles file not found: $profilePath");
            }
            
            $profiles = json_decode(file_get_contents($profilePath), true);
            if ($profiles === null) {
                throw new FuzzerException("Failed to parse mutator profiles JSON");
            }
            
            if ($profileId === 'default') {
                // Use default profile from JSON
                if (isset($profiles['default'])) {
                    $this->setMutatorProfile($profiles['default']);
                } else {
                    // Fallback: use all mutators (null = all)
                    $this->setMutatorProfile(null);
                }
            } elseif (!isset($profiles[$profileId])) {
                throw new FuzzerException("Unknown mutator profile: $profileId");
            } else {
                $this->setMutatorProfile($profiles[$profileId]);
            }
        }

        // Setup stability logger if requested
        if (isset($opts['stability-log'])) {
            $format = $opts['stability-format'] ?? 'csv';
            $interval = isset($opts['stability-interval']) ? (int) $opts['stability-interval'] : 1000;
            $this->setStabilityLogFile($opts['stability-log'], $format, $interval);
        }

        if (isset($opts['adaptive-mutators'])) {
            $this->adaptiveMutators = true;
            $this->mutatorDisableThreshold = isset($opts['mutator-disable-threshold'])
                ? (float) $opts['mutator-disable-threshold'] : 0.01;
            $this->mutatorEvalWindow = isset($opts['mutator-eval-window'])
                ? max(1, (int) $opts['mutator-eval-window']) : 500;
            $this->mutatorReenableIntervalWindows = isset($opts['mutator-reenable-interval'])
                ? max(1, (int) $opts['mutator-reenable-interval']) : 3;
            $this->mutatorStatsExportInterval = isset($opts['mutator-stats-interval'])
                ? max(1, (int) $opts['mutator-stats-interval']) : $this->mutatorEvalWindow;
            if (isset($opts['mutator-stats-log'])) {
                $this->mutatorStatsLogPath = $opts['mutator-stats-log'];
            } else {
                throw new FuzzerException('--adaptive-mutators requires --mutator-stats-log');
            }
            $this->mutatorStats = new MutatorStats($this->mutatorEvalWindow, $this->mutatorReenableIntervalWindows);
            $this->mutatorStats->registerMutatorNames($this->mutator->getMutatorNames());
        }

        if (isset($opts['seed-scheduling'])) {
            $ss = $opts['seed-scheduling'];
            if ($ss !== 'uniform' && $ss !== 'weighted') {
                throw new FuzzerException('--seed-scheduling must be uniform or weighted');
            }
            $this->seedScheduling = $ss;
            if ($ss === 'weighted') {
                $this->seedScheduler = new SeedScheduler();
            }
        }
        if (isset($opts['seed-lifecycle-log'])) {
            $this->seedLifecycleLogPath = $opts['seed-lifecycle-log'];
        }
        if (isset($opts['seed-lifecycle-interval'])) {
            $this->seedLifecycleInterval = max(1, (int) $opts['seed-lifecycle-interval']);
        }

        if (isset($opts['corpus-admission'])) {
            $m = $opts['corpus-admission'];
            if ($m !== 'strict' && $m !== 'relaxed') {
                throw new FuzzerException('--corpus-admission must be strict or relaxed');
            }
            $this->corpusAdmissionMode = $m;
            $this->corpusAdmission = new CorpusAdmission();
            $this->corpusAdmissionRelaxedThreshold = isset($opts['corpus-admission-threshold'])
                ? max(1, (int) $opts['corpus-admission-threshold']) : 1;
            if (isset($opts['corpus-admission-log'])) {
                $this->corpusAdmissionLogPath = $opts['corpus-admission-log'];
            }
        }

        if (isset($opts['yaml-cache'])) {
            $this->yamlCacheEnabled = true;
            $this->config->setYamlCache(new YamlCache());
        }
        if (isset($opts['yaml-cache-stats-interval'])) {
            $this->yamlCacheStatsInterval = max(1, (int) $opts['yaml-cache-stats-interval']);
        }
        if (isset($opts['yaml-cache-stats-log'])) {
            $this->yamlCacheStatsLogPath = $opts['yaml-cache-stats-log'];
        }

        // Setup corpus diagnostics if requested
        if (isset($opts['enable-corpus-diagnostics'])) {
            $eventsLog = $opts['corpus-events-log'] ?? 'corpus_events.csv';
            $snapshotDir = dirname($eventsLog) . '/corpus_snapshots';
            $snapshotFreq = isset($opts['corpus-snapshot-frequency']) 
                ? (int) $opts['corpus-snapshot-frequency'] 
                : 2000;
            $this->setCorpusDiagnostics($eventsLog, $snapshotDir, $snapshotFreq);
        }

        try {
            if (isset($opts['dict'])) {
                $this->config->addDictionary($opts['dict']);
            }
            $this->loadTarget($getOpt->getOperand('target'));

            $this->setupTimeoutHandler();
            $this->setupErrorHandler();
            $this->setupShutdownHandler();
            $command->getHandler()($getOpt);
        } catch (FuzzerException $e) {
            echo $e->getMessage() . PHP_EOL;
            return 1;
        }
        return 0;
    }

    private function createTemporaryCorpusDirectory(): string {
        do {
            $corpusDir = sys_get_temp_dir(). '/corpus-' . mt_rand();
        } while (file_exists($corpusDir));
        if (!@mkdir($corpusDir)) {
            throw new FuzzerException("Failed to create temporary corpus directory $corpusDir");
        }
        return $corpusDir;
    }

    private function handleFuzzCommand(GetOpt $getOpt): void {
        $corpusDir = $getOpt->getOperand('corpus');
        if ($corpusDir === null) {
            $corpusDir = $this->createTemporaryCorpusDirectory();
            echo "Using $corpusDir as corpus directory\n";
        }

        $outputDir = $getOpt->getOperand('output-dir');
        if ($outputDir === null) {
            $outputDir = getcwd();
            echo "Using $outputDir as output directory\n";
        }

        $logFile = $getOpt->getOperand('logfile');
        if ($logFile === null) {
            mkdir($outputDir.'/log');
            touch($outputDir.'/log/log.txt');
            $logFile = $outputDir.'/log/log.txt';
        }
        $this->setLogFile($logFile);
        $this->setOutputDir($outputDir);
        $this->setCorpusDir($corpusDir);
        $this->fuzz();
    }

    private function handleRunSingleCommand(GetOpt $getOpt): void {
        $inputPath = $getOpt->getOperand('input');
        if (!is_file($inputPath)) {
            throw new FuzzerException('Input "' . $inputPath . '" does not exist');
        }

        $input = file_get_contents($inputPath);
        $entry = $this->runInput($input);
        $entry->path = $inputPath;
        if ($entry->crashInfo) {
            $this->printCrash('CRASH', $entry);
        }
    }

    private function handleMinimizeCrashCommand(GetOpt $getOpt): void {
        if ($this->maxRuns === PHP_INT_MAX) {
            $this->maxRuns = 100000;
        }
        $this->minimizeCrash($getOpt->getOperand('input'));
    }

    private function handleReportCoverage(GetOpt $getOpt): void {
        $this->setCorpusDir($getOpt->getOperand('corpus'));
        $this->setCoverageDir($getOpt->getOperand('coverage-dir'));
        $this->loadCorpus();
        $this->renderCoverage();
    }

    private function setupTimeoutHandler(): void {
        if (\extension_loaded('pcntl')) {
            \pcntl_signal(SIGALRM, function() {
                throw new \Error("Timeout of {$this->timeout} seconds exceeded");
            });
            \pcntl_async_signals(true);
        }
    }

    private function setupErrorHandler(): void {
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return true;
            }

            throw new \Error(sprintf(
                '[%d] %s in %s on line %d', $errno, $errstr, $errfile, $errline));
        });
    }

    private function setupShutdownHandler(): void {
        // If a fatal error occurs, at least recover the crashing input.
        // TODO: We could support fork mode to continue fuzzing after this (and allow minimization).
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error === null || $error['type'] != E_ERROR || $this->lastInput === null) {
                return;
            }

            $crashInfo = "Fatal error: {$error['message']} in {$error['file']} on line {$error['line']}";
            $entry = new CorpusEntry($this->lastInput, [], $crashInfo);
            $entry->storeAtPath($this->outputDir . '/crash-' . $entry->hash . '.txt');
            $this->printCrash('CRASH', $entry);
        });
    }
}
