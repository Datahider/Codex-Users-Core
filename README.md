# Codex Runtime

`codex-runtime` is the transport-agnostic core extracted from `codex-telegram-bot`.

## What lives here

- `CommandWatcher`
- `ManagerQueue`
- `ManagerWorker`
- `Orchestrator`
- `SchedulerWorker`
- `IdleWatchdogWorker`
- `CodexProcess`
- shared state/config/logging/project registry

## What does not live here

- Telegram polling
- Telegram API client
- Telegram-specific status messages
- Telegram HTML rendering
- voice handling

Those parts stay outside the core and must be implemented by a transport adapter.

## Transport contracts

`ManagerWorker` and `Orchestrator` depend on interfaces instead of Telegram classes:

- [TransportClientInterface](./src/Contracts/TransportClientInterface.php)
- [StatusMessageServiceInterface](./src/Contracts/StatusMessageServiceInterface.php)
- [AdminNotifierInterface](./src/Contracts/AdminNotifierInterface.php)

Inbound adapters should enqueue normalized user messages through:

- [TransportInboundMessage](./src/TransportInboundMessage.php)
- [TransportMessageIngress](./src/TransportMessageIngress.php)

That is the first explicit seam for the future MAX transport. Text rendering is transport-side and must not move back into core.

## Current shape

- inbound transport builds `TransportInboundMessage` and enqueues `user_message` into `manager-queue`
- core can now run as its own standalone process via `bin/run-core.php`
- core writes outbound transport work into `outbound-queue` instead of requiring a live transport process
- scheduler releases due files from `scheduled-queue`
- watcher executes external jobs from `command-queue`
- orchestrator reacts to finished watcher jobs and enqueues `internal_decision`
- manager runs Codex and decides what to send back through the transport adapter

## Layering

- transport/core boundaries are documented in [LAYERS.md](./LAYERS.md)
- per-layer handoff docs:
  - [CORE.md](./CORE.md)
  - [MAX-TRANSPORT.md](./MAX-TRANSPORT.md)
  - [STORAGE.md](./STORAGE.md)
- concrete runtime backlog lives in [BACKLOG.md](./BACKLOG.md)
- transport-specific state must live in transport-owned storage
- core-owned state must not contain transport-local settings

## Next step

Build a MAX transport project on top of this core:

- inbound MAX runner
- MAX implementation of the transport contracts
- runtime bootstrap that wires the contracts to the core workers

## MAX slice

This repository now includes a first MAX transport slice under `CodexRuntime\Max` built around the Packagist package `bushlanov-dev/max-bot-api-client-php`.

What the slice currently covers:

- `MaxRuntime` bootstrap for the existing `ManagerWorker` and `Orchestrator`
- `MaxTransportClient` for outbound `sendMessage` and `sendChatAction`
- `MaxLongPollingRunner` as the runnable MAX ingress path for plain-text inbound updates
- `MaxWebhookIngress` for keeping webhook normalization available in-repo when useful
- `MaxStatusMessageService`, `MaxAdminNotifier`, and transport-side rendering that keep the core transport-agnostic

Entry points:

- `bin/run-core.php`
- `bin/cli-transport.php`
- `smoke/max-long-poll-normalization.php`
- `smoke/max-webhook-normalization.php`

This is intentionally narrow. The slice handles plain text ingress and outbound messaging first. The runnable inbound path is now MAX long polling, while richer MAX-specific features such as media and callback buttons remain outside this cut.

Smoke coverage:

- `php smoke/max-long-poll-normalization.php`
- replays a plain-text MAX update fixture directly through the shared MAX update ingress used by long polling
- `php smoke/max-webhook-normalization.php`
- replays a fixture for a plain-text `message_created`-style MAX webhook update
- verifies the MAX ingress path accepts it, normalizes it into `TransportInboundMessage`, and enqueues a `user_message` event under `manager-queue/new`
- asserts the queued payload keeps the current transport assumptions: `channel_id`, plain text body, `channel_type`, and MAX metadata (`transport`, `transport_message_id`, `update_type`, `sender_id`)

Running the current core slice:

- start the standalone core: `php bin/run-core.php config/config.php`
- for local transportless testing, use: `php bin/cli-transport.php config/config.php`
- configure `chat_routing.routes` for the test channel you want to use

## Starter and shims

- `bin/shims/*` point to `bin/starter.sh` and do not require a writable project log directory to start.
- `bin/starter.sh` now requires standard `logger(1)` to be resolvable from `PATH`; if `logger` is missing, starter exits immediately with an explicit `stderr` error and a non-zero status before any command dispatch.
- Starter diagnostics still go to `stderr` and are also emitted with the standard syslog/journald tag `codex-runtime-starter`.
- Optional file logging is available via `CODEX_STARTER_LOG_FILE`, but failure to create or write that file does not block command startup.

The older MAX bootstrap code still exists under `src/Max`, but `bin/` is intentionally reduced for the current phase so the core has a single real entrypoint plus one local test transport.
