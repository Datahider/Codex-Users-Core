#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_ROOT="/home/web/tmp/shim-storage-root-$$"
HOME_DIR="$TMP_ROOT/home"
STORAGE_ROOT="$TMP_ROOT/storage"
CONFIG_DIR="$HOME_DIR/.codex-users-core"
CONFIG_PATH="$CONFIG_DIR/config.php"
STDERR_FILE="$TMP_ROOT/stderr.log"

mkdir -p "$CONFIG_DIR"

cat >"$CONFIG_PATH" <<PHP
<?php

return [
    'router' => [
        'base_url' => 'https://router.local',
        'core_token' => 'token',
    ],
    'storage' => [
        'root' => '$STORAGE_ROOT',
    ],
];
PHP

HOME="$HOME_DIR" CODEX_STORAGE_ROOT="$STORAGE_ROOT" RUNTIME_SID="shim-smoke-session" "$ROOT/bin/shims/schedule" --when '+1 day' 'shim storage smoke' >/dev/null 2>"$STDERR_FILE"

if [[ -s "$STDERR_FILE" ]]; then
  echo "Shim storage root smoke failed: shim leaked internal logs to stderr" >&2
  cat "$STDERR_FILE" >&2
  exit 1
fi

queue_file="$(find "$STORAGE_ROOT/scheduled-queue" -maxdepth 1 -type f -name '*.json' | head -n 1 || true)"
if [[ -z "$queue_file" ]]; then
  echo "Shim storage root smoke failed: scheduled queue file not created" >&2
  exit 1
fi

if ! grep -q '"session_id": "shim-smoke-session"' "$queue_file"; then
  echo "Shim storage root smoke failed: wrong payload in scheduled job" >&2
  exit 1
fi

echo "Shim storage root smoke: OK"
