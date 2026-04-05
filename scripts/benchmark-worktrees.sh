#!/usr/bin/env bash
# Create git worktrees under .benchmark/ for parallel Docker builds.
# Requires: Docker-related files committed on each branch (same Dockerfile/compose across branches).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

mkdir -p .benchmark

add_worktree() {
  local path="$1"
  local branch="$2"
  local full_path="$ROOT/.benchmark/$path"

  if [[ -e "$full_path" ]]; then
    echo "[*] Skip (already exists): $full_path"
    return 0
  fi

  echo "[+] git worktree add .benchmark/$path $branch"
  git worktree add ".benchmark/$path" "$branch"
}

add_worktree optimal-weights feature/optimal-mutator-weights
add_worktree mutator-combinations feature/mutator-combinations
add_worktree adaptive-mutations feature/adaptive-mutations

# matator-optimizations is built from the main repo (.), not a worktree — git forbids
# the same branch in two worktrees. Before: git checkout matator-optimizations

echo "[✓] Worktrees ready. Ensure current branch is matator-optimizations, then:"
echo "    docker compose -f docker-compose.benchmark.yml build"
echo "    docker compose -f docker-compose.benchmark.yml up"
