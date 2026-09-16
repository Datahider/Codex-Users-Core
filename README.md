# Codex Runtime Core

`codex-runtime` — это ядро рантайма для Codex-ботов, работающее через `Router`.

## Что делает репозиторий

- забирает входящие события из `Router`
- ставит runtime-задачи в очереди и обрабатывает их
- запускает `codex`
- хранит состояние рантайма на локальном диске
- поднимает и удерживает фоновые воркеры

## Зона ответственности

Ядро отвечает за:

- чтение входящих событий из `Router`
- обработку `manager-queue`
- запуск `codex`
- связь `runtime_session_id -> codex_session_id`
- команды управления вроде `/stop`, `/reset`, `/session`
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

В конфиге нужно задать:

- `router.base_url`
- `router.core_token`
- `transcription.api_key`
- `transcription.model`
- `file_exchange.base_url`
- `file_exchange.token`
- `codex.cwd`, если `codex` должен запускаться из другого каталога

`storage.root` менять не обязательно. По умолчанию он равен:

```text
~/.codex-users-core/var
```

Шаблон конфига лежит в [config/config.example.php](./config/config.example.php).

При старте `bin/run-core.php` сам:

- проверяет наличие и читаемость конфига
- валидирует `router.base_url`, `router.core_token`, `transcription.api_key` и `transcription.model`
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

Как именно они рендерятся и доставляются, решает внешний transport-слой.

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

Минимальная конфигурация:

```php
'limits' => [
    // 'primary_remaining_warning_percent' => 5,
    // 'secondary_remaining_warning_percent' => 1,
],
```

### MCP tools исходящих файлов

Core подключает к каждому `codex exec` локальный STDIO MCP-сервер `bin/mcp-server.php`.
Сервер публикует tools:

```text
send_document(path: string, caption?: string)
send_image(path: string, caption?: string)
```

- `path` — абсолютный путь к читаемому regular file.
- `caption` — необязательная подпись.
- runtime-session берётся только из `RUNTIME_SID`; tool не принимает адресата.
- файл загружается в `file_exchange.base_url` с Bearer-токеном `file_exchange.token`.
- после загрузки Core отправляет в Router `kind=document`, пустой или равный `caption` текст и ровно одно attachment.
- attachment содержит `file_id`, `url`, `name`, `mime` и `size_bytes` из фактического файла/ответа файлообменника.
- любая ошибка проверки, загрузки или Router delivery завершает tool ошибкой.
- `send_image` принимает только JPEG, PNG и WEBP размером не больше 10 МБ.
- после загрузки `send_image` отправляет в Router `kind=image` с тем же форматом attachment.

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
В prompt первым полем вложения передаётся `local_path` к читаемой локальной копии. Поля `url`, `type`, `name`, `mime`, `size_bytes`, `source`, `expires_at` сохраняются как дополнительная информация.
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
