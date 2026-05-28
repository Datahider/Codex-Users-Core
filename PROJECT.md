# PROJECT

## Что это

`Core` — это исполняемое ядро Codex-бота.

Оно не общается с пользователем напрямую. Оно получает входящие события из `Router`, запускает `codex`, ведет runtime-сессии, обрабатывает очереди и отдает наружу готовые outbound-события.

## Когда нужен этот проект

Этот проект нужен, когда уже есть внешний слой, который:

- принимает сообщения пользователя
- умеет сложить их в `Router`
- умеет забрать из `Router` outbound payload и доставить его пользователю

Без `Router` и без внешнего transport-слоя само по себе это ядро бесполезно.

## Что здесь находится

Внутри `Core` живут:

- `bin/run-core.php` — основной entrypoint
- `RouterIngressWorker` — забирает входящие события из `Router`
- `ManagerWorker` — ведет turn, запускает `codex`, обновляет session-state
- `ControlWatcher` — обрабатывает команды вроде `/stop`, `/reset`, `/session`
- `BackgroundSupervisor` — поднимает и удерживает фоновые воркеры
- файловые очереди, lock-файлы, state и runtime-логи под `var/`

## Чего здесь нет

Внутри `Core` не должно быть:

- transport UI
- transport-specific message formatting
- transport-specific message ids и transport state
- polling/webhook-кода конкретного мессенджера

## Текущий статус

Проект активный.

Текущая цель — держать `Core` как отдельное, устанавливаемое и понятное ядро без мусора от старых transport- и orchestration-слоев.
