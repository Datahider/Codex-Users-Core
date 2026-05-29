#!/usr/bin/env bash
set -euo pipefail

LOG_TAG="${CODEX_STARTER_LOG_TAG:-codex-runtime-starter}"
OPTIONAL_LOG_FILE="${CODEX_STARTER_LOG_FILE:-}"
LOGGER_BIN="$(command -v logger || true)"

if [[ -z "$LOGGER_BIN" ]]; then
  echo "starter requires logger(1) in PATH; aborting before command dispatch" >&2
  exit 127
fi

SELF_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
RUNTIME_ROOT="$(cd "$(dirname "$SELF_PATH")/.." && pwd)"
HOME_DIR="${HOME:-}"
CONFIG_PATH=""
STORAGE_ROOT=""
QUEUE_DIR=""
COMMAND_QUEUE_DIR=""
COMMAND_RESULTS_DIR=""
SCHEDULED_QUEUE_DIR=""
RESULTS_DIR=""
MANAGED_PROCESSES_DIR=""
DEFAULT_PROJECT="$RUNTIME_ROOT"
TIMEOUT_SECONDS="${CODEX_STARTER_TIMEOUT_SECONDS:-3600}"

resolve_config_path() {
  if [[ -n "$HOME_DIR" && -f "$HOME_DIR/.codex-users-core/config.php" ]]; then
    printf '%s\n' "$HOME_DIR/.codex-users-core/config.php"
    return 0
  fi

  printf '%s\n' "$RUNTIME_ROOT/config/config.php"
}

extract_storage_root() {
  local config_path line config_dir suffix
  local quoted_root_pattern dir_root_pattern
  config_path="$1"
  quoted_root_pattern="[\"']root[\"'][[:space:]]*=>[[:space:]]*[\"']([^\"']+)[\"']"
  dir_root_pattern="[\"']root[\"'][[:space:]]*=>[[:space:]]*__DIR__[[:space:]]*\\.[[:space:]]*[\"']([^\"']+)[\"']"

  if [[ ! -f "$config_path" ]]; then
    echo "starter config file not found: $config_path" >&2
    exit 1
  fi

  line="$(grep -m1 -E "['\"]root['\"][[:space:]]*=>" "$config_path" || true)"
  if [[ -z "$line" ]]; then
    echo "starter failed to find storage.root in config: $config_path" >&2
    exit 1
  fi

  if [[ "$line" =~ $quoted_root_pattern ]]; then
    printf '%s\n' "${BASH_REMATCH[1]}"
    return 0
  fi

  if [[ "$line" =~ $dir_root_pattern ]]; then
    config_dir="$(cd "$(dirname "$config_path")" && pwd)"
    suffix="${BASH_REMATCH[1]}"
    printf '%s\n' "${config_dir}${suffix}"
    return 0
  fi

  echo "starter failed to parse storage.root from config: $config_path" >&2
  exit 1
}

initialize_runtime_layout() {
  if [[ -n "${CODEX_STORAGE_ROOT:-}" ]]; then
    STORAGE_ROOT="$CODEX_STORAGE_ROOT"
  else
    CONFIG_PATH="$(resolve_config_path)"
    STORAGE_ROOT="$(extract_storage_root "$CONFIG_PATH")"
  fi

  QUEUE_DIR="$STORAGE_ROOT/exec-queue/new"
  COMMAND_QUEUE_DIR="$STORAGE_ROOT/command-queue/new"
  COMMAND_RESULTS_DIR="$STORAGE_ROOT/command-results"
  SCHEDULED_QUEUE_DIR="$STORAGE_ROOT/scheduled-queue"
  RESULTS_DIR="$STORAGE_ROOT/exec-results"
  MANAGED_PROCESSES_DIR="$STORAGE_ROOT/managed-processes"

  mkdir -p "$QUEUE_DIR" "$RESULTS_DIR"
  mkdir -p "$COMMAND_QUEUE_DIR"
  mkdir -p "$COMMAND_RESULTS_DIR"
  mkdir -p "$SCHEDULED_QUEUE_DIR"
  mkdir -p "$MANAGED_PROCESSES_DIR"
}

initialize_runtime_layout

