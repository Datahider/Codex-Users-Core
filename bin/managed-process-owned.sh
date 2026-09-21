#!/usr/bin/env bash
set -euo pipefail

if [[ "$#" -ne 2 || ! "$1" =~ ^[0-9]+$ || -z "$2" ]]; then
  echo "usage: managed-process-owned.sh <pid> <marker>" >&2
  exit 2
fi

process_pid="$1"
expected="CODEX_MANAGED_PROCESS_MARKER=$2"
environment_file="/proc/$process_pid/environ"

if [[ ! -r "$environment_file" ]]; then
  exit 1
fi

while IFS= read -r -d '' entry; do
  if [[ "$entry" == "$expected" ]]; then
    exit 0
  fi
done <"$environment_file"

exit 1
