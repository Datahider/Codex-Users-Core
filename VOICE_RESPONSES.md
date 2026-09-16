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
],
```

Changing the response mode is independent of transcript visibility for incoming
voice messages.