log() {
  local timestamp line log_dir
  timestamp="$(date --iso-8601=seconds)"
  line="[$timestamp] $*"

  printf '%s\n' "$line" >&2

  "$LOGGER_BIN" -t "$LOG_TAG" -- "$*" 2>/dev/null || true

  if [[ -n "$OPTIONAL_LOG_FILE" ]]; then
    log_dir="$(dirname "$OPTIONAL_LOG_FILE")"
    if [[ -d "$log_dir" ]] || mkdir -p "$log_dir" 2>/dev/null; then
      printf '%s\n' "$line" >>"$OPTIONAL_LOG_FILE" 2>/dev/null || true
    fi
  fi
}

publish_stdin_atomically() {
  local target_path target_dir target_name temp_path
  target_path="$1"
  target_dir="$(dirname "$target_path")"
  target_name="$(basename "$target_path")"
  temp_path="$target_dir/.${target_name}.$$.tmp"

  if ! cat >"$temp_path"; then
    rm -f "$temp_path"
    return 1
  fi

  if ! mv "$temp_path" "$target_path"; then
    rm -f "$temp_path"
    return 1
  fi
}

find_project_root() {
  local dir
  dir="$(pwd -P)"
  while [[ "$dir" != "/" ]]; do
    if [[ -f "$dir/PROJECT.md" ]]; then
      printf '%s\n' "$dir"
      return 0
    fi
    dir="$(dirname "$dir")"
  done

  printf '%s\n' "$DEFAULT_PROJECT"
}

build_command() {
  local invoked
  invoked="$1"
  shift
  printf '%q' "$invoked"
  for arg in "$@"; do
    printf ' %q' "$arg"
  done
  printf '\n'
}

codex_exec_has_output_flag() {
  local arg
  for arg in "$@"; do
    case "$arg" in
      -o|--output-last-message|--output-last-message=*|-o?*)
        return 0
        ;;
    esac
  done

  return 1
}

codex_extract_output_flag_path() {
  local args index next_arg
  args=("$@")

  for ((index = 0; index < ${#args[@]}; index++)); do
    case "${args[$index]}" in
      -o)
        next_arg="${args[$((index + 1))]-}"
        printf '%s\n' "$next_arg"
        return 0
        ;;
      -o?*)
        printf '%s\n' "${args[$index]#-o}"
        return 0
        ;;
      --output-last-message)
        next_arg="${args[$((index + 1))]-}"
        printf '%s\n' "$next_arg"
        return 0
        ;;
      --output-last-message=*)
        printf '%s\n' "${args[$index]#--output-last-message=}"
        return 0
        ;;
    esac
  done

  return 1
}

codex_requests_help() {
  local arg
  for arg in "$@"; do
    case "$arg" in
      -h|--help)
        return 0
        ;;
    esac
  done

  return 1
}

json_escape() {
  local value
  value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "$value"
}

resolve_real_command() {
  local command_name shim_dir filtered_path resolved
  command_name="$1"
  shim_dir="$RUNTIME_ROOT/bin/shims"
  filtered_path="$(printf '%s' "${PATH:-}" | awk -v RS=: -v ORS=: -v shim="$shim_dir" '$0 != shim { print }' | sed 's/:$//')"
  resolved="$(PATH="$filtered_path" command -v "$command_name" || true)"
  if [[ -z "$resolved" ]]; then
    return 1
  fi
  printf '%s\n' "$resolved"
}

extract_exit_code() {
  local result_file exit_code
  result_file="$1"
  exit_code="$(grep -m1 '"exit_code"' "$result_file" | sed -E 's/.*"exit_code":[[:space:]]*([0-9-]+).*/\1/')"
  if [[ -z "$exit_code" ]]; then
    printf '1\n'
    return 0
  fi
  printf '%s\n' "$exit_code"
}

pause_briefly() {
  local seconds
  seconds="$1"
  read -r -t "$seconds" _ </dev/null || true
}

