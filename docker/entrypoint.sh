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
# Supervisor loop: set to 0/false to run a single php process (no restarts).
FUZZER_RESTART_ON_MEMORY_LIMIT="${FUZZER_RESTART_ON_MEMORY_LIMIT:-1}"

mkdir -p "$OUTPUT_DIR"
mkdir -p "$(dirname "$LOGFILE")"

run_fuzzer() {
  php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" "$OUTPUT_DIR" "$LOGFILE" \
    --memory-limit="$MEMORY_LIMIT_MB" \
    --max-time="$MAX_TIME_SECONDS" \
    --seed="$SEED" \
    --stability-log="$STABILITY_LOG" \
    --stability-format=csv \
    --stability-interval="$STABILITY_INTERVAL" \
    ${FUZZER_EXTRA_ARGS:-}
}

case "${FUZZER_RESTART_ON_MEMORY_LIMIT}" in
  1|true|True|yes|YES)
    while true; do
      set +e
      run_fuzzer
      code=$?
      set -e
      if [[ "$code" -eq 0 ]]; then
        exit 0
      fi
      # Exit 1: CLI/operand errors from php-fuzzer — do not spin forever.
      if [[ "$code" -eq 1 ]]; then
        exit 1
      fi
      ts=$(date -Iseconds)
      if [[ "$code" -eq 42 ]]; then
        msg="[$ts] entrypoint: memory budget exceeded (exit 42, MEMORY_LIMIT_MB=$MEMORY_LIMIT_MB); restarting fuzzer"
      else
        msg="[$ts] entrypoint: fuzzer exited with code $code; restarting fuzzer"
      fi
      echo "$msg" >>"$LOGFILE"
      echo "$msg" >&2
      continue
    done
    ;;
  *)
    exec php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" "$OUTPUT_DIR" "$LOGFILE" \
      --memory-limit="$MEMORY_LIMIT_MB" \
      --max-time="$MAX_TIME_SECONDS" \
      --seed="$SEED" \
      --stability-log="$STABILITY_LOG" \
      --stability-format=csv \
      --stability-interval="$STABILITY_INTERVAL" \
      ${FUZZER_EXTRA_ARGS:-}
    ;;
esac
