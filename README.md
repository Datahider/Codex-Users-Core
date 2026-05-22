# Core

`Core` — runtime-ядро, работающее через `Router`.

## Scope

- чтение входящих событий через `Router`
- обработка внутренних очередей core
- запуск Codex
- отправка исходящих сообщений через `Router`
- при временной недоступности `Router` core пишет warning в лог, ждёт 15 секунд и продолжает обработку внутренней очереди

## Out Of Scope

- внешние адаптеры доставки
- channel-specific UI
- source-specific state

## Checks

- `php smoke/router-ingress.php`
- `php smoke/router-core-events.php`
- `php smoke/router-outbound.php`