await_result_and_exit() {
  while [[ ! -f "$RESULT_FILE" ]]; do
    if (( "$(date +%s)" >= DEADLINE )); then
      log "starter timeout job_id=$JOB_ID result_file=$RESULT_FILE"
      echo "starter timeout waiting for $JOB_ID" >&2
      exit 124
    fi
    pause_briefly 0.2
  done

  log "starter result_ready job_id=$JOB_ID result_file=$RESULT_FILE"

  if [[ -f "$STDOUT_FILE" ]]; then
    cat "$STDOUT_FILE"
  fi

  if [[ -f "$STDERR_FILE" ]]; then
    cat "$STDERR_FILE" >&2
  fi

  EXIT_CODE="$(extract_exit_code "$RESULT_FILE")"
  log "starter finish job_id=$JOB_ID exit_code=$EXIT_CODE"
  exit "$EXIT_CODE"
}

schedule_usage() {
  echo "usage: schedule --when '<datetime or +7 days>' '<prompt>'" >&2
}

start_usage() {
  echo "usage: start --name <process-name> [--cwd <dir>] [--pid-file <file>] -- <command...>" >&2
}

stop_usage() {
  echo "usage: stop <process-name>" >&2
}

run_usage() {
  echo "usage: run <path-to-script> [args...]" >&2
}

parse_due_prefix() {
  local when_spec due
  when_spec="$1"
  due="$(date -d "$when_spec" +%Y%m%d-%H%M%S 2>/dev/null || true)"
  if [[ -z "$due" ]]; then
    return 1
  fi
  printf '%s\n' "$due"
}

sanitize_process_name() {
  local name
  name="$1"
  if [[ ! "$name" =~ ^[A-Za-z0-9._-]+$ ]]; then
    return 1
  fi
  printf '%s\n' "$name"
}

resolve_script_path() {
  local script_arg resolved
  script_arg="$1"
  resolved="$(readlink -f "$script_arg" 2>/dev/null || true)"
  if [[ -z "$resolved" || ! -f "$resolved" ]]; then
    return 1
  fi
  printf '%s\n' "$resolved"
}

build_script_execution_command() {
  local script_path
  script_path="$1"
  shift

  if [[ ! -x "$script_path" ]]; then
    return 1
  fi

  build_command "$script_path" "$@"
}

PROJECT_ROOT="$(find_project_root)"
INVOKED_NAME="$(basename "$0")"
COMMAND_STRING="$(build_command "$INVOKED_NAME" "$@")"
JOB_ID="$(date +%Y%m%d-%H%M%S-%6N)"
JOB_FILE="$QUEUE_DIR/$JOB_ID.json"
RESULT_FILE="$RESULTS_DIR/$JOB_ID.result.json"
STDOUT_FILE="$RESULTS_DIR/$JOB_ID.stdout.log"
STDERR_FILE="$RESULTS_DIR/$JOB_ID.stderr.log"
DEADLINE="$(( $(date +%s) + TIMEOUT_SECONDS ))"

log "starter begin invoked=$INVOKED_NAME cwd=$(pwd -P) project=$PROJECT_ROOT job_id=$JOB_ID command=$COMMAND_STRING"

if [[ "$INVOKED_NAME" == "sleep" ]]; then
  echo "sleep запрещен в этом окружении; используй: schedule --when '<datetime or +7 days>' '<prompt>'" >&2
  exit 2
fi

if [[ "$INVOKED_NAME" == "timeout" ]]; then
  echo "timeout недоступен в этом окружении" >&2
  exit 2
fi

