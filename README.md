# Codex Runtime Core

`codex-runtime` — это ядро рантайма для Codex-ботов, работающее через `Router`.

## Что делает репозиторий

- забирает входящие события из `Router`
- ставит runtime-задачи в очереди и обрабатывает их
- запускает `codex` или `claude` (см. [PROVIDERS.md](./PROVIDERS.md))
- хранит состояние рантайма на локальном диске
- поднимает и удерживает фоновые воркеры

## Зона ответственности

Ядро отвечает за:

- чтение входящих событий из `Router`
- обработку `manager-queue`
- запуск `codex`
- связь `runtime_session_id -> codex_session_id`
- команды управления вроде `/stop`, `/reset`, `/session`, `/model`, `/provider`
- выпуск отложенных задач в `manager-queue`
- исходящие семантические payload'ы для границы с транспортом

## Требования

- Linux
- PHP 8.1 или новее
- PHP-расширение `curl`
- `composer`
- `codex` в `PATH`
- `logger` в `PATH`
- доступный экземпляр `Router`
- валидный `Router` core token

## Быстрая установка

```bash
git clone <repo-url>
cd Core
composer install
mkdir -p ~/.codex-users-core
cp config/config.example.php ~/.codex-users-core/config.php
php bin/run-core.php
```

## Bundled skills

`Core` может хранить project-scoped skill source внутри `skills/`.

При старте `bin/run-core.php` должен:

- определить runtime `CODEX_HOME`
- проверить bundled skills из проекта
- скопировать их в `<CODEX_HOME>/skills/`, если skill отсутствует или содержимое отличается

Source of truth для таких skills находится внутри дерева проекта, а runtime-копия считается производной.

## Обязательная настройка

По умолчанию `php bin/run-core.php` ищет конфиг в:

```text
~/.codex-users-core/config.php
```

Если нужно, путь к конфигу можно передать первым аргументом.

Для запуска Core в конфиге нужно задать:

- `router.base_url`
- `router.core_token`
- `codex.cwd`, если `codex` должен запускаться из другого каталога

`codex.bin` по умолчанию равен `codex`, а `manager_queue.max_workers` — `1`.

Голосовые функции и отправка файлов опциональны. Их параметры не
блокируют запуск Core:

- `transcription.api_key` и `transcription.model` нужны для расшифровки входящего голоса;
- `speech.model`, `voice_response.default_voice` и
  `voice_response.allowed_voices` нужны для голосовых ответов;
- `file_exchange.base_url` и `file_exchange.token` нужны для
  `send_document`, `send_image` и голосовых ответов.

При обращении к ненастроенной функции Core не должен падать:
входящий пользователь получает понятное сообщение, а MCP tool возвращает
агенту ошибку с перечнем недостающих параметров.

`storage.root` менять не обязательно. По умолчанию он равен:

```text
~/.codex-users-core/var
```

Шаблон конфига лежит в [config/config.example.php](./config/config.example.php).

При старте `bin/run-core.php` сам:

- проверяет наличие и читаемость конфига
- валидирует обязательные `router.base_url` и `router.core_token`
- проверяет PHP-зависимости и нужные команды в `PATH`
- создает локальную runtime-структуру каталогов под `storage.root`

Shim-команды из `bin/shims` берут `storage.root` из того же конфига.
`bin/starter.sh` явно добавляет `--dangerously-bypass-approvals-and-sandbox` к каждому `codex exec`; этот контракт не зависит от конфига Codex.

## Запуск

В foreground:

```bash
php bin/run-core.php
```

Пример unit-файла для `systemd`:

- [systemd/codex-runtime-core.service](./systemd/codex-runtime-core.service)

## Smoke-проверки

```bash
php smoke/minimal-config-surface.php
php smoke/runtime-storage-layout.php
php smoke/doctor-ready-config.php
```

## Runtime layout

- очереди, логи, state и pid-файлы живут под `storage.root`
- `storage.root` по умолчанию — `~/.codex-users-core/var`
- состояние ядра лежит в `var/state`
- lock-файлы и pid-файлы воркеров лежат в `var/run`
- очереди ядра:
  - `manager-queue`
  - `control-queue`
  - `scheduled-queue`

