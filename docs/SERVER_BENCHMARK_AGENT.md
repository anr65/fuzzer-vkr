# Запуск Docker-бенчмарка фаззера на VPS (4 ветки)

Этот документ предназначен для оператора или ИИ-агента на сервере. Репозиторий: **fuzzer-vkr**. Предполагается Linux с Docker Engine и плагином Compose v2.

## Предпосылки

- **Образ**: `Dockerfile` использует **PHP 8.4** CLI (требование транзитивных пакетов Symfony 8 из Laravel 12).
- **CPU/RAM**: ориентир 8 vCPU, 12+ ГБ RAM; в `docker-compose.benchmark.yml` на контейнер задано ~2 vCPU и 2.5 ГиБ RAM. При OOM уменьшите параллелизм (остановите часть сервисов) или добавьте swap.
- Ветки с **одинаковыми** файлами Docker (после мержа/cherry-pick): `Dockerfile`, `.dockerignore`, `docker/entrypoint.sh`, `docker-compose.yml`, `docker-compose.benchmark.yml`, `scripts/benchmark-worktrees.sh`.

## Установка Docker (Debian/Ubuntu, кратко)

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "${VERSION_CODENAME:-$VERSION_ID}") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker "$USER"
# перелогиньтесь, чтобы группа docker применилась
```

На других дистрибутивах используйте официальную инструкцию Docker.

## Клонирование и подготовка worktree

```bash
git clone <URL> fuzzer-vkr
cd fuzzer-vkr
git checkout matator-optimizations   # ветка для четвёртого образа (сборка из корня `.`)
./scripts/benchmark-worktrees.sh
```

Скрипт создаёт три worktree (одна ветка не может быть в двух деревьях одновременно):

- `.benchmark/optimal-weights` → `feature/optimal-mutator-weights`
- `.benchmark/mutator-combinations` → `feature/mutator-combinations`
- `.benchmark/adaptive-mutations` → `feature/adaptive-mutations`

Четвёртый сервис в `docker-compose.benchmark.yml` собирается из **корня репозитория** (код ветки `matator-optimizations`). Перед `docker compose ... build` обязательно `git checkout matator-optimizations` в основном клоне.

Если `git worktree add` падает с ошибкой «branch already checked out», освободите ветку (удалите другой worktree).

## Сборка и запуск четырёх контейнеров

Из **корня** репозитория (там, где лежит `docker-compose.benchmark.yml`):

```bash
docker compose -f docker-compose.benchmark.yml build
docker compose -f docker-compose.benchmark.yml up -d
docker compose -f docker-compose.benchmark.yml ps
docker stats
```

## Артефакты

На хосте (относительно корня репозитория):

- `docker-output/benchmark/optimal-weights/` — `fuzzer.log`, `stability.csv`, прочий вывод фаззера
- `docker-output/benchmark/mutator-combinations/`
- `docker-output/benchmark/adaptive-mutations/`
- `docker-output/benchmark/mutator-optimizations/`

Таргет `request_mix_3/target.php` при исключениях может писать `log.txt` в **рабочий каталог приложения внутри образа** (`/app`), а не в примонтированный `output`. Для сравнения веток опирайтесь на `fuzzer.log`, `stability.csv` и файлы в `OUTPUT_DIR` фаззера.

## Остановка

```bash
docker compose -f docker-compose.benchmark.yml down
```

Образы можно оставить или удалить: `docker image rm fuzzer-vkr:optimal-weights ...`.

## Настройка длительности и лимитов

В `docker-compose.benchmark.yml` длительность задаётся через переменную хоста **`BENCHMARK_MAX_TIME_SECONDS`** (по умолчанию 7200). Пример короткой проверки четырёх контейнеров:  
`BENCHMARK_MAX_TIME_SECONDS=120 docker compose -f docker-compose.benchmark.yml up`.

Для одного контейнера используйте `docker-compose.yml` и переопределите `MAX_TIME_SECONDS` в секции `environment`.

Дополнительные флаги фаззера (если нужны одинаково на всех ветках): в образе поддерживается `FUZZER_EXTRA_ARGS` в `docker/entrypoint.sh` (добавьте в compose при необходимости).

## Типичные проблемы

1. **OOM / контейнер убит**: `dmesg | tail` или `journalctl -k`. Снизьте `mem_limit` / число сервисов или увеличьте RAM/swap.
2. **Сборка падает на composer**: проверьте сеть и доступ к Packagist; при необходимости `docker build` с `--network=host` не используется по умолчанию — убедитесь, что исходящий HTTPS разрешён.
3. **Контекст `.benchmark/...` не найден**: снова выполните `./scripts/benchmark-worktrees.sh` из корня репозитория.

## Одиночный smoke-тест (одна ветка)

```bash
docker compose build
docker compose up
```

Логи и стабильность: `docker-output/single/`.
