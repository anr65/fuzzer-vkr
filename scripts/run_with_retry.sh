#!/bin/bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <retries> <command...>"
  exit 2
fi

RETRIES="$1"
shift

attempt=1
while true; do
  echo "[run_with_retry] attempt=$attempt command=$*"
  if "$@"; then
    exit 0
  fi

  if [[ "$attempt" -ge "$RETRIES" ]]; then
    echo "[run_with_retry] failed after $attempt attempts"
    exit 1
  fi

  sleep_sec=$((attempt * 2))
  echo "[run_with_retry] retrying in ${sleep_sec}s"
  sleep "$sleep_sec"
  attempt=$((attempt + 1))
done