## Managed-процессы

`start --name <name> -- <command...>` запускает процесс с уникальным
маркером владения и сохраняет маркер рядом с PID/PGID. PGID
сохраняется только после того, как `setsid` создал группу с PGID, равным
PID запущенного launcher-процесса. Унаследованная группа вызывающего Core-
процесса не считается managed-группой.

`stop <name>` посылает сигнал только если маркер в окружении живого процесса
точно совпадает с сохранённым. Отсутствующий или несовпадающий маркер —
ошибка; PID/PGID в этом случае не сигналируется. Если маркер PID совпал,
но сохранённый PGID не совпадает с текущим PGID процесса, `stop` сигналирует
только проверенному PID и не трогает чужую группу.

### Слоты manager worker

Число одновременно запущенных `manager_worker` ограничивается параметром
`manager_queue.max_workers` с дефолтом `1`. Каждый экземпляр до чтения очереди
должен атомарно занять один из файловых слотов
`<storage.root>/run/manager-worker-slot-<N>.lock`. Слот удерживается открытым
file lock на протяжении всей жизни worker и автоматически освобождается при
завершении процесса. Дескриптор slot-lock закрывается при `exec`, поэтому
запущенные worker-ом `codex` и tool-процессы не наследуют владение слотом.

Если свободного слота нет, новый экземпляр не читает и не изменяет очередь и
завершается штатно. В runtime-log фиксируются события `Manager worker slot
acquired`, `Manager worker capacity reached` и `Manager worker slot released`
с номером слота, PID и настроенным пределом. При `max_workers = 1` сохраняется
последовательная обработка одним manager worker; это начальный безопасный режим
для проверки новой модели ограничения.

После извлечения события из очереди worker запускает новый экземпляр
`manager_worker` до начала длительной обработки. Новый экземпляр самостоятельно
пытается занять слот: при наличии свободного слота он становится дежурным, при
достигнутом `max_workers` сразу завершается. Общий PID-файл для manager workers
не используется; основной supervisor определяет наличие manager worker по
занятым slot-lock.

Manager queue остаётся файловой. Перед переносом события из `new` в `running`
worker обязан захватить отдельный lock его `runtime_session_id`. Занятая сессия
пропускается без ошибки, поэтому другой worker может взять следующее доступное
событие другой сессии. Перенос `new -> running` является конкурентной попыткой:
если файл уже забрал другой worker, поиск продолжается без ошибки обработки.

Получив сессию, worker удерживает её lock и после первого события забирает все
следующие доступные события той же сессии в порядке приоритета и времени. Только
после опустошения очереди этой сессии lock освобождается.

Связь `runtime_session_id -> codex_session_id` изменяется только транзакционно
под lock manager-state. Активные процессы Codex регистрируются отдельным файлом
на каждый `runtime_session_id`; `/stop` читает и сигналирует только процесс
сессии, из которой пришла команда.

## Граница исходящих сообщений

Ядро может выпускать только такие outbound payload'ы:

- `final`
- `commentary`
- `transcript`
- `heartbeat`
- `status`
- `warning`
- `system`
- `document`
- `voice`

Как именно они рендерятся и доставляются, решает внешний transport-слой.

Контракт режимов `text`/`voice`, текстовых предохранителей, Luna-классификации
и `kind=voice` определён в [VOICE_RESPONSES.md](./VOICE_RESPONSES.md).

## Контроль лимитов Codex

Core читает ChatGPT-лимиты через JSON-RPC метод `account/rateLimits/read`
процесса `codex app-server`.

- transport-команда `/limits` не передаётся в manager queue; Core отвечает
  outbound-сообщением `kind=system` с остатком 5-часового и недельного окон и
  временем их сброса;
- ответы transport-команд `/reset` и `/session` также отправляются как
  `kind=system`;
