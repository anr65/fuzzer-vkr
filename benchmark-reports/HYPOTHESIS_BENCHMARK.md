# Связь Docker-бенчмарка с гипотезами

Четыре контейнера из `docker-compose.benchmark.yml` и артефакты в `docker-output/benchmark/<variant>/`.

| Контейнер / папка | Ветка / смысл | Раздел | Гипотезы (проверка по метрикам) |
|-------------------|---------------|--------|----------------------------------|
| `optimal-weights` | `feature/optimal-mutator-weights` | **2.1** Веса операторов | H1: эффективность поиска → `stability.csv` (runs, unique_features, contribution_rate), сравнение с базой. H2: динамика весов → политика `adaptive` в `FUZZER_EXTRA_ARGS`, лог операторов в fuzzer-ветке при наличии. |
| `mutator-combinations` | `feature/mutator-combinations` | **2.2** Пространство профилей | H1: уникальные уязвимости / покрытие → `unique_features`, `crash-*.txt`, корпус. H2: YAML → таргет `request_mix_3` + корпус; смотреть ветку на YAML-мутации и поля stability при наличии. |
| `adaptive-mutations` | `feature/adaptive-mutations` | **2.3** Адаптивные мутации | H1: сложные уязвимости → рост `unique_features`, crashes. H2: семантика входа → специфичные колонки stability (если есть в ветке), `stability.csv` + `fuzzer.log`. |
| `mutator-optimizations` | `matator-optimizations` | **2.4** Эффективность фаззера | Сейчас в compose только `--timeout=30` (флаги adaptive-mutators / weighted seeds / corpus / yaml-cache отключены: стек Laravel + Symfony `Stream::flock` падает на PHP 8.4). Гипотезы 2.4 проверяйте по `stability.csv`, `fuzzer.log`, `crash-*.txt`; доп. CSV включайте после обновления зависимостей. |

## Дополнительные метрики (после починки `flock` / обновления Symfony)

Планировались в `mutator-optimizations`: `mutator_adaptive_events.csv`, `seed_lifecycle.csv`, `corpus_admission.csv`, `yaml_cache_stats.json` — см. историю `docker-compose.benchmark.yml`.

Сейчас все четыре контейнера опираются на **`stability.csv`**, **`fuzzer.log`**, **`crash-*.txt`**; у `optimal-weights` в compose включено **`--operator-policy=adaptive`**.

## Регенерация отчёта для графиков

```bash
python3 scripts/export_benchmark_reports.py
```
