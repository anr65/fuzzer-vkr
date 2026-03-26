# Deterministic Fuzzing Experiments

## Purpose

Deterministic fuzzing experiments measure the **stability of the fuzzer through determinism** by running the fuzzer multiple times with identical input conditions and identical seeds, then comparing coverage evolution across executions.

The scientific goal is to understand:
- How reproducible are fuzzing results?
- How much variance exists between runs with identical conditions?
- Are there non-deterministic factors affecting fuzzer behavior?

## How Deterministic Seeding Works

### The `--seed` Flag

The `--seed` flag enables deterministic random number generation:

```bash
php bin/php-fuzzer fuzz target.php corpus/ output/ log.txt --seed=12345
```

**Behavior:**
- If `--seed` is provided: All random number generators inside the fuzzer use this seed value
- RNG is deterministic across runs with the same seed
- Seed is reset and used BEFORE any mutation or corpus operation

**Deterministic Guarantee:**
> If the seed and corpus are identical, the fuzzer should generate identical mutation sequences.

### Without `--seed`

If `--seed` is not provided, the fuzzer uses non-deterministic seeding (default behavior remains unchanged), which is useful for normal fuzzing but not for stability experiments.

## Running Determinism Experiments

### Quick Start

```bash
# Run 10 fuzzing executions, 5 minutes each, with seed 12345
scripts/run_determinism_experiment.sh \
    --target=example/target_simple.php \
    --corpus=corpus/ \
    --runs=10 \
    --time=300 \
    --seed=12345
```

### Experiment Script Parameters

The `scripts/run_determinism_experiment.sh` script accepts:

- `--target=<path_to_target>` (required): Path to the fuzzing target PHP file
- `--corpus=<corpus_dir>` (required): Path to the initial corpus directory
- `--runs=10` (optional): Number of fuzzer executions (default: 10)
- `--time=300` (optional): Time limit per run in seconds (default: 300 = 5 minutes)
- `--seed=12345` (optional): Random seed for deterministic runs (default: 12345)

### What the Experiment Does

For each execution `i` (from 1 to `runs`):

1. Creates an isolated directory: `determinism_runs/run_i/`
2. Creates an isolated corpus copy to ensure complete isolation
3. Runs the fuzzer with:
   - `--seed=<seed>`: Deterministic seed
   - `--max-time=<time>`: Time limit per run
   - `--stability-log=determinism_runs/run_i/stability.csv`: Stability metrics
   - `--stability-format=csv`: CSV format for easy processing
   - `--enable-corpus-diagnostics`: Enable corpus diagnostic instrumentation
   - `--corpus-events-log=determinism_runs/run_i/events.csv`: Corpus events log
4. Generates `graph_data.csv` with normalized time-series data

### Output Structure

```
determinism_runs/
├── run_1/
│   ├── stability.csv          # Full stability metrics
│   ├── graph_data.csv         # Normalized time-series (run_id, t, executions, coverage, corpus_size)
│   ├── events.csv             # Corpus events log
│   └── log.txt                # Fuzzer log
├── run_2/
│   └── ...
└── run_10/
    └── ...
```

## Generating Aggregate Results

After running the experiment, aggregate all runs into a unified timeline:

```bash
php scripts/aggregate_determinism_results.php
```

This produces `determinism_summary.csv` with the format:

```csv
run_id,t,coverage,corpus_size
run_1,0.00,10,5
run_1,1.23,15,7
run_2,0.00,10,5
run_2,1.23,15,7
...
```

**Features:**
- All runs share a common timeline (aligned by `t` = seconds since run start)
- Missing samples are approximated via last-known-value filling
- Ready for plotting and statistical analysis

## Interpreting Results

### Coverage Overlay

Plot `coverage(t)` for all runs on the same graph. This shows:
- **Convergence**: Do all runs reach similar coverage?
- **Variance**: How much do runs differ at each time point?
- **Determinism**: Perfect determinism would show identical curves

### Variance Analysis

Calculate variance in coverage across runs at each time point:
- Low variance = high determinism/stability
- High variance = non-deterministic factors present

### Corpus Size Evolution

Plot `corpus_size(t)` to see if corpus growth is deterministic:
- Deterministic runs should show identical corpus growth patterns
- Differences indicate non-deterministic seed selection or mutation paths

### Delta Coverage

Calculate `delta_coverage(t) = coverage(t) - coverage(t-1)` to see:
- When new coverage is discovered
- Whether discovery timing is deterministic

## Example Analysis Workflow

```bash
# 1. Run experiment
scripts/run_determinism_experiment.sh \
    --target=example/target_simple.php \
    --corpus=corpus/ \
    --runs=10 \
    --time=300 \
    --seed=12345

# 2. Aggregate results
php scripts/aggregate_determinism_results.php

# 3. Analyze with Python/R/your tool
python scripts/plot_determinism.py determinism_summary.csv
```

## Validation Criteria

Before considering the implementation complete, verify:

1. **Identical Outputs**: Fuzzer produces identical `stability.csv` outputs when:
   - Same seed + same corpus + same time limit
   - (Except for timestamps, which are relative)

2. **Different Seeds**: Multiple runs with different seeds produce different trajectories

3. **Graph Data**: `graph_data.csv` is created correctly for each run with:
   - `run_id`: Identifier for the run
   - `timestamp_relative_seconds`: Seconds since start of run
   - `executions`: Number of fuzzer executions
   - `coverage`: Unique features discovered
   - `corpus_size`: Number of corpus entries

4. **Aggregation**: `determinism_summary.csv` merges all runs into a unified timeline with proper last-known-value filling

## Technical Details

### RNG Seeding

The fuzzer uses PHP's `mt_srand()` to seed the Mersenne Twister random number generator. All random operations (mutations, corpus selection, etc.) use this seeded generator.

### Isolation

Each run uses:
- Isolated corpus copy (prevents cross-run contamination)
- Separate output directory
- Separate log files

### Time Normalization

All timestamps are converted to "seconds since start of run" to enable comparison across runs that may have started at different absolute times.

## Troubleshooting

### Runs produce different results with same seed

Possible causes:
- Corpus files differ between runs (check corpus isolation)
- Non-deterministic code paths in target
- System-level non-determinism (file system, timing)

### Missing graph_data.csv

The script generates `graph_data.csv` from `stability.csv` after each run. If missing:
- Check that `stability.csv` was created
- Verify the run completed successfully
- Check script permissions

### Aggregation fails

- Ensure `determinism_runs/` directory exists
- Verify at least one `run_*/graph_data.csv` file exists
- Check file permissions
