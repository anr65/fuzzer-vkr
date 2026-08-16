#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPERIMENT_ID="${1:-memory-leak-e2e-$(date +%Y%m%d-%H%M%S)}"
DURATION_SECONDS="${DURATION_SECONDS:-90}"
MEMORY_LIMIT_MB="${MEMORY_LIMIT_MB:-128}"
PRNG_SEED="${PRNG_SEED:-735457}"
STABILITY_INTERVAL="${STABILITY_INTERVAL:-250}"
TARGET_TIMEOUT_SECONDS="${TARGET_TIMEOUT_SECONDS:-5}"

RUN_ROOT="$ROOT_DIR/corpora/runs/$EXPERIMENT_ID"
TARGET="${TARGET_SOURCE:-$ROOT_DIR/request_mix_3/target.php}"
SEED_SOURCE="${SEED_SOURCE:-$ROOT_DIR/corpora/memory-leak-ab-v1/B04_depth_width.yaml}"
LEGACY_PRELOAD="$ROOT_DIR/test/fixtures/LegacyRetainingCorpus.php"

if [[ -e "$RUN_ROOT" ]]; then
  echo "Experiment directory already exists: $RUN_ROOT" >&2
  exit 3
fi
if [[ ! -f "$SEED_SOURCE" ]]; then
  echo "Seed file does not exist: $SEED_SOURCE" >&2
  exit 4
fi
if [[ ! -f "$TARGET" ]]; then
  echo "Target file does not exist: $TARGET" >&2
  exit 5
fi

mkdir -p "$RUN_ROOT"

sha256_file() {
  local path="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$path" | awk '{print $1}'
  else
    shasum -a 256 "$path" | awk '{print $1}'
  fi
}

sample_rss() {
  local root_pid="$1"
  local output="$2"
  local started="$3"
  printf 'elapsed_sec,parent_pid,parent_rss_kb,worker_pids,worker_rss_kb,total_rss_kb\n' > "$output"
  while kill -0 "$root_pid" 2>/dev/null; do
    local now elapsed parent_rss worker_pids worker_rss total_rss
    now="$(date +%s)"
    elapsed="$((now - started))"
    parent_rss="$(ps -o rss= -p "$root_pid" 2>/dev/null | awk '{print $1+0}')"
    worker_pids="$(pgrep -P "$root_pid" 2>/dev/null | paste -sd: - || true)"
    worker_rss=0
    if [[ -n "$worker_pids" ]]; then
      local pid
      IFS=':' read -r -a pid_list <<< "$worker_pids"
      for pid in "${pid_list[@]}"; do
        worker_rss="$((worker_rss + $(ps -o rss= -p "$pid" 2>/dev/null | awk '{print $1+0}')))"
      done
    fi
    total_rss="$((parent_rss + worker_rss))"
    printf '%s,%s,%s,%s,%s,%s\n' "$elapsed" "$root_pid" "$parent_rss" "$worker_pids" "$worker_rss" "$total_rss" >> "$output"
    sleep 1
  done
}

run_variant() {
  local variant="$1"
  local variant_dir="$RUN_ROOT/$variant"
  local corpus_dir="$variant_dir/corpus"
  local output_dir="$variant_dir/output"
  mkdir -p "$corpus_dir" "$output_dir" "$variant_dir/coverage"
  cp "$SEED_SOURCE" "$corpus_dir/seed.yaml"

  local -a command=(php)
  if [[ "$variant" == "baseline" ]]; then
    command+=(-d "auto_prepend_file=$LEGACY_PRELOAD")
  fi
  command+=(
    "$ROOT_DIR/bin/php-fuzzer" fuzz
    "$TARGET" "$corpus_dir" "$output_dir" "$variant_dir/fuzzer.log"
    "--seed=$PRNG_SEED"
    "--max-time=$DURATION_SECONDS"
    "--timeout=$TARGET_TIMEOUT_SECONDS"
    "--memory-limit=$MEMORY_LIMIT_MB"
    --max-crashes=1000000
    "--stability-log=$variant_dir/stability.csv"
    --stability-format=csv
    "--stability-interval=$STABILITY_INTERVAL"
  )

  local started pid sampler_pid exit_code
  started="$(date +%s)"
  set +e
  "${command[@]}" > "$variant_dir/stdout.log" 2> "$variant_dir/stderr.log" &
  pid=$!
  set -e
  sample_rss "$pid" "$variant_dir/rss.csv" "$started" &
  sampler_pid=$!

  set +e
  wait "$pid"
  exit_code=$?
  wait "$sampler_pid" 2>/dev/null
  set -e
  printf '%s\n' "$exit_code" > "$variant_dir/exit_code.txt"

  set +e
  php "$ROOT_DIR/bin/php-fuzzer" report-coverage "$TARGET" "$corpus_dir" "$variant_dir/coverage" \
    > "$variant_dir/coverage.stdout.log" 2> "$variant_dir/coverage.stderr.log"
  printf '%s\n' "$?" > "$variant_dir/coverage_exit_code.txt"
  set -e
}

printf '{"experiment_id":"%s","target":"%s","target_sha256":"%s","seed_file":"%s","seed_sha256":"%s","seed_bytes":%s,"prng_seed":%s,"duration_seconds":%s,"memory_limit_mb":%s,"target_timeout_seconds":%s,"supervisor_restarts":false,"variant_order":["baseline","fixed"]}\n' \
  "$EXPERIMENT_ID" "$TARGET" "$(sha256_file "$TARGET")" "$SEED_SOURCE" "$(sha256_file "$SEED_SOURCE")" "$(wc -c < "$SEED_SOURCE" | tr -d ' ')" "$PRNG_SEED" "$DURATION_SECONDS" "$MEMORY_LIMIT_MB" "$TARGET_TIMEOUT_SECONDS" \
  > "$RUN_ROOT/metadata.json"

run_variant baseline
run_variant fixed

echo "$RUN_ROOT"