- остаток окна вычисляется как `100 - usedPercent`;
- каждое окно отображается моноширинной полосой из 20 символов: `█` показывает
  оставшуюся долю, `░` — использованную; количество заполненных сегментов
  округляется до ближайших 5 процентов;
- название окна, полоса с процентом и время сброса выводятся отдельными строками;
- разные окна и тариф разделяются пустыми строками;
- время сброса показывается относительно текущего времени как
  `Сброс через <интервал>`; для наступившего времени выводится `Сброс сейчас`;
- только после каждого отправленного Core outbound-сообщения `kind=final` Core заново
  читает лимиты;
- после `kind=system`, включая `/limits`, Core лимиты повторно не проверяет и
  `kind=warning` не отправляет;
- если остаток 5-часового окна строго меньше
  `limits.primary_remaining_warning_percent` (по умолчанию `5`) или остаток недельного окна строго
  меньше `limits.secondary_remaining_warning_percent` (по умолчанию `1`), Core отправляет следом
  отдельное outbound-сообщение `kind=warning`;
- отсутствующее окно не участвует в проверке соответствующего порога;
- ошибка запуска `codex app-server`, ошибка JSON-RPC, преждевременное завершение
  процесса или некорректная структура ответа считаются ошибкой операции и не
  маскируются;

## Выбор модели

Раздел описывает провайдер `codex`. Модели провайдера `claude` и команда
`/provider` описаны в [PROVIDERS.md](./PROVIDERS.md).

`codex.model` в конфиге Core задаёт модель по умолчанию. Если параметр
отсутствует или пуст, Core использует `gpt-5.6-sol` и не считает это ошибкой
конфигурации. Core передаёт эффективную модель в каждый вызов `codex exec`
через `--model`.

- `/model` без аргумента показывает эффективную модель текущей
  runtime-сессии и список моделей из `codex debug models --bundled`;
- каждая модель в списке выводится как копируемая inline-code команда
  `` `/model <slug>` ``;
- строка для конфиговой или резервной default-модели дополнительно
  помечается текстом ` (default)` за закрывающим обратным апострофом;
- `/model <slug>` проверяет `slug` по текущему списку Codex и сохраняет
  override только для текущего `runtime_session_id`;
- override применяется со следующего turn, в том числе при `codex exec resume`;
- если override нет, используется `codex.model`, а при его отсутствии — `gpt-5.6-sol`;
- Core не изменяет `config.toml` Codex;
- ошибка запуска `codex debug models --bundled`, некорректный JSON или
  пустой список моделей считаются ошибкой операции.

Состояние override хранится отдельно от mapping `runtime_session_id -> codex_session_id`
в `session_models[runtime_session_id]`. `/reset` не удаляет model override.

Минимальная конфигурация:

```php
'codex' => [
    'model' => 'gpt-5.6-sol',
],
'limits' => [
    // 'primary_remaining_warning_percent' => 5,
    // 'secondary_remaining_warning_percent' => 1,
],
```

### Локальные MCP tools

Core подключает к каждому `codex exec` локальный STDIO MCP-сервер `bin/mcp-server.php`.
Сервер публикует tools:

```text
send_document(path: string, caption?: string)
send_image(path: string, caption?: string)
set_response_delivery(mode: "text"|"voice", scope: "once"|"persistent")
```

- пользователь не имеет доступа к локальной файловой системе компьютера агента;
- локальный путь или Markdown-ссылка на локальный файл не передаёт файл пользователю и не может заменять вызов tool;
- `send_document` обязателен для передачи пользователю любого локального файла, кроме изображений JPEG, PNG и WEBP;
- `send_image` обязателен для передачи пользователю локальных JPEG, PNG и WEBP;
- `set_response_delivery` переключает текстовый и голосовой режим ответа: `scope=once` действует только для текущего финального ответа, `scope=persistent` — для текущего и последующих ответов;
- server-level `instructions` описывает только общие условия локальной файловой системы и не упоминает отдельные tools;
- описание каждого tool описывает только его собственное назначение.
- `path` — абсолютный путь к читаемому regular file.
- `caption` — необязательная подпись.
- runtime-session берётся только из `RUNTIME_SID`; tool не принимает адресата.
- файл загружается в `file_exchange.base_url` с Bearer-токеном `file_exchange.token`.
- после загрузки Core отправляет в Router `kind=document`, пустой или равный `caption` текст и ровно одно attachment.
- attachment содержит `file_id`, `url`, `name`, `mime` и `size_bytes` из фактического файла/ответа файлообменника.
- любая ошибка проверки, загрузки или Router delivery завершает tool ошибкой.
- `send_image` принимает только JPEG, PNG и WEBP размером не больше 10 МБ.
- после загрузки `send_image` отправляет в Router `kind=image` с тем же форматом attachment.

