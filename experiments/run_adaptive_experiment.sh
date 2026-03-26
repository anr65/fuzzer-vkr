#!/bin/bash
set -euo pipefail

if [ "$#" -ne 5 ]; then
  echo "Usage: $0 TARGET_PHP CORPUS_DIR OUTPUT_BASE SEED MAX_RUNS"
  exit 1
fi

TARGET_PHP="$1"
CORPUS_DIR="$2"
OUTPUT_BASE="$3"
SEED="$4"
MAX_RUNS="$5"

mkdir -p "$OUTPUT_BASE"

GIT_HASH="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
TS="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

run_condition() {
  local condition="$1"
  shift
  local out="$OUTPUT_BASE/$condition"
  mkdir -p "$out"

  php -r "
  file_put_contents('$out/metadata.json', json_encode([
      'git_hash' => '$GIT_HASH',
      'timestamp' => '$TS',
      'php_version' => '$PHP_VERSION',
      'seed' => (int) '$SEED',
      'max_runs' => (int) '$MAX_RUNS',
      'condition' => '$condition',
      'flags' => explode(' ', trim('$*')),
  ], JSON_PRETTY_PRINT));
  "

  php bin/php-fuzzer fuzz "$TARGET_PHP" "$CORPUS_DIR" "$out" "$out/log.txt" \
    --seed="$SEED" \
    --max-runs="$MAX_RUNS" \
    --stability-log="$out/stability.csv" \
    --stability-format=csv \
    "$@" || true
}

run_condition baseline
run_condition structural --structural-mutator-selector
run_condition type_aware --type-aware-weight=0.25
run_condition str_crossover --structural-crossover
run_condition adaptive --adaptive-scheduler

php experiments/compare_conditions.php "$OUTPUT_BASE"

