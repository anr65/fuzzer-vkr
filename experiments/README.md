# Adaptive Experiment Harness

Run all A/B/C/D hypothesis conditions in one command:

`bash experiments/run_adaptive_experiment.sh targets/yaml_target.php corpus_yaml results/adaptive_compare 12345 100000`

This executes:
- `baseline` (no extra flags)
- `structural` (`--structural-mutator-selector`)
- `type_aware` (`--type-aware-weight=0.25`)
- `str_crossover` (`--structural-crossover`)
- `adaptive` (`--adaptive-scheduler`)

Each condition writes into `OUTPUT_BASE/<condition>/`:
- `log.txt`
- `stability.csv`
- `metadata.json` (git hash, timestamp, php version, seed, max runs, flags)

After completion, a markdown comparison table is printed by:

`php experiments/compare_conditions.php OUTPUT_BASE`

