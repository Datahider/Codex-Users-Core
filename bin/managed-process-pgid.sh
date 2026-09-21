#!/usr/bin/env bash
set -euo pipefail

if [[ "$#" -ne 3 || ! "$1" =~ ^[0-9]+$ || ! "$2" =~ ^[1-9][0-9]*$ || ! "$3" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
  echo "usage: managed-process-pgid.sh <launcher-pid> <attempts> <interval-seconds>" >&2
  exit 2
fi

launcher_pid="$1"
attempts="$2"
interval_seconds="$3"

for ((attempt = 1; attempt <= attempts; attempt++)); do
  process_pgid="$(ps -o pgid= -p "$launcher_pid" 2>/dev/null | tr -d '[:space:]')"
  if [[ "$process_pgid" == "$launcher_pid" ]]; then
    printf '%s\n' "$process_pgid"
    exit 0
  fi

  if ((attempt < attempts)); then
    read -r -t "$interval_seconds" _ </dev/null || true
  fi
done

exit 1