if [[ "$INVOKED_NAME" == "schedule" ]]; then
  if [[ "$#" -lt 3 || "$1" != "--when" ]]; then
    schedule_usage
    exit 2
  fi

  if [[ -z "${RUNTIME_SID-}" ]]; then
    echo "schedule доступен только из runtime-сессии; переменная RUNTIME_SID не установлена" >&2
    exit 2
  fi

  WHEN_SPEC="$2"
  shift 2
  PROMPT_TEXT="$(printf '%s' "$*")"
  if [[ -z "$PROMPT_TEXT" ]]; then
    echo "schedule requires non-empty prompt" >&2
    schedule_usage
    exit 2
  fi

  DUE_PREFIX="$(parse_due_prefix "$WHEN_SPEC" || true)"
  if [[ -z "$DUE_PREFIX" ]]; then
    echo "schedule failed to parse --when value: $WHEN_SPEC" >&2
    exit 2
  fi

  SCHEDULED_ID="$DUE_PREFIX-$(date +%6N)"
  SCHEDULED_FILE="$SCHEDULED_QUEUE_DIR/$SCHEDULED_ID.json"
  log "starter schedule_begin runtime_sid=${RUNTIME_SID-} due=$DUE_PREFIX prompt=$(printf '%q' "$PROMPT_TEXT") file=$SCHEDULED_FILE"

  if ! publish_stdin_atomically "$SCHEDULED_FILE" <<JSON
{
  "id": "$SCHEDULED_ID",
  "scheduled_at": "$DUE_PREFIX",
  "target_queue": "manager",
  "created_at": "$(date --iso-8601=seconds)",
  "payload": {
    "type": "scheduled_prompt",
    "session_id": "$(json_escape "${RUNTIME_SID-}")",
    "text": "$(json_escape "$PROMPT_TEXT")",
    "meta": {
      "source": "starter-schedule",
      "scheduled_by_codex_sid": "$(json_escape "${CODEX_SID-}")",
      "scheduled_by_runtime_sid": "$(json_escape "${RUNTIME_SID-}")",
      "scheduled_when": "$(json_escape "$WHEN_SPEC")"
    }
  }
}
JSON
  then
    log "starter schedule_write_failed file=$SCHEDULED_FILE"
    echo "schedule failed to write scheduled job: $SCHEDULED_FILE" >&2
    exit 1
  fi

  log "starter schedule_queued id=$SCHEDULED_ID due=$DUE_PREFIX file=$SCHEDULED_FILE"
  printf 'Отложенная задача поставлена: %s\nКогда наступит время — отправлю это в текущую сессию.\n' "$SCHEDULED_ID"
  exit 0
fi

if [[ "$INVOKED_NAME" == "start" ]]; then
  PROCESS_NAME=""
  TARGET_CWD="$(pwd -P)"
  TARGET_PID_FILE=""

  while [[ "$#" -gt 0 ]]; do
    case "$1" in
      --name)
        shift
        PROCESS_NAME="${1-}"
        ;;
      --cwd)
        shift
        TARGET_CWD="${1-}"
        ;;
      --pid-file)
        shift
        TARGET_PID_FILE="${1-}"
        ;;
      --)
        shift
        break
        ;;
      *)
        start_usage
        exit 2
        ;;
    esac
    shift
  done

  if [[ -z "$PROCESS_NAME" || "$#" -eq 0 ]]; then
    start_usage
    exit 2
  fi

  PROCESS_NAME="$(sanitize_process_name "$PROCESS_NAME" || true)"
  if [[ -z "$PROCESS_NAME" ]]; then
    echo "start requires process name matching [A-Za-z0-9._-]+" >&2
    exit 2
  fi

  TARGET_CWD="$(readlink -f "$TARGET_CWD" 2>/dev/null || true)"
  if [[ -z "$TARGET_CWD" || ! -d "$TARGET_CWD" ]]; then
    echo "start failed: invalid --cwd directory" >&2
    exit 2
  fi

  if [[ -n "$TARGET_PID_FILE" ]]; then
    TARGET_PID_FILE="$(readlink -m "$TARGET_PID_FILE" 2>/dev/null || true)"
    if [[ -z "$TARGET_PID_FILE" ]]; then
      echo "start failed: invalid --pid-file path" >&2
      exit 2
    fi
  fi

  PROCESS_DIR="$MANAGED_PROCESSES_DIR/$PROCESS_NAME"
  PID_FILE="$PROCESS_DIR/pid"
  PGID_FILE="$PROCESS_DIR/pgid"
  PROCESS_LOG="$PROCESS_DIR/stdout.log"
  PROCESS_META="$PROCESS_DIR/meta.json"
  START_COMMAND="$(build_command "$1" "${@:2}")"
  JOB_FILE="$QUEUE_DIR/$JOB_ID.json"
  DETACHED_COMMAND="$(cat <<BASH
mkdir -p $(printf '%q' "$PROCESS_DIR")
if [[ -f $(printf '%q' "$PID_FILE") ]]; then
  existing_pid="\$(cat $(printf '%q' "$PID_FILE") 2>/dev/null || true)"
  if [[ -n "\${existing_pid:-}" ]] && kill -0 "\$existing_pid" 2>/dev/null; then
    echo "managed process already running: $PROCESS_NAME pid=\$existing_pid"
    exit 1
  fi
  rm -f $(printf '%q' "$PID_FILE")
