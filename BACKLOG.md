# Бэклог Codex Runtime Core

Конкретные технические долги и следующие шаги по ядру.

## Параллелизм ядра

- убрать single-thread bottleneck в `ManagerWorker`
- перейти на модель:
  - session lock по `runtime_session_id`
  - один idle `manager_worker`
  - воркер, взявший session lock, поднимает следующего idle-воркера до начала обработки
- сериализация должна оставаться per-session, а не глобальной

## Фоновые codex-задачи

- разделить три роли в контракте фоновой задачи:
  - codex session постановщика
  - codex session воркера
  - runtime session доставки результата
- не допускать `resume` фонового `codex` в текущую живую manager-session
- явно задокументировать, что `starter.sh` ставит async `codex` только из реальной runtime-session
- доработать `background_result`, чтобы он не интерферировал с живой manager-session

## Модель исполнения и unix-пользователи

- разделить control plane и execution plane
- control plane должен жить под отдельным service-user и отвечать только за:
  - очереди
  - lock'и
  - state
  - маршрутизацию
  - запуск исполнителя
- execution plane должен запускаться под unix-user владельца конкретной session/job
- ввести owner-aware metadata для:
  - runtime sessions
  - command jobs
  - background codex jobs
- не выполнять owner work напрямую из:
  - `ManagerWorker`
  - `CommandWatcher`
  - других daemon-процессов
- вынести запуск команд в единый executor layer
- не делать long-lived worker, который меняет unix identity на лету

## Управление воркерами

- довести `WORKERS.md` до состояния реестра постоянных разработчиков
- для каждого постоянного worker-slot зафиксировать:
  - `codex_session_id`
  - слой ответственности
  - project roots
- не путать постоянные worker-sessions с эфемерными background-sessions

## Чистка архитектуры

- решить судьбу остаточного `orchestrator`-кода:
  - либо удалить
  - либо оставить только для строго определенного deterministic path
- поддерживать жесткое разделение state по слоям:
  - core state отдельно
  - transport-local state отдельно
- не добавлять fallback и recovery logic без явного согласования

## Инструменты и окружение

- зафиксировать в документации текущий operational contract для shim-команд в `bin/shims`
- решить, какие системные команды должны иметь shims по умолчанию, а какие добавляются только осознанно
- зафиксировать нормальный путь для `composer` в окружении, чтобы `composer update` не зависел от случайного состояния shell
