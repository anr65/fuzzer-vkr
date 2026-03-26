#!/bin/bash

# Automated multi-run determinism experiment driver
# Runs the fuzzer multiple times with identical conditions to measure stability

set -e

# Default values
TARGET=""
CORPUS=""
RUNS=10
TIME=300
SEED=12345
OUTPUT_DIR="determinism_runs"

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --target=*)
            TARGET="${1#*=}"
            shift
            ;;
        --corpus=*)
            CORPUS="${1#*=}"
            shift
            ;;
        --runs=*)
            RUNS="${1#*=}"
            shift
            ;;
        --time=*)
            TIME="${1#*=}"
            shift
            ;;
        --seed=*)
            SEED="${1#*=}"
            shift
            ;;
        --output-dir=*)
            OUTPUT_DIR="${1#*=}"
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 --target=<path> --corpus=<dir> [--runs=10] [--time=300] [--seed=12345] [--output-dir=determinism_runs]"
            exit 1
            ;;
    esac
done

# Validate required arguments
if [ -z "$TARGET" ] || [ -z "$CORPUS" ]; then
    echo "Error: --target and --corpus are required"
    echo "Usage: $0 --target=<path> --corpus=<dir> [--runs=10] [--time=300] [--seed=12345]"
    exit 1
fi

# Validate target exists
if [ ! -f "$TARGET" ]; then
    echo "Error: Target file '$TARGET' does not exist"
    exit 1
fi

# Validate corpus exists
if [ ! -d "$CORPUS" ]; then
    echo "Error: Corpus directory '$CORPUS' does not exist"
    exit 1
fi

# Create output directory
DETERMINISM_DIR="$OUTPUT_DIR"
mkdir -p "$DETERMINISM_DIR"

echo "=== Determinism Experiment Configuration ==="
echo "Target: $TARGET"
echo "Corpus: $CORPUS"
echo "Runs: $RUNS"
echo "Time per run: $TIME seconds"
echo "Seed: $SEED"
echo "Output directory: $DETERMINISM_DIR"
echo ""

# Create a temporary corpus copy for each run to ensure isolation
TEMP_CORPUS_BASE=$(mktemp -d)
echo "Using temporary corpus base: $TEMP_CORPUS_BASE"

# Function to copy corpus
copy_corpus() {
    local dest="$1"
    rm -rf "$dest"
    mkdir -p "$dest"
    cp -r "$CORPUS"/* "$dest/" 2>/dev/null || true
}

# Run experiments
for i in $(seq 1 $RUNS); do
    RUN_DIR="$DETERMINISM_DIR/run_$i"
    mkdir -p "$RUN_DIR"
    
    # Create isolated corpus copy for this run
    RUN_CORPUS="$TEMP_CORPUS_BASE/corpus_$i"
    copy_corpus "$RUN_CORPUS"
    
    echo "=== Run $i/$RUNS ==="
    echo "Run directory: $RUN_DIR"
    echo "Corpus: $RUN_CORPUS"
    
    # Run the fuzzer with deterministic settings
    # Note: We'll generate graph_data.csv from stability.csv after the run
    php bin/php-fuzzer fuzz "$TARGET" "$RUN_CORPUS" "$RUN_DIR" "$RUN_DIR/log.txt" \
        --seed="$SEED" \
        --max-time="$TIME" \
        --stability-log="$RUN_DIR/stability.csv" \
        --stability-format=csv \
        --enable-corpus-diagnostics \
        --corpus-events-log="$RUN_DIR/events.csv" || true
    
    # Generate graph_data.csv from stability.csv
    # Extract: timestamp, runs, unique_features, corpus_size
    # Use PHP for proper CSV parsing to handle quoted fields
    if [ -f "$RUN_DIR/stability.csv" ]; then
        php -r "
            \$handle = fopen('$RUN_DIR/stability.csv', 'r');
            if (!\$handle) exit(1);
            
            \$output = fopen('$RUN_DIR/graph_data.csv', 'w');
            fputcsv(\$output, ['run_id', 'timestamp_relative_seconds', 'executions', 'coverage', 'corpus_size']);
            
            // Skip header
            fgetcsv(\$handle);
            
            while ((\$row = fgetcsv(\$handle)) !== false) {
                if (count(\$row) >= 4) {
                    fputcsv(\$output, [
                        'run_$i',
                        \$row[0],  // timestamp
                        \$row[1],  // runs
                        \$row[2],  // unique_features (coverage)
                        \$row[3]   // corpus_size
                    ]);
                }
            }
            
            fclose(\$handle);
            fclose(\$output);
        "
        echo "Generated graph_data.csv"
    else
        echo "Warning: stability.csv not found for run $i"
    fi
    
    echo "Run $i completed"
    echo ""
done

# Cleanup temporary corpus
rm -rf "$TEMP_CORPUS_BASE"

echo "=== Experiment Complete ==="
echo "Results are in: $DETERMINISM_DIR"
echo "Run aggregation script: php scripts/aggregate_determinism_results.php"
