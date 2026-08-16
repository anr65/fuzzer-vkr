#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPERIMENT_ID="${1:-yaml-memory-leak-hour-$(date +%Y%m%d-%H%M%S)}"

export TARGET_SOURCE="$ROOT_DIR/targets/yaml_target.php"
export SEED_SOURCE="${SEED_SOURCE:-$ROOT_DIR/corpora/yaml-v1/seeds/B04_depth_width.yaml}"
export DURATION_SECONDS="${DURATION_SECONDS:-3600}"
export MEMORY_LIMIT_MB="${MEMORY_LIMIT_MB:-128}"
export PRNG_SEED="${PRNG_SEED:-735457}"
export TARGET_TIMEOUT_SECONDS="${TARGET_TIMEOUT_SECONDS:-3}"
export STABILITY_INTERVAL="${STABILITY_INTERVAL:-1000}"

exec "$ROOT_DIR/scripts/run_memory_leak_end_to_end.sh" "$EXPERIMENT_ID"
