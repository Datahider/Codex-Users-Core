# Codex Runtime Backlog

Конкретные технические долги и следующие шаги по runtime.

## MAX transport

- Перестроить MAX runtime по той же модели, что и core:
  - отдельный supervisor, который сам не гасится transport shutdown flag;
  - отдельный inbound worker для long polling / ingress;
  - отдельный outbound worker для outbound queue;
  - restart/shutdown флаги должны управлять worker-ами, а не валить весь transport root process.
- Дофиксить и закоммитить текущий status-path:
  - `group + pinned` должен создавать, pin-ить и потом редактировать одно status-сообщение;
  - `dialog + pinned` должен сразу отвечать ошибкой `Закрепление сообщений в личных чатах не поддерживается MAX.`;
  - `regular` не должен слать `idle`;
  - `pinned` должен показывать `Занят: <job_id>`.
- Зафиксировать в коде и docs, что `typing_on` у MAX работает, но UI может вести себя нестабильно в разных клиентах.
- Не допускать silent death outbound child:
  - любой fatal в MAX outbound path должен оставлять явную запись в `runtime.log`.
- Добавить recovery для oversized / rejected outbound message:
  - если `sendMessage` получает transport-level ошибку на слишком тяжёлом тексте или неподдерживаемом payload, не валить весь outbound consumer;
  - сначала зафиксировать ошибку и исходный outbound payload в логах;
  - потом пробовать fallback-доставку содержимого как файла/вложения вместо обычного сообщения;
  - отдельным тестом покрыть, что такой кейс не оставляет очередь навсегда в `outbound-queue/new`.

## Core concurrency

- Убрать single-thread bottleneck в `ManagerWorker`.
- Перейти на модель:
  - session lock по `runtime_session_id`;
  - один idle manager worker;
  - worker, взявший session lock, поднимает следующего idle worker до начала обработки.
- Сериализация должна оставаться per-session, а не global.

## Background codex jobs

- Разделить три роли в контракте фоновой задачи:
  - poster/origin codex session;
  - worker codex session;
  - runtime session доставки результата.
- Не допускать `resume` фонового codex в текущую живую manager session.
- Явно задокументировать, что `starter.sh` ставит async `codex` только из реальной runtime session.
- Доработать `background_result` path так, чтобы он не интерферировал с живой manager session.

## Execution model and unix users

- Разделить control plane и execution plane.
- Control plane должен жить под отдельным service user и отвечать только за:
  - очереди;
  - locks;
  - state;
  - маршрутизацию;
  - запуск исполнителя.
- Execution plane должен запускаться под owner unix-user конкретной session/job.
- Ввести owner-aware metadata для:
  - runtime sessions;
  - command jobs;
  - background codex jobs.
- Не выполнять owner work напрямую из:
  - `ManagerWorker`;
  - `CommandWatcher`;
  - других daemon-процессов.
- Вынести запуск команд в единый executor layer:
  - сначала как общую abstraction;
  - потом перевести её на запуск под owner user (`sudo -u` / `runuser` / systemd-run).
- Не делать long-lived worker, который меняет unix identity на лету.
- Для многопоточности держать session lock в control plane, а owner-scoped executor поднимать отдельным дочерним процессом на время задачи.

## Worker management

- Довести `WORKERS.md` до реального registry постоянных разработчиков.
- Для каждого постоянного worker slot зафиксировать:
  - `codex_session_id`;
  - слой ответственности;
  - project roots.
- Не путать постоянные worker sessions с эфемерными background sessions.

## Architecture cleanup

- Решить судьбу остаточного `orchestrator` code:
  - либо удалить;
  - либо оставить только для строго определённого deterministic path.
- Поддерживать жёсткое разделение state по слоям:
  - core state отдельно;
  - transport-local state отдельно.
- Не добавлять fallback/recovery logic без явного согласования.

## Tooling and environment

- Зафиксировать в docs текущий operational contract для shim-команд в `bin/shims`.
- Решить, какие системные команды должны иметь shims по умолчанию, а какие добавляются только осознанно.
- Зафиксировать нормальный путь для `composer` в окружении, чтобы `composer update` не зависел от случайного состояния shell.
