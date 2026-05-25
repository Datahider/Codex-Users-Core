# Storage

## Rule

Each layer owns its own storage.

Do not mix:

- core state
- transport state
- orchestrator state
- watchdog state

## Current Files

Core:

- [manager-state.json](/home/web/Документы/codex-runtime/var/state/manager-state.json)

MAX transport:

- [max-transport-state.json](/home/web/Документы/codex-runtime/var/state/max-transport-state.json)
- [max-long-poll-state.json](/home/web/Документы/codex-runtime/var/state/max-long-poll-state.json)

Orchestrator:

- [orchestrator-state.json](/home/web/Документы/codex-runtime/var/state/orchestrator-state.json)

Idle watchdog:

- [idle-watchdog-state.json](/home/web/Документы/codex-runtime/var/state/idle-watchdog-state.json)

## Shared Queues Are Not Shared State

Shared queue directories are allowed:

- `manager-queue`
- `outbound-queue`
- `command-queue`
- `exec-queue`

But queue messages must contain only the minimal cross-layer payload.

Queue traffic is allowed.
Shared mutable state format is not.
