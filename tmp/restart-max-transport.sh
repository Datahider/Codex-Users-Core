#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/web/Документы/codex-runtime"
CONFIG="$ROOT/config/config.php"

transport_pid_file="$ROOT/var/run/max-transport.pid"
outbound_pid_file="$ROOT/var/run/max-outbound-consumer.pid"
transport_shutdown_flag="$ROOT/var/run/max-transport.shutdown.flag"
outbound_shutdown_flag="$ROOT/var/run/max-outbound-consumer.shutdown.flag"

kill_from_pid_file() {
  local pid_file="$1"
  if [[ ! -f "$pid_file" ]]; then
    return 0
  fi

  local pid
  pid="$(tr -d '[:space:]' < "$pid_file")"
  if [[ -z "$pid" ]]; then
    rm -f "$pid_file"
    return 0
  fi

  if kill -0 "$pid" 2>/dev/null; then
    kill "$pid" 2>/dev/null || true
    for _ in 1 2 3 4 5 6 7 8 9 10; do
      if ! kill -0 "$pid" 2>/dev/null; then
        break
      fi
      sleep 1
    done
    if kill -0 "$pid" 2>/dev/null; then
      kill -9 "$pid" 2>/dev/null || true
    fi
  fi

  rm -f "$pid_file"
}

kill_from_pid_file "$transport_pid_file"
kill_from_pid_file "$outbound_pid_file"

rm -f "$transport_shutdown_flag" "$outbound_shutdown_flag"

start --name codex-runtime-max --cwd "$ROOT" -- php "$ROOT/bin/run-max.php" "$CONFIG"

echo "restart requested"
