#!/usr/bin/env bash
# Periodic health check during docker-compose.benchmark.yml runs (default: 6 × 30 min = 3 h).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose -f docker-compose.benchmark.yml)
LOG="${BENCHMARK_WATCHDOG_LOG:-docker-output/benchmark/watchdog.log}"
mkdir -p "$(dirname "$LOG")"
ROUNDS="${BENCHMARK_WATCHDOG_ROUNDS:-6}"
SLEEP_SEC="${BENCHMARK_WATCHDOG_INTERVAL_SEC:-1800}"

echo "[$(date -Iseconds)] watchdog start rounds=$ROUNDS interval_s=$SLEEP_SEC" >>"$LOG"
{
  echo "===== $(date -Iseconds) check #0 (immediate) ====="
  "${COMPOSE[@]}" ps -a || true
  docker stats --no-stream 2>/dev/null || true
} >>"$LOG" 2>&1

for i in $(seq 1 "$ROUNDS"); do
  sleep "$SLEEP_SEC"
  {
    echo "===== $(date -Iseconds) check #$i/$ROUNDS ====="
    "${COMPOSE[@]}" ps -a || true
    "${COMPOSE[@]}" up -d || true
    docker stats --no-stream 2>/dev/null || true
  } >>"$LOG" 2>&1
done
echo "[$(date -Iseconds)] watchdog done" >>"$LOG"
