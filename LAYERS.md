# Layer Boundaries

This document is for downstream developers. The goal is to keep layers independent so a format change in one layer cannot corrupt another layer's data.

## Rule Zero

- No shared storage between layers.
- No transport-specific fields inside core-owned state.
- No core-specific fields inside transport-owned state.
- Between layers, pass only stable identifiers and plain payloads.

## Layers

### 1. Core

Owns:

- `runtime_session_id -> codex_session_id`
- manager queue processing
- outbound queue production
- Codex execution
- job lifecycle

Must not own:

- transport settings
- transport formatting
- transport-specific message ids
- status presentation mode
- MAX/Telegram-specific parse modes or markup

Current core state file:

- [manager-state.json](/home/web/Документы/codex-runtime/var/state/manager-state.json)

Core output contract:

- writes outbound messages into the shared outbound queue
- outbound message types may include:
  - `message`
  - `chat_action`
  - `status`

Core does not decide how a transport renders those types.

### 2. Transport

Owns:

- transport authorization / whitelist
- transport-local settings
- transport-local channel/session presentation
- transport-specific UI commands such as `/status`
- transport-specific delivery details

Must not own:

- Codex session lifecycle
- manager scheduling
- core queue semantics

MAX transport state file:

- [max-transport-state.json](/home/web/Документы/codex-runtime/var/state/max-transport-state.json)

Examples of transport-local data:

- `status_mode`
- pinned-status metadata
- callback/button state
- message ids used for edits

### 3. Shared Queue Boundary

Allowed shared mechanisms:

- manager queue
- outbound queue

Allowed shared data:

- `runtime_session_id`
- message `type`
- plain text / action payload

Not allowed:

- direct transport storage writes from core
- direct core storage writes from transport

## MAX Rules

Canonical external id inside the product:

- `chat_id`

Deterministic runtime session ids:

- dialog: `max_d<chat_id>`
- group: `max_g<abs(chat_id)>`

MAX transport may keep additional local metadata, but that metadata must live only in MAX transport state, never in core state.

## Status Rules

Core is allowed to emit:

- `status busy`
- `status idle`

Transport decides:

- ignore
- regular message
- pinned/editable status

That choice is transport-local state.

## Change Rules For Developers

If you work on core:

- do not add transport fields to `manager-state.json`
- do not add transport markup/rendering to `ManagerWorker`
- do not add transport-specific branching to core queue processing

If you work on a transport:

- do not change core state format
- do not store transport settings in core-owned files
- do not assume another transport's routing or state format

If you need new data across the boundary:

- first decide which layer owns it
- then store it only in that layer's storage
- only expose the minimal identifier/payload needed across the queue boundary

## Current Practical Split

Core-owned:

- [ManagerWorker.php](/home/web/Документы/codex-runtime/src/ManagerWorker.php)
- [QueueStatusMessageService.php](/home/web/Документы/codex-runtime/src/QueueStatusMessageService.php)

MAX transport-owned:

- [MaxUpdateIngress.php](/home/web/Документы/codex-runtime/src/Max/MaxUpdateIngress.php)
- [MaxOutboundConsumer.php](/home/web/Документы/codex-runtime/src/Max/MaxOutboundConsumer.php)
- [MaxTransportStateStore.php](/home/web/Документы/codex-runtime/src/Max/MaxTransportStateStore.php)

If a proposed change does not fit that split, stop and redesign before merging.
