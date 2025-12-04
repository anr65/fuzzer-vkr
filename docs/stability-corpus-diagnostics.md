# Stability Corpus Diagnostics

## Overview

The corpus diagnostics system provides deep instrumentation to analyze corpus evolution problems, specifically designed to understand why corpus stagnation occurs and how seeds contribute (or fail to contribute) to new coverage.

## Features

### 1. Per-Seed Lifecycle Tracking

Each seed in the corpus is tracked with the following statistics:

- **seed_id**: Unique identifier for the seed
- **origin_hash**: Hash of the parent input that created this seed
- **creation_run**: Run number when the seed was added to corpus
- **times_selected**: How many times this seed was chosen for mutation
- **times_contributed**: How many times mutations from this seed produced new coverage
- **last_contribution_run**: Last run when this seed contributed
- **is_dead**: Flag indicating if seed is considered "dead" (no recent contributions)
- **coverage_at_creation**: Number of unique features when seed was created
- **size**: Size of the seed input in bytes

### 2. Corpus Event Logging

All candidate seeds that could potentially be added to the corpus are logged to `stability_corpus_events.csv`:

**Columns:**
- `run`: Run number
- `parent_seed_id`: ID of the seed that produced this candidate
- `input_hash`: MD5 hash of the candidate input
- `size`: Size of the candidate input
- `coverage_before`: Coverage count before adding candidate
- `coverage_after`: Coverage count after adding candidate
- `delta`: Coverage delta (after - before)
- `decision`: `accepted` or `rejected`
- `reason`: Reason for decision:
  - `new_coverage`: Accepted due to new unique features
  - `minimization`: Accepted as smaller replacement
  - `no_unique_features`: Rejected - no new unique features
  - `duplicate_hash`: Rejected - hash already exists in corpus

### 3. Corpus Snapshots

Periodic snapshots of the entire corpus state are saved as JSON files:

**File format:** `stability_corpus_snapshot_<run>.json`

**Contents:**
- Run number and timestamp
- Complete list of all seeds with their statistics
- Allows post-analysis of corpus drift and seed lifecycle

### 4. Extended Stability Metrics

Enhanced metrics added to stability logs:

- **avg_seed_age**: Average age of seeds in runs
- **active_seeds**: Number of seeds that contributed in recent window
- **dead_seeds**: Number of seeds marked as dead
- **dead_selection_percentage**: % of selections that chose dead seeds
- **contribution_rate**: Overall contribution rate (% of runs that produced new coverage)

## Usage

### Enable Diagnostics

```bash
php bin/php-fuzzer fuzz target.php corpus/ output/ log.txt \
    --enable-corpus-diagnostics \
    --corpus-events-log=corpus_events.csv \
    --corpus-snapshot-frequency=2000 \
    --stability-log=stability.csv
```

### Command-Line Options

- `--enable-corpus-diagnostics`: Enable diagnostic instrumentation (required)
- `--corpus-events-log=<file>`: Path to corpus events CSV file (default: `corpus_events.csv`)
- `--corpus-snapshot-frequency=<runs>`: Create snapshot every N runs (default: 2000)

## Interpreting Results

### Understanding Seed Lifecycle

1. **Active Seeds**: Seeds that contributed to new coverage in the recent window (last 1000 runs or half of total runs, whichever is smaller)
2. **Dead Seeds**: Seeds that haven't contributed in the recent window
3. **Contribution Rate**: Percentage of runs that resulted in new coverage

### Analyzing Stagnation

When corpus size stays constant despite new coverage:

1. Check `corpus_events.csv` for rejected candidates:
   - Look for patterns in rejection reasons
   - Count how many candidates were rejected vs accepted

2. Analyze seed statistics in snapshots:
   - Which seeds are selected most often?
   - Which seeds contribute most?
   - Are dead seeds being selected frequently?

3. Review extended metrics:
   - High `dead_selection_percentage` indicates inefficient seed selection
   - Low `contribution_rate` indicates overall stagnation
   - Increasing `avg_seed_age` suggests corpus is not evolving

### Example Analysis Scenarios

#### Scenario 1: Corpus Not Growing

**Symptoms:**
- Corpus size constant at 22 entries
- New coverage appears but corpus doesn't grow

**Diagnosis:**
1. Check `corpus_events.csv` - are candidates being rejected?
2. Look for `decision=rejected` entries with reason `no_unique_features`
3. Check if accepted entries are replacing existing ones (minimization)

**Possible Causes:**
- All new coverage is already covered by existing seeds
- Minimization is replacing seeds instead of adding new ones
- Unique feature detection is too strict

#### Scenario 2: Dead Seeds Dominating

**Symptoms:**
- High `dead_selection_percentage`
- Low `contribution_rate`
- Many seeds with `times_contributed=0`

**Diagnosis:**
1. Review snapshots to identify dead seeds
2. Check if dead seeds are being selected frequently
3. Analyze why dead seeds aren't producing new coverage

**Possible Causes:**
- Seed selection is not weighted by contribution history
- Dead seeds represent exhausted code paths
- Mutation strategies are not diverse enough

#### Scenario 3: Low Contribution Rate

**Symptoms:**
- `contribution_rate` < 5%
- Long stagnation periods
- Most runs produce no new coverage

**Diagnosis:**
1. Check which seeds are contributing
2. Analyze coverage deltas in events log
3. Review seed age distribution

**Possible Causes:**
- Fuzzing has reached saturation
- Mutation strategies need improvement
- Target has limited code paths

## Implementation Details

### Seed Registration

Seeds are registered when:
1. Loading initial corpus (during `loadCorpus()`)
2. Adding new entries (during `addEntry()`)
3. Replacing entries (during `replaceEntry()`)

### Dead Seed Detection

Seeds are marked as dead if:
- They have never contributed (`last_contribution_run === null`) AND were created before the threshold run
- OR their last contribution was before the threshold run

Threshold run = current_run - min(1000, current_run / 2)

### Snapshot Frequency

Snapshots are created:
- Every N runs (configurable via `--corpus-snapshot-frequency`)
- At the end of fuzzing (final snapshot)

## Performance Impact

Diagnostics are designed to be lightweight:

- Event logging: O(1) per candidate seed
- Seed tracking: O(1) per selection/contribution
- Snapshot creation: O(N) where N = corpus size, but only every N runs

When disabled (default), there is **zero performance impact**.

## Future Improvements

Potential enhancements based on diagnostic insights:

1. **Adaptive Seed Selection**: Weight selection by contribution history
2. **Dead Seed Removal**: Periodically remove seeds that haven't contributed
3. **Coverage-Based Prioritization**: Prioritize seeds that cover rare code paths
4. **Mutation Strategy Tuning**: Adjust mutation strategies based on seed performance

