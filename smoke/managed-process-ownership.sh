#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHECKER="$ROOT/bin/managed-process-owned.sh"
PGID_CHECKER="$ROOT/bin/managed-process-pgid.sh"
MARKER="smoke-marker-$$"

[[ -x "$PGID_CHECKER" ]]

CODEX_MANAGED_PROCESS_MARKER="$MARKER" tail -f /dev/null >/dev/null 2>&1 &
process_pid=$!
setsid tail -f /dev/null >/dev/null 2>&1 &
session_pid=$!
trap 'kill "$process_pid" "$session_pid" 2>/dev/null || true' EXIT

"$CHECKER" "$process_pid" "$MARKER"

if "$CHECKER" "$process_pid" "wrong-marker"; then
  echo "ownership checker accepted a foreign process" >&2
  exit 1
fi

if "$PGID_CHECKER" "$process_pid" 1 0; then
  echo "PGID checker accepted an inherited process group" >&2
  exit 1
fi

[[ "$("$PGID_CHECKER" "$session_pid" 5 1)" == "$session_pid" ]]

echo "managed process ownership smoke passed"
