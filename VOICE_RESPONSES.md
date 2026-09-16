# Voice responses contract

## Scope

Voice response mode applies only to outbound `kind=final` produced by a Codex turn.
Outbound `kind=commentary` is always delivered as text and never enters voice
classification or speech synthesis.

The contract is transport-independent inside Core. Core decides whether a final
response is text or voice and emits a semantic outbound event. A transport only
renders explicitly supported outbound kinds.

## Per-session response mode

Core stores response mode separately for every `runtime_session_id`.

Supported modes:

- `text` — finals are delivered as text;
- `voice` — eligible finals are delivered as voice.

Supported scopes:

- `once` — overrides the persistent mode for the current turn's final only;
- `persistent` — becomes the session mode for the current and following turns.

The local Core MCP server exposes:

```text
set_response_delivery(mode: "text"|"voice", scope: "once"|"persistent")
```

The tool derives the session exclusively from `RUNTIME_SID`. It cannot accept a
recipient or session identifier from the model. Invalid values fail explicitly.

A `once` override is consumed when the corresponding final is planned, including
when that final is forced to text. Commentary does not consume it. Without stored
state the effective mode is `text`.

## Final planning pipeline

For effective `text` mode Core sends the original final as `kind=final` without
calling the classifier or speech synthesizer.

For effective `voice` mode Core performs these steps in order:

1. Reject a final containing a fenced Markdown code block.
2. Reject a final whose Unicode character count is greater than
   `voice_response.max_characters`.
3. Submit the complete final to the configured Luna classifier.
4. If Luna accepts it, synthesize the unchanged final and emit `kind=voice`.
5. If any check rejects it, emit `kind=final` containing the unchanged original
   response with a short textual explanation prepended.

The default `voice_response.max_characters` is `700`. A response of exactly 700
characters is eligible; 701 characters are not. The configured value must be a
positive integer.

The fixed hard-rejection explanations are:

```text
Отправляю текстом: ответ содержит блок кода.

Отправляю текстом: ответ слишком длинный для голосового сообщения.
```

The original final follows the explanation after one blank line.

Hard rejection must not call Luna or TTS. Luna is asked only whether the already
generated response remains understandable when heard without seeing its Markdown.
It returns a strict decision consisting of `voice: boolean` and a non-empty
Russian `reason` when `voice=false`. Luna must not rewrite, shorten, summarize or
synthesize the response.

Luna rejection is rendered as:

```text
Отправляю текстом: <reason>.

<original final>
```

Classifier failure, invalid classifier output, TTS failure, file upload failure
and voice outbound failure are operation errors. They are not silently converted
to a text response.

## Voice outbound

An accepted response is synthesized from the original final without Markdown
rewriting. Core uploads the generated audio through file exchange and emits:

- `kind=voice`;
- empty `text`;
- exactly one attachment with `file_id`, `url`, `name`, `mime` and `size_bytes`;
- `mime=audio/ogg`.

The temporary synthesized file is removed after upload succeeds or fails.
Telegram transport downloads the attachment through file exchange and sends it
with Telegram `sendVoice`. It removes its downloaded temporary file after success
or failure.

## Configuration

```php
'voice_response' => [
    'max_characters' => 700,
    'classifier_model' => 'gpt-5.6-luna',
    'default_voice' => 'cedar',
    'allowed_voices' => [
        'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'onyx',
        'nova', 'sage', 'shimmer', 'verse', 'marin', 'cedar',
    ],
],
'speech' => [
    'model' => 'gpt-4o-mini-tts',
],
```

Speech generation uses the same OpenAI API key as `transcription.api_key`.

`default_voice` must occur in the non-empty `allowed_voices` list. Unknown and
disallowed voice names fail explicitly. Voice names are compared exactly after
lowercasing and trimming.

## User voice selection and preview

The selected voice is a Core-wide user preference because one Core instance has
one owner. It applies across runtime sessions and survives process restarts. The
default comes from `voice_response.default_voice` until the user selects another
voice.

Core handles these transport commands without forwarding them to Codex:

- `/voices` — emits one `kind=voice` sample for every allowed voice. Each sample
  has the Markdown inline-code caption `` `/voice name` `` so a transport can
  render it as a copyable complete command;
- `/voice` — returns the currently selected voice;
- `/voice <name>` — validates and persists the voice, then returns
  `Выбран голос: <name>.` as `kind=system` without emitting another sample.

Every catalog voice has a short lively greeting which includes its voice name.
Samples are synthesized lazily and stored in a persistent cache by voice name.
Later `/voices` calls reuse the cached OGG file and do not invoke TTS again. A
missing, unknown or disallowed voice never changes the stored preference and
never invokes TTS.

Regular accepted voice finals and voice samples use the selected voice. The
catalog is not divided into gender or other subjective categories.

Changing the response mode is independent of transcript visibility for incoming
voice messages.
