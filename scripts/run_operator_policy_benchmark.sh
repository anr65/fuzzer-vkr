#!/bin/bash

set -euo pipefail

TARGET="targets/yaml_target.php"
CORPUS="corpus_yaml"
OUTPUT_DIR="operator_policy_runs"
TIME=60
REPEATS=3
SEED=12345
ALPHA=0.2
PERIOD=1000
WARMUP=""
DECAY_MODE="reset"
DECAY_FACTOR=0.5
WEIGHTS_CONFIG="config/operator_weights.json"

while [[ $# -gt 0 ]]; do
  case $1 in
    --target=*) TARGET="${1#*=}" ;;
    --corpus=*) CORPUS="${1#*=}" ;;
    --output-dir=*) OUTPUT_DIR="${1#*=}" ;;
    --time=*) TIME="${1#*=}" ;;
    --repeats=*) REPEATS="${1#*=}" ;;
    --seed=*) SEED="${1#*=}" ;;
    --alpha=*) ALPHA="${1#*=}" ;;
    --period=*) PERIOD="${1#*=}" ;;
    --warmup=*) WARMUP="${1#*=}" ;;
    --decay-mode=*) DECAY_MODE="${1#*=}" ;;
    --decay-factor=*) DECAY_FACTOR="${1#*=}" ;;
    --weights-config=*) WEIGHTS_CONFIG="${1#*=}" ;;
    *)
      echo "Unknown option: $1"
      exit 1
      ;;
  esac
  shift
done

if [[ ! -f "$TARGET" ]]; then
  echo "Target does not exist: $TARGET"
  exit 1
fi
if [[ ! -d "$CORPUS" ]]; then
  echo "Corpus does not exist: $CORPUS"
  exit 1
fi

mkdir -p "$OUTPUT_DIR"

MODES=("uniform" "static" "adaptive" "class-adaptive")

for mode in "${MODES[@]}"; do
  for repeat in $(seq 1 "$REPEATS"); do
    run_seed=$((SEED + repeat - 1))
    run_dir="$OUTPUT_DIR/$mode/repeat_$repeat"
    run_corpus="$run_dir/corpus"
    mkdir -p "$run_dir"
    rm -rf "$run_corpus"
    cp -R "$CORPUS" "$run_corpus"

    echo "Running mode=$mode repeat=$repeat seed=$run_seed"

    extra_args=()
    extra_args+=("--operator-policy=$mode")
    extra_args+=("--operator-alpha=$ALPHA")
    extra_args+=("--operator-rebalance-period=$PERIOD")
    extra_args+=("--operator-decay-mode=$DECAY_MODE")
    extra_args+=("--operator-decay-factor=$DECAY_FACTOR")
    extra_args+=("--operator-weights-log=$run_dir/operator_weights.jsonl")
    if [[ -n "$WARMUP" ]]; then
      extra_args+=("--operator-warmup-iterations=$WARMUP")
    fi

    if [[ "$mode" == "static" ]]; then
      extra_args+=("--operator-weights-config=$WEIGHTS_CONFIG")
    fi
    if [[ "$mode" == "class-adaptive" ]]; then
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

echo "Completed benchmark runs in $OUTPUT_DIR"