fi
rm -f $(printf '%q' "$PGID_FILE")
if ! command -v setsid >/dev/null 2>&1; then
  echo "start failed: setsid is required for managed processes" >&2
  exit 1
fi
nohup setsid bash -lc $(printf '%q' "cd $(printf '%q' "$TARGET_CWD") && exec $START_COMMAND") >>$(printf '%q' "$PROCESS_LOG") 2>&1 < /dev/null &
process_pid=\$!
if [[ -n $(printf '%q' "$TARGET_PID_FILE") ]]; then
  for _ in 1 2 3 4 5; do
    if [[ -f $(printf '%q' "$TARGET_PID_FILE") ]]; then
      delegated_pid="\$(cat $(printf '%q' "$TARGET_PID_FILE") 2>/dev/null || true)"
      if [[ -n "\${delegated_pid:-}" ]] && kill -0 "\$delegated_pid" 2>/dev/null; then
        process_pid="\$delegated_pid"
        break
      fi
    fi
    read -r -t 1 _ </dev/null || true
  done
fi
process_pgid=""
for _ in 1 2 3 4 5; do
  process_pgid="\$(ps -o pgid= -p "\$process_pid" 2>/dev/null | tr -d '[:space:]')"
  if [[ -n "\${process_pgid:-}" ]]; then
    break
  fi
  read -r -t 1 _ </dev/null || true
done
printf '%s\n' "\$process_pid" >$(printf '%q' "$PID_FILE")
if [[ -n "\${process_pgid:-}" ]]; then
  printf '%s\n' "\$process_pgid" >$(printf '%q' "$PGID_FILE")
fi
cat >$(printf '%q' "$PROCESS_META") <<'JSON'
{
  "name": "$(json_escape "$PROCESS_NAME")",
  "cwd": "$(json_escape "$TARGET_CWD")",
  "command": "$(json_escape "$START_COMMAND")",
  "pid_file": "$(json_escape "$PID_FILE")",
  "pgid_file": "$(json_escape "$PGID_FILE")",
  "delegated_pid_file": "$(json_escape "$TARGET_PID_FILE")",
  "log_file": "$(json_escape "$PROCESS_LOG")",
  "started_by_runtime_sid": "$(json_escape "${RUNTIME_SID-}")",
  "started_by_codex_sid": "$(json_escape "${CODEX_SID-}")",
  "created_at": "$(date --iso-8601=seconds)"
}
JSON
if [[ -n "\${process_pgid:-}" ]]; then
  echo "managed process started: $PROCESS_NAME pid=\$process_pid pgid=\$process_pgid"
else
  echo "managed process started: $PROCESS_NAME pid=\$process_pid"
fi
echo "pid_file=$PID_FILE"
echo "pgid_file=$PGID_FILE"
echo "log_file=$PROCESS_LOG"
BASH
)"

  if ! publish_stdin_atomically "$JOB_FILE" <<JSON
{
  "id": "$JOB_ID",
  "project": "$(json_escape "$PROJECT_ROOT")",
  "title": "$(json_escape "starter start: $PROCESS_NAME")",
  "command": "$(json_escape "$DETACHED_COMMAND")",
  "cwd": "$(json_escape "$TARGET_CWD")",
  "timeout": 60,
  "created_at": "$(date --iso-8601=seconds)",
  "meta": {
    "source": "starter-start",
    "managed_process_name": "$(json_escape "$PROCESS_NAME")"
  }
}
JSON
  then
    log "starter start_write_failed job_id=$JOB_ID process=$PROCESS_NAME"
    echo "starter failed to write start job file: $JOB_FILE" >&2
    exit 1
  fi

  log "starter start_queued job_id=$JOB_ID process=$PROCESS_NAME cwd=$TARGET_CWD command=$START_COMMAND"
  await_result_and_exit
fi

