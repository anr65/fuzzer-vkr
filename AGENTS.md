# Agent notes

## Docker benchmark on a server

For step-by-step instructions to run four parallel fuzzer containers on a VPS (git worktrees, Compose, artifacts, troubleshooting), read [docs/SERVER_BENCHMARK_AGENT.md](docs/SERVER_BENCHMARK_AGENT.md).

## Key files

- [Dockerfile](Dockerfile) — PHP 8.4 CLI, `pcntl` / `mbstring` / `zip`, Composer deps (Laravel 12)
- [docker/entrypoint.sh](docker/entrypoint.sh) — `php bin/php-fuzzer fuzz` with env-driven limits and stability CSV
- [docker-compose.yml](docker-compose.yml) — single-service smoke run
- [docker-compose.benchmark.yml](docker-compose.benchmark.yml) — four services, contexts under `.benchmark/`
- [scripts/benchmark-worktrees.sh](scripts/benchmark-worktrees.sh) — creates worktrees for the four branches
