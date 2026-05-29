#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_ROOT="/home/web/tmp/shim-codex-config-path-$$"
HOME_DIR="$TMP_ROOT/home"
HOME_CONFIG_DIR="$HOME_DIR/.codex-users-core"
HOME_STORAGE_ROOT="$TMP_ROOT/home-storage"
RUNTIME_STORAGE_ROOT="$TMP_ROOT/runtime-storage"

mkdir -p "$HOME_CONFIG_DIR"

cat >"$HOME_CONFIG_DIR/config.php" <<PHP
<?php

return [
    'router' => [
        'base_url' => 'https://router.local',
        'core_token' => 'token',
    ],
    'storage' => [
        'root' => '$HOME_STORAGE_ROOT',
    ],
];
PHP

printf 'shim codex config path smoke\n' | HOME="$HOME_DIR" CODEX_STORAGE_ROOT="$RUNTIME_STORAGE_ROOT" RUNTIME_SID="runtime-smoke-session" CODEX_SID="codex-smoke-session" "$ROOT/bin/shims/codex" exec resume background-session >/dev/null

runtime_queue_file="$(find "$RUNTIME_STORAGE_ROOT/command-queue/new" -maxdepth 1 -type f -name '*.json' | head -n 1 || true)"
if [[ -z "$runtime_queue_file" ]]; then
  echo "Shim codex config path smoke failed: runtime command queue file not created" >&2
  exit 1
fi

if find "$HOME_STORAGE_ROOT/command-queue/new" -maxdepth 1 -type f -name '*.json' | grep -q .; then
  echo "Shim codex config path smoke failed: job was written to HOME storage instead of runtime storage" >&2
  exit 1
fi

if ! grep -q '"origin_runtime_session_id": "runtime-smoke-session"' "$runtime_queue_file"; then
  echo "Shim codex config path smoke failed: runtime session missing from queued job" >&2
  exit 1
fi

echo "Shim codex config path smoke: OK"