if [[ "$INVOKED_NAME" == "stop" ]]; then
  if [[ "$#" -ne 1 ]]; then
    stop_usage
    exit 2
  fi

  PROCESS_NAME="$(sanitize_process_name "$1" || true)"
  if [[ -z "$PROCESS_NAME" ]]; then
    echo "stop requires process name matching [A-Za-z0-9._-]+" >&2
    exit 2
  fi

  PROCESS_DIR="$MANAGED_PROCESSES_DIR/$PROCESS_NAME"
  PID_FILE="$PROCESS_DIR/pid"
  PGID_FILE="$PROCESS_DIR/pgid"
  PROCESS_META="$PROCESS_DIR/meta.json"
  JOB_FILE="$QUEUE_DIR/$JOB_ID.json"
  STOP_COMMAND="$(cat <<BASH
if [[ ! -f $(printf '%q' "$PID_FILE") && ! -f $(printf '%q' "$PGID_FILE") ]]; then
  echo "managed process is not running: $PROCESS_NAME"
  exit 0
fi
process_pid="\$(cat $(printf '%q' "$PID_FILE") 2>/dev/null || true)"
process_pgid="\$(cat $(printf '%q' "$PGID_FILE") 2>/dev/null || true)"
if [[ -z "\${process_pgid:-}" && -n "\${process_pid:-}" ]]; then
  process_pgid="\$(ps -o pgid= -p "\$process_pid" 2>/dev/null | tr -d '[:space:]')"
fi
if [[ "\${process_pgid:-}" =~ ^[0-9]+$ ]] && kill -0 -- "-\$process_pgid" 2>/dev/null; then
  kill -- "-\$process_pgid"
  echo "managed process group stopped: $PROCESS_NAME pgid=\$process_pgid"
elif [[ -n "\${process_pid:-}" ]] && kill -0 "\$process_pid" 2>/dev/null; then
  kill "\$process_pid"
  echo "managed process stopped: $PROCESS_NAME pid=\$process_pid"
else
  echo "managed process already exited: $PROCESS_NAME pid=\${process_pid:-} pgid=\${process_pgid:-}"
fi
rm -f $(printf '%q' "$PID_FILE")
rm -f $(printf '%q' "$PGID_FILE")
BASH
)"

  if ! publish_stdin_atomically "$JOB_FILE" <<JSON
{
  "id": "$JOB_ID",
  "project": "$(json_escape "$PROJECT_ROOT")",
  "title": "$(json_escape "starter stop: $PROCESS_NAME")",
  "command": "$(json_escape "$STOP_COMMAND")",
  "cwd": "$(json_escape "$PROJECT_ROOT")",
  "timeout": 60,
  "created_at": "$(date --iso-8601=seconds)",
  "meta": {
    "source": "starter-stop",
    "managed_process_name": "$(json_escape "$PROCESS_NAME")"
  }
}
JSON
  then
    log "starter stop_write_failed job_id=$JOB_ID process=$PROCESS_NAME"
    echo "starter failed to write stop job file: $JOB_FILE" >&2
    exit 1
  fi

  log "starter stop_queued job_id=$JOB_ID process=$PROCESS_NAME"
  await_result_and_exit
fi

if [[ "$INVOKED_NAME" == "run" ]]; then
  if [[ "$#" -lt 1 ]]; then
    run_usage
    exit 2
  fi

  SCRIPT_PATH="$(resolve_script_path "$1" || true)"
  if [[ -z "$SCRIPT_PATH" ]]; then
    echo "run failed: cannot resolve script path: $1" >&2
    exit 2
  fi
  shift

  COMMAND_STRING="$(build_script_execution_command "$SCRIPT_PATH" "$@" || true)"
  if [[ -z "$COMMAND_STRING" ]]; then
    echo "run failed: target must be executable: $SCRIPT_PATH" >&2
    exit 2
  fi

  log "starter run_resolved script=$SCRIPT_PATH command=$COMMAND_STRING"
fi

