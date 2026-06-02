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
mkdir -p ~/.codex-users-core
cp config/config.example.php ~/.codex-users-core/config.php
php bin/run-core.php
```

## Обязательная настройка

По умолчанию `php bin/run-core.php` ищет конфиг в:

```text
~/.codex-users-core/config.php
```

Если нужно, путь к конфигу можно передать первым аргументом.

В конфиге нужно задать:

- `router.base_url`
- `router.core_token`
- `codex.cwd`, если `codex` должен запускаться из другого каталога

`storage.root` менять не обязательно. По умолчанию он равен:

```text
~/.codex-users-core/var
```

Шаблон конфига лежит в [config/config.example.php](./config/config.example.php).

При старте `bin/run-core.php` сам:

- проверяет наличие и читаемость конфига
- валидирует `router.base_url` и `router.core_token`
- проверяет PHP-зависимости и нужные команды в `PATH`
- создает локальную runtime-структуру каталогов под `storage.root`

Shim-команды из `bin/shims` берут `storage.root` из того же конфига.

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
- `storage.root` по умолчанию — `~/.codex-users-core/var`
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

Для `ManagerWorker` это значит следующее:

- `user_message`, `scheduled_prompt` и `background_result` во время `codex->run(...)` стримят промежуточные commentary-чанки как outbound `message`
- после завершения каждого такого turn финальный текст уходит отдельным outbound `message`
- для `background_result` отличается только prompt-builder и текст fallback-ответа при пустом результате

## Границы проекта

- краткое описание проекта: [PROJECT.md](./PROJECT.md)
