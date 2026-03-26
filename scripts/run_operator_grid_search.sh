#!/bin/bash

set -euo pipefail

TARGET="targets/yaml_target.php"
CORPUS="corpus_yaml"
OUTPUT_DIR="operator_grid_runs"
TIME=60
REPEATS=3
SEED=7000
POLICY="adaptive"

ALPHAS=(0.05 0.1 0.2 0.3 0.5)
PERIODS=(500 1000 2000 5000)

while [[ $# -gt 0 ]]; do
  case $1 in
    --target=*) TARGET="${1#*=}" ;;
    --corpus=*) CORPUS="${1#*=}" ;;
    --output-dir=*) OUTPUT_DIR="${1#*=}" ;;
    --time=*) TIME="${1#*=}" ;;
    --repeats=*) REPEATS="${1#*=}" ;;
    --seed=*) SEED="${1#*=}" ;;
    --policy=*) POLICY="${1#*=}" ;;
    *)
      echo "Unknown option: $1"
      exit 1
      ;;
  esac
  shift
done

mkdir -p "$OUTPUT_DIR"

combination=0
for alpha in "${ALPHAS[@]}"; do
  for period in "${PERIODS[@]}"; do
    combination=$((combination + 1))
    combo_id="a${alpha}_p${period}"
    echo "Grid combo $combination/20: alpha=$alpha period=$period policy=$POLICY"

    for repeat in $(seq 1 "$REPEATS"); do
      run_seed=$((SEED + repeat - 1))
      run_dir="$OUTPUT_DIR/$combo_id/repeat_$repeat"
      run_corpus="$run_dir/corpus"
      mkdir -p "$run_dir"
      rm -rf "$run_corpus"
      cp -R "$CORPUS" "$run_corpus"

      extra_args=(
        "--operator-policy=$POLICY"
        "--operator-alpha=$alpha"
        "--operator-rebalance-period=$period"
        "--operator-warmup-iterations=$((period * 2))"
        "--operator-decay-mode=reset"
        "--operator-weights-log=$run_dir/operator_weights.jsonl"
      )
      if [[ "$POLICY" == "class-adaptive" ]]; then
        extra_args+=("--seed-classifier=yaml")
      fi

      php bin/php-fuzzer fuzz "$TARGET" "$run_corpus" "$run_dir" "$run_dir/log.txt" \
        --seed="$run_seed" \
        --max-time="$TIME" \
        --stability-log="$run_dir/stability.csv" \
        --stability-format=csv \
        --enable-corpus-diagnostics \
        --corpus-events-log="$run_dir/events.csv" \
        "${extra_args[@]}" || true
    done
  done
done

echo "Completed grid search: 20 combinations x $REPEATS repeats"