if [[ "$INVOKED_NAME" == "codex" ]]; then
  if [[ -z "${CODEX_SID-}" || -z "${RUNTIME_SID-}" ]] || codex_requests_help "$@"; then
    REAL_CODEX="$(resolve_real_command codex || true)"
    if [[ -z "$REAL_CODEX" ]]; then
      echo "starter failed to resolve real codex binary" >&2
      exit 1
    fi
    log "starter codex_sync_proxy real=$REAL_CODEX cwd=$(pwd -P) command=$COMMAND_STRING"
    exec "$REAL_CODEX" "$@"
  fi

  if [[ "$#" -ge 3 && "$1" == "exec" && "$2" == "resume" && "$3" == "${CODEX_SID}" ]]; then
    echo "starter refused to enqueue background codex: resume into current CODEX_SID (${CODEX_SID}) is forbidden" >&2
    exit 2
  fi

  COMMAND_LAST_MESSAGE_FILE=""
  if [[ "$#" -ge 1 && "$1" == "exec" ]]; then
    COMMAND_LAST_MESSAGE_FILE="$(codex_extract_output_flag_path "$@" || true)"
    if [[ -z "$COMMAND_LAST_MESSAGE_FILE" ]] && ! codex_exec_has_output_flag "$@"; then
      COMMAND_LAST_MESSAGE_FILE="$COMMAND_RESULTS_DIR/$JOB_ID.last-message.txt"
      set -- "$@" -o "$COMMAND_LAST_MESSAGE_FILE"
    fi
  fi

  CODEx_COMMAND="$(build_command codex "$@")"
  JOB_FILE="$COMMAND_QUEUE_DIR/$JOB_ID.json"
  JOB_TMP_FILE="$COMMAND_QUEUE_DIR/.$JOB_ID.$$.tmp"
  log "starter codex_async_begin cwd=$(pwd -P) project=$PROJECT_ROOT job_id=$JOB_ID command=$CODEx_COMMAND runtime_sid=${RUNTIME_SID-} codex_sid=${CODEX_SID-}"

  if ! cat >"$JOB_TMP_FILE" <<JSON
{
  "id": "$JOB_ID",
  "project": "$(json_escape "$PROJECT_ROOT")",
  "title": "$(json_escape "starter codex: $CODEx_COMMAND")",
  "command": "$(json_escape "$CODEx_COMMAND")",
  "cwd": "$(json_escape "$(pwd -P)")",
  "timeout": $TIMEOUT_SECONDS,
  "created_at": "$(date --iso-8601=seconds)",
  "meta": {
    "source": "starter-codex",
    "bridge_to_manager": true,
    "origin_runtime_session_id": "$(json_escape "${RUNTIME_SID-}")",
    "origin_codex_session_id": "$(json_escape "${CODEX_SID-}")",
    "last_message_path": "$(json_escape "$COMMAND_LAST_MESSAGE_FILE")"
  }
}
JSON
  then
    rm -f "$JOB_TMP_FILE"
    log "starter codex_async_write_failed job_id=$JOB_ID job_file=$JOB_FILE temp_file=$JOB_TMP_FILE"
    echo "starter failed to write codex job file: $JOB_FILE" >&2
    exit 1
  fi

  if ! mv "$JOB_TMP_FILE" "$JOB_FILE"; then
    rm -f "$JOB_TMP_FILE"
    log "starter codex_async_rename_failed job_id=$JOB_ID job_file=$JOB_FILE temp_file=$JOB_TMP_FILE"
    echo "starter failed to publish codex job file: $JOB_FILE" >&2
    exit 1
  fi

  log "starter codex_async_queued job_id=$JOB_ID job_file=$JOB_FILE"
  printf 'Фоновая задача поставлена: %s\nКогда завершится — сообщу результат сюда.\n' "$JOB_ID"
  exit 0
fi

if ! publish_stdin_atomically "$JOB_FILE" <<JSON
{
  "id": "$JOB_ID",
  "project": "$(json_escape "$PROJECT_ROOT")",
  "title": "$(json_escape "starter $INVOKED_NAME: $COMMAND_STRING")",
  "command": "$(json_escape "$COMMAND_STRING")",
  "cwd": "$(json_escape "$(pwd -P)")",
  "timeout": $TIMEOUT_SECONDS,
  "created_at": "$(date --iso-8601=seconds)"
}
JSON
then
  log "starter write_failed job_id=$JOB_ID job_file=$JOB_FILE"
  echo "starter failed to write job file: $JOB_FILE" >&2
  exit 1
fi

log "starter queued job_id=$JOB_ID job_file=$JOB_FILE"
await_result_and_exit
