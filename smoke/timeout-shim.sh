#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TIMEOUT_SHIM="$ROOT/bin/shims/timeout"
TMP_ROOT="$(mktemp -d /home/web/tmp/codex-timeout-shim.XXXXXX)"
trap 'rm -rf "$TMP_ROOT"' EXIT

assert_rejected() {
  local duration storage output status
  duration="$1"
  storage="$TMP_ROOT/rejected-${duration//[^A-Za-z0-9]/_}"

  set +e
  output="$(CODEX_STORAGE_ROOT="$storage" "$TIMEOUT_SHIM" "$duration" true 2>&1)"
  status=$?
  set -e

  [[ "$status" -eq 2 ]]
  [[ "$output" == *"timeout duration must be greater than 0 and no greater than 30 seconds"* ]]

  shopt -s nullglob
  local queued=("$storage/exec-queue/new/"*.json)
  shopt -u nullglob
  [[ "${#queued[@]}" -eq 0 ]]
}

assert_rejected 0
assert_rejected 30.1s
assert_rejected 1m
assert_rejected invalid

accepted_storage="$TMP_ROOT/accepted"
set +e
accepted_output="$(
  CODEX_STORAGE_ROOT="$accepted_storage" \
  CODEX_STARTER_TIMEOUT_SECONDS=1 \
  "$TIMEOUT_SHIM" 0.5s printf timeout-ok 2>&1
)"
accepted_status=$?
set -e

[[ "$accepted_status" -eq 124 ]]
[[ "$accepted_output" == *"starter timeout waiting for"* ]]

shopt -s nullglob
accepted_jobs=("$accepted_storage/exec-queue/new/"*.json)
shopt -u nullglob
[[ "${#accepted_jobs[@]}" -eq 1 ]]

queued_command="$(php -r '$job = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $job["command"];' "${accepted_jobs[0]}")"
[[ "$queued_command" == *"timeout --kill-after=1s 0.5s printf timeout-ok" ]]

echo "timeout shim smoke passed"
