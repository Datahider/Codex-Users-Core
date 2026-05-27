# AGENTS.md

## Проект

- `Core` — это runtime-ядро для обработки входящих событий через `codex`.
- Ядро однопользовательское.
- Ядро не должно содержать transport-specific реализацию, кроме внешнего интерфейса с `Router`.

## Текущее состояние

- Ядро сейчас состоит из:
  - `router_ingress_worker`
  - `manager_worker`
  - `exec_watcher`
  - `command_watcher`
  - `control_watcher`
  - `scheduler_worker`
- Основной entrypoint: `bin/run-core.php`.
- Фоновыми воркерами управляет `src/BackgroundSupervisor.php`.

## Границы слоя

Core owns:

- приём входящих событий из `Router`
- manager queue
- запуск `codex`
- `runtime_session_id -> codex_session_id`
- command/exec/control/scheduler очереди
- core state и lifecycle

Core may know about `Router` only as:

- ingress source событий
- outbound/status delivery boundary
- HTTP/API contract
- auth token/base URL for этого boundary

Core must not own:

- transport-specific код
- transport-specific команды
- transport-specific rendering/markup
- transport-specific state
- transport-specific message ids
- transport-specific polling/webhook logic

## Запрещено

- Добавлять в core state transport-local поля.
- Вшивать fallback/compatibility-path без явного требования.
- Смешивать runtime orchestration с transport presentation.

## Разрешённый интерфейс

Допустимая внешняя интеграция для этого репозитория:

- `Router` API client
- `Router` ingress worker
- `Router` outbound/status client

Если новая функциональность не относится к этим границам или к внутренним core queue/session contracts, ей не место в этом репозитории.

## Правила изменений

- Перед любым следующим кодовым шагом проверять чистоту дерева.
- Если дерево грязное, сначала делать commit.
- Для изменений контрактов сначала обновлять документацию, потом тесты, потом код.