### MCP tools транспорта

Перед чтением MCP-запросов STDIO-сервер прогревает список tools транспорта
для текущего `RUNTIME_SID`. Ключ кэша — точное значение `RUNTIME_SID`: Router
гарантирует отдельный runtime session для каждого binding/opaque. Кэш общий для
последовательных MCP-процессов. TTL задаётся `transport_tools.cache_ttl_seconds`,
по умолчанию 600 секунд. Пустой корректный список также кэшируется.

При cache miss один MCP-процесс захватывает session lock, повторно проверяет кэш,
заранее регистрирует ожидание и отправляет через Router:

```json
{"runtime_session_id":"...","kind":"tool","text":"","attachments":[],"meta":{"type":"list","request_id":"..."}}
```

Остальные MCP-процессы этой session не создают дублирующий `list`, а ждут
освобождения lock и читают заполненный кэш. Таймаут ответа на `list` задаётся
`transport_tools.list_timeout_seconds`, по умолчанию 5 секунд. Таймаут, невалидный
ответ или коллизия с локальным tool журналируются, не кэшируются и дают
только локальные tools.

`meta.tools` — массив MCP `Tool`. Каждый tool обязан иметь непустые строковые
`name`, строковый `description` и object `inputSchema`; остальные MCP-поля сохраняются
непрозрачно и без переименования. `tools/list` возвращает сначала локальные, затем
транспортные tools. Любая коллизия имени отклоняет весь транспортный список.

Вызов известного транспортного tool отправляет:

```json
{"runtime_session_id":"...","kind":"tool","text":"","attachments":[],"meta":{"type":"call","request_id":"...","name":"tool_name","arguments":{}}}
```

Таймаут ответа задаётся `transport_tools.call_timeout_seconds`, по умолчанию 50 секунд.
`meta.result` — полный MCP `CallToolResult` без обёртки. `meta.error` со строковыми
`code`, `message` и необязательным `data` возвращается как `CallToolResult`: `isError=true`,
а полный JSON объекта error помещается в текстовый `content`. Неизвестное имя
отклоняется локально и не отправляется Router.

Входящий `kind=tool` никогда не становится manager event и не запускает turn. Core
сопоставляет ответ по `runtime_session_id + request_id`. Неизвестные, чужие, поздние,
дублированные и некорректные ответы журналируются, отбрасываются и не блокируют
ingress cursor. Протухшие ожидания удаляются.

Core не задаёт Codex `startup_timeout_sec` и `tool_timeout_sec`. Codex запускает MCP-сервер
без `enabled_tools`, с `default_tools_approval_mode="approve"` и без per-tool approval settings.

Для `ManagerWorker` это значит следующее:

- `user_message`, `scheduled_prompt` и `background_result` во время `codex->run(...)` стримят промежуточные чанки как outbound `commentary`
- после завершения каждого такого turn финальный текст уходит отдельным outbound `final`
- для `background_result` отличается только prompt-builder и текст fallback-ответа при пустом результате
- после успешного распознавания каждого входящего `voice` объединённый текст распознавания сначала уходит отдельным outbound `transcript`, а затем тот же текст вместе с непустой подписью передаётся в `codex` как пользовательское сообщение
- transport сам решает, как представить `transcript`; исходное голосовое сообщение повторно не прикладывается

