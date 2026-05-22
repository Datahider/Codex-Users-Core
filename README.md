# Core

`Core` — runtime-ядро, работающее через `Router`.

## Scope

- чтение входящих событий через `Router`
- обработка внутренних очередей core
- запуск Codex
- запись исходящих сообщений в локальную очередь выдачи

## Out Of Scope

- внешние адаптеры доставки
- channel-specific UI
- source-specific state

## Checks

- `php smoke/router-ingress.php`
- `php smoke/router-core-events.php`
