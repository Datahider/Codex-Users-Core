# Провайдеры агента

Core запускает turn через один из провайдеров:

- `codex` — `codex exec`;
- `claude` — `claude -p` (Claude Code CLI).

Провайдер выбирается для каждого `runtime_session_id`. Router и transport о
провайдере ничего не знают: для них это обычная runtime-сессия.

## Конфигурация

```php
'provider' => [
    // Провайдер новых runtime-сессий. Если не задан, используется codex.
    'default' => 'codex',
],
'codex' => [
    // без изменений
],
'claude' => [
    'bin' => 'claude',
    'model' => 'opus',
    'models' => ['opus', 'sonnet', 'haiku'],
    // По умолчанию домашний каталог пользователя.
    'cwd' => $home,
    'extra_args' => [],
],
```

- `provider.default` принимает только `codex` или `claude`; любое другое
  значение — ошибка конфигурации при старте Core.
- Секция `codex` не меняется.
- `claude.bin` по умолчанию `claude`, `claude.cwd` — домашний каталог
  пользователя, `claude.extra_args` — пустой список.
- `claude.model` и `claude.models` обязательны для провайдера `claude`.
  `claude.models` — непустой список, `claude.model` обязан в него входить.
- Если секция `claude` не настроена, Core стартует штатно. Выбор `claude`
  (командой или через `provider.default`) завершается понятным сообщением с
  перечнем недостающих параметров.

## State

Провайдер хранится в `session_providers[runtime_session_id]` рядом с
`sessions` и `session_models` в manager state.

- Эффективный провайдер сессии: `session_providers[runtime_session_id]`, а при
  его отсутствии — `provider.default`.
- Сессия считается начатой, если существует `sessions[runtime_session_id]`.
- В момент, когда Core впервые записывает `sessions[runtime_session_id]`
  (первый turn или `/session <id>`), он записывает и
  `session_providers[runtime_session_id]` с эффективным провайдером. Обе записи
  выполняются в одной транзакции manager state.
- Начатая сессия без `session_providers[runtime_session_id]` (state, созданный
  версией Core до появления провайдеров) считается сессией `codex` независимо
  от `provider.default`. Core не переписывает такой state.

`/reset` удаляет `sessions[runtime_session_id]`, но не трогает
`session_providers` и `session_models`. После `/reset` сессия снова не начата,
и провайдер можно сменить.

## Команда `/provider`

- `/provider` без аргумента показывает эффективный провайдер текущей
  runtime-сессии и список провайдеров копируемыми inline-code командами
  `` `/provider <name>` ``; провайдер из `provider.default` помечается
  ` (default)`.
- `/provider <name>` для неначатой сессии сохраняет
  `session_providers[runtime_session_id]` и отвечает
  `Провайдер текущей сессии: <name>.`
- Если `<name>` отличается от прежнего эффективного провайдера,
  `session_models[runtime_session_id]` удаляется: модель возвращается к
  default нового провайдера.
- `/provider <name>` для начатой сессии ничего не меняет и отвечает:
  `Сессия уже начата с провайдером <current>. Сменить провайдер можно после /reset.`
- Неизвестное имя — ответ с текстом `Неизвестный провайдер: <name>` и списком
  команд.
- Все ответы — `kind=system`. Команда не попадает в manager queue.
- Поддерживается форма `/provider@<bot>` по тем же правилам, что и остальные
  transport-команды.

## Влияние на остальные команды

- `/model` работает с моделями эффективного провайдера сессии. Для `codex` —
  как сейчас; для `claude` список берётся из `claude.models`, default — из
  `claude.model`.
- `/session` для `codex` работает как сейчас. Для `claude` на этом этапе
  отвечает `kind=system`: `Список сессий для провайдера claude не поддерживается.`
  `/session <id>` для `claude` сохраняет id и провайдер `claude` без проверки
  существования сессии.
- `/limits` читает лимиты Codex. В сессии с провайдером `claude` отвечает:
  `Лимиты для провайдера claude не поддерживаются.`
- После `kind=final` в сессии `claude` Core лимиты не перечитывает и
  `kind=warning` не отправляет.
- Luna-классификатор голосовых ответов всегда работает через `codex` и от
  провайдера сессии не зависит.

## Запуск `claude`

Команда:

```text
<claude.bin> -p --output-format stream-json --verbose
  --dangerously-skip-permissions
  <claude.extra_args...>
  --model <effective_model>
  --mcp-config <json>
  [--resume <session_id>]
```

- Prompt передаётся через stdin. Рабочий каталог — `claude.cwd`.
- `--mcp-config` подключает тот же STDIO MCP-сервер, что и для Codex:

  ```json
  {"mcpServers":{"codex_runtime":{"command":"php","args":["<Core>/bin/mcp-server.php"],"env":{"RUNTIME_SID":"...","CODEX_CORE_CONFIG":"..."}}}}
  ```

- Окружение процесса такое же, как у Codex (`PATH` с `bin/shims`,
  `RUNTIME_SID`, `CODEX_CORE_CONFIG`, `CODEX_STORAGE_ROOT`), но вместо
  `CODEX_SID` задаётся `CLAUDE_SID`.
- Сырой stdout/stderr пишется в `codex.debug_dir` так же, как для Codex, с
  префиксом `claude-run-`.
- Активный процесс регистрируется в `ActiveTurnRegistry`; `/stop` работает
  без изменений.

Разбор stdout (JSON Lines):

- `session_id` берётся из события `{"type":"system","subtype":"init"}` и из
  события `{"type":"result"}`.
- Каждый текстовый блок `{"type":"assistant"}` (`message.content[].type=text`),
  после которого turn продолжился, уходит как `commentary`.
- Финальный текст — поле `result` события `{"type":"result"}`.
- `is_error=true` в событии `result`, ненулевой exit code или отсутствие
  события `result` — ошибка turn. Если финальный текст пуст, пользователь
  получает `Claude вернул ошибку:` и stderr.

## Ограничения первого этапа

- Shim `codex` в `bin/shims` (фоновые воркеры) не меняется; аналога для
  `claude` нет.
- Bundled skills копируются только в `CODEX_HOME`.
- Claude читает инструкции из `CLAUDE.md`, а не из `AGENTS.md`. Если нужны
  те же инструкции, в каталоге `claude.cwd` должен лежать `CLAUDE.md` со
  строкой `@AGENTS.md`. Core этот файл не создаёт.
- В отличие от Codex (`required=true`), Claude не умеет требовать MCP-сервер:
  при ошибке старта `bin/mcp-server.php` turn продолжится без tools.
