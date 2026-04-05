#!/usr/bin/env bash
set -euo pipefail

# Defaults match docker-compose / benchmark plan: Laravel request_mix_3 + corpus/
TARGET="${TARGET:-request_mix_3/target.php}"
CORPUS="${CORPUS:-corpus}"
OUTPUT_DIR="${OUTPUT_DIR:-/app/output}"
LOGFILE="${LOGFILE:-${OUTPUT_DIR}/fuzzer.log}"
SEED="${SEED:-42}"
MEMORY_LIMIT_MB="${MEMORY_LIMIT_MB:-1024}"
MAX_TIME_SECONDS="${MAX_TIME_SECONDS:-7200}"
STABILITY_INTERVAL="${STABILITY_INTERVAL:-500}"
STABILITY_LOG="${STABILITY_LOG:-${OUTPUT_DIR}/stability.csv}"

mkdir -p "$OUTPUT_DIR"
mkdir -p "$(dirname "$LOGFILE")"

exec php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" "$OUTPUT_DIR" "$LOGFILE" \
  --memory-limit="$MEMORY_LIMIT_MB" \
  --max-time="$MAX_TIME_SECONDS" \
  --seed="$SEED" \
  --stability-log="$STABILITY_LOG" \
  --stability-format=csv \
  --stability-interval="$STABILITY_INTERVAL" \
  ${FUZZER_EXTRA_ARGS:-}
