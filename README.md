# Codex Runtime Core

`codex-runtime` is the transport-agnostic core for Codex-based operator bots.

## What this repository does

- pulls inbound events from Router
- queues and processes runtime jobs
- runs `codex`
- stores runtime state on local disk
- starts and keeps background workers alive

Transport-specific UI, polling, webhook handling, and message formatting do not live here.

## Scope

Core owns:

- Router ingress consumption
- manager queue processing
- Codex execution
- `runtime_session_id -> codex_session_id`
- control commands such as stop/reset/session
- scheduled prompts released into manager queue
- outbound semantic messages for the transport boundary

## Requirements

- Linux
- PHP 8.1 or newer
- PHP `curl` extension
- `composer`
- `codex` in `PATH`
- `logger` in `PATH`
- a reachable Router instance
- a valid Router core token

## Quick install

```bash
git clone <repo-url>
cd Core
composer install
cp config/config.example.php config/config.php
php bin/run-core.php
```

## Required config

Edit `config/config.php` and set:

- `router.base_url`
- `router.core_token`
- `storage.root` if you do not want the default `./var`
- `codex.cwd` if `codex` must run from another directory

Default config template lives in [config/config.example.php](./config/config.example.php).

On startup `bin/run-core.php` does the required checks itself:

- validates `config/config.php`
- validates `router.base_url` and `router.core_token`
- checks PHP requirements and required commands in `PATH`
- creates the local runtime storage layout under `storage.root`

## Start and operations

Foreground run:

```bash
php bin/run-core.php
```

Systemd example:

- unit file: [systemd/codex-runtime-core.service](./systemd/codex-runtime-core.service)

## Smoke checks

```bash
php smoke/minimal-config-surface.php
php smoke/install-setup.php
php smoke/doctor-ready-config.php
```

## Runtime layout

- queues, logs, state and pid files live under `storage.root`
- default storage root is `./var`
- core state lives in `var/state`
- worker locks and pid files live in `var/run`
- core-owned queues are:
  - `manager-queue`
  - `outbound-queue`
  - `control-queue`
  - `scheduled-queue`

## Queue boundary

Core may emit only semantic outbound payloads:

- `message`
- `chat_action`
- `status`

Transport code decides how those payloads are rendered and delivered.

## Boundaries

- project scope: [PROJECT.md](./PROJECT.md)
