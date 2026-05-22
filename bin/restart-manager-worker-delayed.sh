#!/usr/bin/env bash
set -euo pipefail

RUNTIME_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DELAY_SECONDS="${DELAY_SECONDS:-30}"
PID_FILE="$RUNTIME_ROOT/var/run/manager-worker.pid"
LOG_FILE="$RUNTIME_ROOT/var/log/restart-manager-worker-delayed.log"

mkdir -p "$(dirname "$LOG_FILE")"

log() {
  printf '[%s] %s\n' "$(date --iso-8601=seconds)" "$*" >>"$LOG_FILE"
}

read_pid() {
  if [[ ! -f "$PID_FILE" ]]; then
    return 1
  fi

  local pid
  pid="$(tr -d '[:space:]' <"$PID_FILE")"
  if [[ -z "$pid" || ! "$pid" =~ ^[0-9]+$ ]]; then
    return 1
  fi

  printf '%s\n' "$pid"
}

main() {
  log "delayed manager_worker kill requested delay=${DELAY_SECONDS}s"
  sleep "$DELAY_SECONDS"

  local pid
  pid="$(read_pid || true)"
  if [[ -z "${pid:-}" ]]; then
    log "no manager_worker pid found"
    exit 0
  fi

  if kill -0 "$pid" 2>/dev/null; then
    log "killing manager_worker pid=$pid"
    kill "$pid"
    exit 0
  fi

  log "manager_worker pid already dead pid=$pid"
}

main "$@"
