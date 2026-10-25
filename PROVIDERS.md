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
## Доступность провайдера

Провайдер доступен, если одновременно:

- его имя входит в список `codex`, `claude`;
- значение `<provider>.bin` (`codex.bin`, `claude.bin`) находится в `PATH`;
- для `codex`: `codex.cwd` существует;
- для `claude`: `claude.cwd` существует, `claude.models` — непустой список,
  `claude.model` в него входит.

Доступность проверяется при каждой команде `/provider` и перед каждым turn.
Результат не кэшируется.

Причина недоступности — первая из проверок по порядку:

- `<bin> не найден в PATH`
- `каталог <cwd> не существует`
- `не задан claude.models`
- `не задан claude.model`
- `claude.model <model> не входит в claude.models`

- При старте Core обязан быть доступен `provider.default`; иначе doctor
  сообщает `provider.default <name> is not available: <причина>`. Недопустимое
  значение — `provider.default must be codex or claude: <value>`. Проверки
  `codex.cwd` и `codex.bin` выполняются только для провайдера по умолчанию. Наличие `codex` в `PATH` больше не
  обязательно, если `provider.default = claude`.
- Turn в сессии, чей провайдер недоступен, завершается ошибкой. Другой
  провайдер не подставляется. Пользователь получает `kind=system`:
  - для начатой сессии: `Провайдер <name> недоступен: <причина>. Отправьте /reset, чтобы начать новую сессию.`;
  - для неначатой сессии: `Провайдер <name> недоступен: <причина>. Выберите другой провайдер командой /provider.`
- Если недоступен `codex`, функции, которые всегда работают через него
  (`/limits` и список `/session` в сессии `codex`, `/model` для `codex`,
  Luna-классификатор),
  отвечают явным сообщением о недоступности `codex`. Core при этом не падает.

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

- `/provider` без аргумента отвечает `Текущий провайдер: <name>.`, пустой
  строкой и списком только доступных провайдеров копируемыми
  inline-code командами `` `/provider <name>` ``; провайдер из
  `provider.default` помечается ` (default)`.
- `/provider <name>` для известного, но недоступного провайдера ничего не
  меняет и отвечает `Провайдер <name> недоступен: <причина>.`
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
- `/codex` и `/claude` без аргументов — короткие формы `/provider codex` и
  `/provider claude`: те же проверки, ответы и изменения state. Поддерживаются
  формы `/codex@<bot>` и `/claude@<bot>`.

## Команда `/login`

- Авторизация Codex и Claude независима. Core не запускает оба flow подряд.
- Все формы `/login` разрешены только в private chat.
- `/login` сначала проверяет наличие каждого настроенного бинарника в `PATH`.
  Для найденного бинарника Core читает статус авторизации, для отсутствующего
  выводит причину. Затем показывает `/login codex` и `/login claude`.
- `/login codex` и `/login claude` до обращения к CLI проверяют соответствующий
  бинарник в `PATH`. Отсутствие бинарника завершает команду явной ошибкой.
- `/login codex` использует device-code flow `codex login --device-auth`.
- `/login claude` использует OAuth flow `claude auth login --claudeai`, выдаёт
  пользователю URL с кнопкой `Отмена` и ожидает следующее обычное текстовое
  сообщение в том же private runtime session как OAuth code. Сообщение с code
  не попадает в manager queue.
- При поступлении code Core сначала снимает shared-признак ожидания, затем
  передаёт code в Claude CLI. Ошибка проверки не восстанавливает ожидание.
- Нажатие `Отмена` приходит как служебный callback, завершает Claude OAuth
  process и снимает shared-признак ожидания.
- Одновременно может существовать один незавершённый Claude OAuth flow. Сам
  process живёт только в памяти `control_watcher`; shared runtime state нужен
  только для маршрутизации следующего сообщения в control queue.
- Auth-процессы не получают `CODEX_SID`, `RUNTIME_SID` и `CLAUDE_SID`.

## Провайдер из метаданных сообщения

Transport может задать провайдер сессии полем `meta.provider` входящего
сообщения. В Core оно остаётся `meta.provider` события
`user_message`. Поле обрабатывается до запуска turn.

- Поле отсутствует — поведение не меняется.
- Значение не входит в список `codex`, `claude` (включая пустую строку и
  не-строку) — turn завершается ошибкой, пользователь получает `kind=system`:
  `Неизвестный провайдер в метаданных сообщения: <value в JSON>.` State не
  меняется.
