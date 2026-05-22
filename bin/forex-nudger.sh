#!/usr/bin/env bash
set -euo pipefail

RUNTIME_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FOREX_ROOT="/home/web/Документы/Forex-Agents"
NEXT_STEP_FILE="$FOREX_ROOT/NEXT-STEP.md"
STATE_DIR="$RUNTIME_ROOT/var/nudger"
STATE_FILE="$STATE_DIR/forex-nudger.state"
MANAGER_QUEUE_DIR="$RUNTIME_ROOT/var/manager-queue/new"
RUNTIME_SESSION_ID="max_main:g73463067973666"

mkdir -p "$STATE_DIR"

log() {
  printf '[%s] %s\n' "$(date --iso-8601=seconds)" "$*"
}

load_state() {
  LAST_PLAN_SLOT=""
  LAST_EXEC_SLOT=""

  if [[ -f "$STATE_FILE" ]]; then
    # shellcheck disable=SC1090
    source "$STATE_FILE"
  fi
}

save_state() {
  cat >"$STATE_FILE" <<EOF
LAST_PLAN_SLOT="${LAST_PLAN_SLOT:-}"
LAST_EXEC_SLOT="${LAST_EXEC_SLOT:-}"
EOF
}

queue_prompt() {
  local prompt event_id event_path tmp_path created_at scheduled_when
  prompt="$1"
  event_id="$(date +%Y%m%d-%H%M%S)-$(printf '%04x' "$((RANDOM % 65536))")$(printf '%04x' "$((RANDOM % 65536))")"
  event_path="$MANAGER_QUEUE_DIR/$event_id.json"
  tmp_path="$MANAGER_QUEUE_DIR/.$event_id.json.tmp"
  created_at="$(date --iso-8601=seconds)"
  scheduled_when="$created_at"

  mkdir -p "$MANAGER_QUEUE_DIR"
  cat >"$tmp_path" <<EOF
{
    "type": "scheduled_prompt",
    "text": $(printf '%s' "$prompt" | php -r '$v = stream_get_contents(STDIN); echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);'),
    "meta": {
        "source": "forex-nudger",
        "scheduled_by_codex_sid": null,
        "scheduled_by_runtime_sid": null,
        "scheduled_when": "$scheduled_when"
    },
    "session_id": "$RUNTIME_SESSION_ID",
    "id": "$event_id",
    "created_at": "$created_at",
    "priority": 50
}
EOF
  mv "$tmp_path" "$event_path"

  log "queued manager event $event_id"
}

plan_prompt() {
  cat <<EOF
Определи следующее действие по проекту Форекс. Не тупой улучшайзинг, а реально приближающее или увеличивающее прибыль. Запиши его в корень проекта в файл NEXT-STEP.md перезаписав его если он существует.
EOF
}

exec_prompt() {
  cat <<EOF
Выполни следующее действие по проекту форекс из файла NEXT-STEP.md в корне проекта. Не делай это сам! Ты же руководитель. Поставь задачу downstream агенту и по завершении проконтролируй исполнение. Если задача следующего шаге не выполнена или выполнена не точно или не до конца -- добейся выполнения. Только после этого шаг можно будет считать завершенным.
EOF
}

main() {
  log "forex nudger started"

  while true; do
    load_state

    minute="$(date +%M)"
    slot_hour="$(date +%Y%m%d-%H)"

    if [[ "$minute" == "45" ]]; then
      plan_slot="${slot_hour}-45"
      if [[ "${LAST_PLAN_SLOT:-}" != "$plan_slot" ]]; then
        log "planning slot $plan_slot"
        if queue_prompt "$(plan_prompt)"; then
          LAST_PLAN_SLOT="$plan_slot"
          save_state
          log "planning slot complete $plan_slot"
        else
          log "planning slot failed $plan_slot"
        fi
      fi
    elif [[ "$minute" == "00" ]]; then
      exec_slot="${slot_hour}-00"
      if [[ "${LAST_EXEC_SLOT:-}" != "$exec_slot" ]]; then
        log "execution slot $exec_slot"
        if queue_prompt "$(exec_prompt)"; then
          LAST_EXEC_SLOT="$exec_slot"
          save_state
          log "execution slot complete $exec_slot"
        else
          log "execution slot failed $exec_slot"
        fi
      fi
    fi

    sleep 20
  done
}

main "$@"
