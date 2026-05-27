# Codex Runtime Core

`codex-runtime` is the transport-agnostic core for Codex-based operator bots.

## What this repository does

- pulls inbound events from Router
- queues and processes runtime jobs
- runs `codex`
- stores runtime state on local disk
- starts and keeps background workers alive

Transport-specific UI, polling, webhook handling, and message formatting do not live here.

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
php bin/setup.php
cp config/config.example.php config/config.php
php bin/doctor.php config/config.php
php bin/run-core.php config/config.php
```

## Required config

Edit `config/config.php` and set:

- `router.base_url`
- `router.core_token`
- `storage.root` if you do not want the default `./var`
- `codex.cwd` if `codex` must run from another directory

Default config template lives in [config/config.example.php](./config/config.example.php).

## What `bin/setup.php` must do

- create local runtime directories under `storage.root`
- create queue/result directories used by the workers
- fail fast when PHP or required commands/extensions are missing
- print the next manual step instead of trying to invent fallback behavior

## What `bin/doctor.php` must validate

- config file exists and is readable
- `router.base_url` is not left as placeholder
- `router.core_token` is not empty
- `storage.root` is writable
- `codex` can be resolved from `PATH`
- `logger` can be resolved from `PATH`

## Start and operations

Foreground run:

```bash
php bin/run-core.php config/config.php
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
- storage details are documented in [STORAGE.md](./STORAGE.md)

## Boundaries

- layer rules: [LAYERS.md](./LAYERS.md)
- core ownership: [CORE.md](./CORE.md)
- project scope: [PROJECT.md](./PROJECT.md)