- Сессия не начата — Core выполняет то же, что `/provider <name>`:
  записывает `session_providers[runtime_session_id]` и, если `<name>`
  отличается от прежнего эффективного провайдера, удаляет
  `session_models[runtime_session_id]`. Затем действуют общие правила
  доступности: недоступный провайдер завершает turn ошибкой.
- Сессия начата с тем же провайдером — поле игнорируется.
- Сессия начата с другим провайдером — нарушение контракта: turn завершается
  ошибкой, пользователь получает `kind=system`:
  `Сессия уже начата с провайдером <current>, сообщение требует провайдер <name>.`
  State не меняется.

## Влияние на остальные команды

- `/model` работает с моделями эффективного провайдера сессии. Для `codex` —
  как сейчас; для `claude` список берётся из `claude.models`, default — из
  `claude.model`.
- `/session` для `codex` работает как сейчас. Для `claude` на этом этапе
  отвечает `kind=system`: `Список сессий для провайдера claude не поддерживается.`
  `/session <id>` для `claude` сохраняет id и провайдер `claude` без проверки
  существования сессии.
- `/limits` показывает лимиты провайдера текущей сессии: для `codex` — как
  сейчас, для `claude` — см. «Лимиты Claude».
- После `kind=final` Core проверяет пороги лимитов провайдера той сессии, в
  которую отправлен final.
- Luna-классификатор голосовых ответов всегда работает через `codex` и от
  провайдера сессии не зависит.

## Лимиты Claude

CLI `claude` не отдаёт лимиты отдельной командой. Core берёт их из события
`{"type":"rate_limit_event"}` потока `stream-json`, которое приходит во время
turn. Событие недокументировано; его формат проверен на `claude 2.1.306`.

- Из события используется только `rate_limit_info.unifiedWindows`:
  - `five_hour` → окно `primary` (`5 часов`);
  - `seven_day` → окно `secondary` (`7 дней`);
  - `usedPercent = round(utilization * 100)`, `resetsAt` — как есть.
- Отсутствующее окно не сохраняется. Окно с нечисловым `utilization` или
  нецелым `resetsAt` — ошибка разбора: событие журналируется и
  отбрасывается, turn продолжается.
- Последнее успешно разобранное событие сохраняется в
  `<storage.root>/state/claude-rate-limits.json`:

  ```json
  {"observed_at":1792680000,"primary":{"usedPercent":14,"resetsAt":1792683000},"secondary":{"usedPercent":2,"resetsAt":1792886400}}
  ```

  Лимиты общие для аккаунта, поэтому файл один на Core, а не на сессию.
- `/limits` в сессии `claude` отвечает `kind=system` в том же формате, что и
  для Codex, но с заголовком `Лимиты Claude`, без строки тарифа и с
  последней строкой `Получено <интервал> назад` (`Получено менее минуты
  назад`, если прошло меньше минуты). Интервал форматируется так же, как
  время сброса.
- Если файла ещё нет, `/limits` отвечает:
  `Лимиты claude пока неизвестны: они приходят вместе с ответом claude.`
- После `kind=final` в сессии `claude` Core читает сохранённые лимиты и
  проверяет те же пороги `limits.*_remaining_warning_percent`. Warning:
  `Внимание: заканчиваются лимиты Claude.` и окна ниже порога. Если файла нет,
  проверка пропускается.
- Повреждённый файл — ошибка операции, не маскируется.

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
  `CODEX_SID` задаётся `CLAUDE_SID` (только при resume). Унаследованный
  `CODEX_SID` в окружение `claude` не передаётся.
- Сырой stdout/stderr пишется в `codex.debug_dir` так же, как для Codex, с
  префиксом `claude-run-`.
- Активный процесс регистрируется в `ActiveTurnRegistry`; `/stop` работает
  без изменений.

Разбор stdout (JSON Lines):

- `session_id` берётся из события `{"type":"system","subtype":"init"}` и из
  события `{"type":"result"}`.
- Каждый текстовый блок `{"type":"assistant"}` (`message.content[].type=text`),
  после которого turn продолжился, уходит как `commentary`. Turn считается
  продолжившимся только при следующем событии `assistant` или `user`.
  Прочие события (`rate_limit_event`, `system` и т.п.) текст не выталкивают
  и не сбрасывают.
- Финальный текст — поле `result` события `{"type":"result"}`.
- `is_error=true` в событии `result`, ненулевой exit code или отсутствие
  события `result` — ошибка turn; возвращаемый exit code в этом случае
  ненулевой, текст — поле `result`. Если финальный текст пуст, пользователь
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
