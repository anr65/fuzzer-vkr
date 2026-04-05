# Master benchmark log (all four Docker benchmark variants)
Generated UTC: 2026-04-05T19:20:44Z

This export was produced from `docker-output/benchmark/*` (not committed) by
`scripts/export_benchmark_reports.py`. Regenerate after new runs.

## Layout

| Folder | Branch / role |
|--------|----------------|
| `optimal-weights/` | `feature/optimal-mutator-weights` |
| `mutator-combinations/` | `feature/mutator-combinations` |
| `adaptive-mutations/` | `feature/adaptive-mutations` |
| `mutator-optimizations/` | `matator-optimizations` |

## Files (per variant)

- `interval_metrics.csv` — full stability logger CSV (all columns, plotting time series).
- `viz_runs_vs_features.csv` — slim CSV: timestamp, runs, unique_features, corpus_size.
- `progress_sample.csv` — downsampled `NEW` lines from fuzzer.log (run rate, RSS, session wall s).
- `supervisor_events.jsonl` — memory / exit-255 restarts from entrypoint (one JSON per line).
- `summary.json` — machine-readable peaks and counts.
- `BENCHMARK_LOG.txt` — human-readable log for this variant.

## Cross-variant peaks (from interval metrics)

| Variant | peak runs | peak unique_features |
|---------|-----------|----------------------|
| optimal-weights | 487803 | 69437 |
| mutator-combinations | 769538 | 48043 |
| adaptive-mutations | 990841 | 48030 |
| mutator-optimizations | 916612 | 46776 |
