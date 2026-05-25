# Core

## Scope

Core owns:

- manager queue
- Codex execution
- `runtime_session_id -> codex_session_id`
- outbound queue production

Core must not own:

- transport settings
- transport markup
- transport callback state
- transport message ids
- transport presentation choices

## Files

- [ManagerWorker.php](/home/web/Документы/codex-runtime/src/ManagerWorker.php)
- [QueueStatusMessageService.php](/home/web/Документы/codex-runtime/src/QueueStatusMessageService.php)
- [TransportMessageIngress.php](/home/web/Документы/codex-runtime/src/TransportMessageIngress.php)

## State

Core state lives in:

- [manager-state.json](/home/web/Документы/codex-runtime/var/state/manager-state.json)

Allowed data there:

- `runtime_session_id`
- `codex_session_id`
- core timestamps
- core lifecycle markers

Transport-local fields do not belong here.

## Outbound Contract

Core may emit only semantic messages:

- `message`
- `chat_action`
- `status`

Core does not decide:

- parse mode
- formatting
- pin/edit behavior
- transport-local routing settings