## Входящие вложения

Если входящее событие `user_message` содержит непустой `meta.attachments`, `Core` должен скачать вложения локально и передать их в `codex` как часть пользовательского prompt.

Формат такой:

```text
Вот файл(ы):
- <информация о вложении 1>
- <информация о вложении 2>

<исходный текст пользователя>
```

Каждое не-voice вложение обязано содержать канонический URL `https://files.ioannidis.ru/<file_id>`.
Core скачивает его до запуска `codex` через `https://files.ioannidis.ru/<file_id>?download=1` с HTTP-заголовком `Referer: https://files.ioannidis.ru/` и сохраняет в `<storage.root>/attachments/<runtime_session_id>/`.
Имя каталога обязано точно совпадать с `runtime_session_id`, полученным от Router. Идентификаторы с символами вне `[A-Za-z0-9._-]` отклоняются.
Имя файла строится из `file_id` и безопасного варианта `attachment.name`, чтобы одноимённые файлы разных вложений не перезаписывали друг друга.
В prompt первым полем вложения передаётся `local_path` к читаемой локальной копии. Исходный `url` при наличии `local_path` в prompt не передаётся, чтобы Codex не воспринимал локальный путь и ссылку как два разных файла. Поля `type`, `name`, `mime`, `size_bytes`, `source`, `expires_at` сохраняются как дополнительная информация.
Локальная копия не удаляется после turn и остаётся доступной последующим сообщениям того же диалога.
Невалидный URL или ошибка скачивания явно завершают обработку события ошибкой; fallback на исходный URL запрещён.

Если `meta.attachments` пустой, prompt должен остаться обычным пользовательским текстом без служебного блока.
Если `meta.attachments` непустой, входящее событие считается валидным даже при пустом `text`.

## Транскрибация аудио

Транскрибация — ответственность `Core`, а не transport-слоя.

Для входящего `user_message` транскрибируются только вложения с точным `type=voice`.
Вложения с `type=audio` и любым другим типом не передаются в transcriber и продолжают обрабатываться общим attachment prompt.

Каждое voice-вложение обязано содержать канонический URL `https://files.ioannidis.ru/<file_id>`.
Core скачивает файл только через `https://files.ioannidis.ru/<file_id>?download=1` с HTTP-заголовком `Referer: https://files.ioannidis.ru/`, сохраняет его во временный файл с расширением из `attachment.name`, передаёт локальный путь в `AudioTranscriberInterface` и удаляет временный файл после успешной транскрибации или ошибки.
Невалидный URL, ошибка скачивания или ошибка транскрибации явно завершают обработку события ошибкой; fallback на исходный URL или игнорирование voice-вложения запрещены.
Перед переводом такого события в `failed` Core отправляет в его runtime-session текст: `Не удалось расшифровать голосовое сообщение. Проверьте transcription.api_key и повторите отправку.`
Текст внутренней ошибки и API key пользователю не передаются.

Транскрипции нескольких voice-вложений объединяются в исходном порядке через пустую строку.
Если исходный `text`/caption непустой, он добавляется после транскрипций через пустую строку без потери содержимого.
Voice-вложения после транскрибации не включаются в общий attachment prompt; остальные вложения форматируются существующим `AttachmentPromptFormatter` перед полученным текстом.

`AudioTranscriberInterface::transcribe(string $file_path): string` принимает путь к локальному читаемому аудиофайлу и возвращает непустую строку распознанного текста.
Нечитаемый файл, ошибка API, невалидный ответ или пустая транскрипция должны явно завершаться ошибкой.

Реализация `GptAudioTranscriber` вызывает `POST /v1/audio/transcriptions` как `multipart/form-data` с полями `file` и `model`.
Настройки берутся из персонального конфига однопользовательского `Core`:

```php
'transcription' => [
    'api_key' => '',
    'model' => 'gpt-transcribe',
],
```

Ключ не передаётся в `Router`, transport или runtime-события.

## Границы проекта

- краткое описание проекта: [PROJECT.md](./PROJECT.md)
