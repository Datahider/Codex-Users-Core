# AGENTS.md

## Проект

- `Core` — это runtime-ядро для обработки входящих событий через `codex`.
- Ядро однопользовательское.
- Внутри `Core` допустима только граница с `Router`.

## Текущее состояние

- Активные воркеры:
- `router_ingress_worker`
- `manager_worker`
- `exec_watcher`
- `command_watcher`
- `control_watcher`
- `scheduler_worker`
- Основной entrypoint: `bin/run-core.php`.
- Фоновыми воркерами управляет `src/BackgroundSupervisor.php`.

## Ответственность Core

- приём входящих событий из `Router`
- `manager_queue` и связанные runtime queues
- запуск `codex`
- mapping `runtime_session_id -> codex_session_id`
- core state, session contracts и lifecycle воркеров

## Граница с Router

- `Router` для `Core` — внешний boundary, а не transport-слой внутри репозитория.
- Допустимы только:
- `Router` API client
- `Router` ingress worker
- `Router` outbound/status client
- `HTTP`/auth contract этого boundary
- Если изменение относится к presentation, transport state, message ids или transport-логике вне самого `Router` boundary, оно не должно жить в `Core`.

## Правила изменений

- Перед любым следующим кодовым шагом проверять чистоту дерева.
- Если дерево грязное, сначала делать commit.
- Изменения контрактов начинать с `*.md`.
- Для изменений контрактов сначала обновлять документацию, потом тесты, потом код.
- Не добавлять fallback, compatibility-path и скрытое самовосстановление без явного требования.
