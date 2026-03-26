# Adaptive Mutation Experiments

## Benchmark: uniform vs static vs adaptive vs class-adaptive

```bash
bash scripts/run_operator_policy_benchmark.sh \
  --target=targets/yaml_target.php \
  --corpus=corpus_yaml \
  --output-dir=operator_policy_runs \
  --time=60 \
  --repeats=3 \
  --seed=12345 \
  --alpha=0.2 \
  --period=1000 \
  --warmup=2000 \
  --decay-mode=reset
```

Aggregate results and confidence intervals:

```bash
php scripts/aggregate_operator_policy_results.php \
  --input-dir=operator_policy_runs \
  --output=operator_policy_summary.csv \
  --bootstrap=2000 \
  --target-percent=0.9
```

## Grid Search: alpha x period

Fixed grid:

- alpha: `0.05, 0.1, 0.2, 0.3, 0.5`
- period: `500, 1000, 2000, 5000`
- total combinations: `20`
- default repeats per combination: `3` (60 runs)

```bash
bash scripts/run_operator_grid_search.sh \
  --target=targets/yaml_target.php \
  --corpus=corpus_yaml \
  --output-dir=operator_grid_runs \
  --time=60 \
  --repeats=3 \
  --seed=7000 \
  --policy=adaptive
```

For class-adaptive grid:

```bash
bash scripts/run_operator_grid_search.sh \
  --policy=class-adaptive \
  --output-dir=operator_grid_runs_class
```

## Logged artifacts

Each run directory includes:

- `stability.csv` - coverage/corpus timeline
- `events.csv` - candidate decision log with `operator_id`, `was_interesting`, `seed_class`, `weight_snapshot_id`
- `operator_weights.jsonl` - periodic weight snapshots (`snapshot_id`, class, weights, stats)
- `log.txt` - runtime textual log
