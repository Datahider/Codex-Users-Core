# Codex Runtime Core

`codex-runtime` — это ядро рантайма для Codex-ботов, работающее через `Router`.

## Что делает репозиторий

- забирает входящие события из `Router`
- ставит runtime-задачи в очереди и обрабатывает их
- запускает `codex`
- хранит состояние рантайма на локальном диске
- поднимает и удерживает фоновые воркеры

## Зона ответственности

Ядро отвечает за:

- чтение входящих событий из `Router`
- обработку `manager-queue`
- запуск `codex`
- связь `runtime_session_id -> codex_session_id`
- команды управления вроде `/stop`, `/reset`, `/session`
- выпуск отложенных задач в `manager-queue`
- исходящие семантические payload'ы для границы с транспортом

## Требования

- Linux
- PHP 8.1 или новее
- PHP-расширение `curl`
- `composer`
- `codex` в `PATH`
- `logger` в `PATH`
- доступный экземпляр `Router`
- валидный `Router` core token

## Быстрая установка

```bash
git clone <repo-url>
cd Core
composer install
cp config/config.example.php config/config.php
php bin/run-core.php
```

## Обязательная настройка

В `config/config.php` нужно задать:

- `router.base_url`
- `router.core_token`
- `storage.root`, если не подходит значение по умолчанию `./var`
- `codex.cwd`, если `codex` должен запускаться из другого каталога

Шаблон конфига лежит в [config/config.example.php](./config/config.example.php).

При старте `bin/run-core.php` сам:

- проверяет наличие и читаемость `config/config.php`
- валидирует `router.base_url` и `router.core_token`
- проверяет PHP-зависимости и нужные команды в `PATH`
- создает локальную runtime-структуру каталогов под `storage.root`

## Запуск

В foreground:

```bash
php bin/run-core.php
```

Пример unit-файла для `systemd`:

- [systemd/codex-runtime-core.service](./systemd/codex-runtime-core.service)

## Smoke-проверки

```bash
php smoke/minimal-config-surface.php
php smoke/runtime-storage-layout.php
php smoke/doctor-ready-config.php
```

## Runtime layout

- очереди, логи, state и pid-файлы живут под `storage.root`
- `storage.root` по умолчанию — `./var`
- состояние ядра лежит в `var/state`
- lock-файлы и pid-файлы воркеров лежат в `var/run`
- очереди ядра:
  - `manager-queue`
  - `outbound-queue`
  - `control-queue`
  - `scheduled-queue`

## Граница исходящих сообщений

Ядро может выпускать только такие outbound payload'ы:

- `message`
- `heartbeat`
- `status`

Как именно они рендерятся и доставляются, решает внешний transport-слой.

## Границы проекта

- краткое описание проекта: [PROJECT.md](./PROJECT.md)
