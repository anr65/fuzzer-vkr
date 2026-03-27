#!/usr/bin/env bash
set -euo pipefail
# Runs all 4 hypothesis experiments with baseline comparison
# Usage: bash experiments/run_optimization_experiments.sh
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

TARGET="${TARGET:-targets/yaml_target.php}"
CORPUS="${CORPUS:-corpus_yaml}"
SEED="${SEED:-12345}"
RUNS="${RUNS:-5000}"
INTERVAL="${INTERVAL:-250}"

mkdir -p results output/baseline output/h41 output/h42 output/h43 output/h44 log

php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" output/baseline/ log/baseline.txt \
  --seed="$SEED" --max-runs="$RUNS" \
  --stability-log=results/baseline_stability.csv \
  --stability-format=csv --stability-interval="$INTERVAL"

php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" output/h41/ log/h41.txt \
  --seed="$SEED" --max-runs="$RUNS" \
  --adaptive-mutators --mutator-disable-threshold=0.01 \
  --mutator-eval-window=500 \
  --mutator-stats-log=results/h41_mutator_stats.csv \
  --stability-log=results/h41_stability.csv \
  --stability-format=csv --stability-interval="$INTERVAL"

php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" output/h42/ log/h42.txt \
  --seed="$SEED" --max-runs="$RUNS" \
  --seed-scheduling=weighted \
  --seed-lifecycle-log=results/h42_lifecycle.csv \
  --seed-lifecycle-interval="$INTERVAL" \
  --stability-log=results/h42_stability.csv \
  --stability-format=csv --stability-interval="$INTERVAL"

php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" output/h43/ log/h43.txt \
  --seed="$SEED" --max-runs="$RUNS" \
  --corpus-admission=relaxed --corpus-admission-threshold=1 \
  --corpus-admission-log=results/h43_admission.csv \
  --stability-log=results/h43_stability.csv \
  --stability-format=csv --stability-interval="$INTERVAL"

php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" output/h44/ log/h44.txt \
  --seed="$SEED" --max-runs="$RUNS" \
  --yaml-cache \
  --yaml-cache-stats-log=results/h44_cache_stats.json \
  --yaml-cache-stats-interval=1000 \
  --stability-log=results/h44_stability.csv \
  --stability-format=csv --stability-interval="$INTERVAL"

echo "All experiments complete. Results in results/"
