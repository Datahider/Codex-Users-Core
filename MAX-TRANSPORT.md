# MAX Transport

## Scope

MAX transport owns:

- MAX long polling / webhook ingress
- MAX authorization by `chat_id`
- MAX transport-local commands such as `/status`
- MAX output rendering
- MAX transport-local settings

MAX transport must not own:

- Codex session lifecycle
- manager queue scheduling
- core state format

## Files

- [MaxRuntime.php](/home/web/Документы/codex-runtime/src/Max/MaxRuntime.php)
- [MaxUpdateIngress.php](/home/web/Документы/codex-runtime/src/Max/MaxUpdateIngress.php)
- [MaxOutboundConsumer.php](/home/web/Документы/codex-runtime/src/Max/MaxOutboundConsumer.php)
- [MaxTransportClient.php](/home/web/Документы/codex-runtime/src/Max/MaxTransportClient.php)
- [MaxTransportStateStore.php](/home/web/Документы/codex-runtime/src/Max/MaxTransportStateStore.php)

## Canonical Id

Inside the product, MAX uses:

- `chat_id`

Deterministic runtime session ids:

- dialog: `max_d<chat_id>`
- group: `max_g<abs(chat_id)>`

## State

MAX transport-local state lives in:

- [max-transport-state.json](/home/web/Документы/codex-runtime/var/state/max-transport-state.json)

Examples:

- `status_mode`
- future pinned-status metadata
- future callback/button metadata

This file is MAX-owned. Core must not write to it.
