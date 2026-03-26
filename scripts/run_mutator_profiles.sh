#!/bin/bash

# Runner for mutator profile experiments
# Tests different mutator combinations on YAML target

set -e

# Default values
TARGET="targets/yaml_target.php"
CORPUS="corpus_yaml/"
SEED=12345
TIME=60
PROFILES_FILE="config/mutator_profiles.json"
OUTPUT_DIR="mutator_profile_runs"

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
        --seed=*)
            SEED="${1#*=}"
            shift
            ;;
        --time=*)
            TIME="${1#*=}"
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--target=<path>] [--corpus=<dir>] [--seed=<int>] [--time=<seconds>]"
            exit 1
            ;;
    esac
done

# Validate files exist
if [ ! -f "$TARGET" ]; then
    echo "Error: Target file '$TARGET' does not exist"
    exit 1
fi

if [ ! -d "$CORPUS" ]; then
    echo "Error: Corpus directory '$CORPUS' does not exist"
    exit 1
fi

if [ ! -f "$PROFILES_FILE" ]; then
    echo "Error: Profiles file '$PROFILES_FILE' does not exist"
    exit 1
fi

# Create output directory
mkdir -p "$OUTPUT_DIR"

echo "=== Mutator Profile Experiment ==="
echo "Target: $TARGET"
echo "Corpus: $CORPUS"
echo "Time per run: $TIME seconds"
echo "Seed: $SEED"
echo "Output directory: $OUTPUT_DIR"
echo ""

# Extract profile names from JSON
PROFILES=$(php -r "
    \$profiles = json_decode(file_get_contents('$PROFILES_FILE'), true);
    if (\$profiles === null) {
        echo 'Error: Failed to parse profiles JSON';
        exit(1);
    }
    echo implode(' ', array_keys(\$profiles));
")

if [ -z "$PROFILES" ]; then
    echo "Error: No profiles found in $PROFILES_FILE"
    exit 1
fi

echo "Found profiles: $PROFILES"
echo ""

# Run experiment for each profile
for PROFILE in $PROFILES; do
    PROFILE_DIR="$OUTPUT_DIR/$PROFILE"
    mkdir -p "$PROFILE_DIR"
    
    echo "=== Running profile: $PROFILE ==="
    echo "Output directory: $PROFILE_DIR"
    
    # Run the fuzzer with this profile
    php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" "$PROFILE_DIR" "$PROFILE_DIR/log.txt" \
        --mutator-profile="$PROFILE" \
        --seed="$SEED" \
        --max-time="$TIME" \
        --stability-log="$PROFILE_DIR/stability.csv" \
        --stability-format=csv \
        --enable-corpus-diagnostics \
        --corpus-events-log="$PROFILE_DIR/events.csv" || true
    
    # Generate graph_data.csv from stability.csv
    if [ -f "$PROFILE_DIR/stability.csv" ]; then
        php -r "
            \$handle = fopen('$PROFILE_DIR/stability.csv', 'r');
            if (!\$handle) exit(1);
            
            \$output = fopen('$PROFILE_DIR/graph_data.csv', 'w');
            fputcsv(\$output, ['run_id', 'timestamp_relative_seconds', 'executions', 'coverage', 'corpus_size']);
            
            // Skip header
            fgetcsv(\$handle);
            
            while ((\$row = fgetcsv(\$handle)) !== false) {
                if (count(\$row) >= 4) {
                    fputcsv(\$output, [
                        '$PROFILE',
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
        echo "Warning: stability.csv not found for profile $PROFILE"
    fi
    
    echo "Profile $PROFILE completed"
    echo ""
done

echo "=== Experiment Complete ==="
echo "Results are in: $OUTPUT_DIR"
echo "Run aggregation script: php scripts/aggregate_mutator_profiles.php"
