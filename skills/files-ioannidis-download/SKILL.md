---
name: files-ioannidis-download
description: Use this skill when a task involves a file hosted on files.ioannidis.ru. It defines the required download URL shape, the mandatory Referer header, and a safe local save path so Codex can fetch the file correctly without re-deriving the workflow.
---

# Files Ioannidis Download

## Overview

This skill is for downloading files from `files.ioannidis.ru` when the user provides a file URL from that host or when such a URL appears inside message attachments.

## When To Use

Use this skill when any of these are true:

- the user gives a `https://files.ioannidis.ru/...` link
- an attachment payload contains a `files.ioannidis.ru` URL
- a message says to open, inspect, parse, summarize, or verify a file hosted on `files.ioannidis.ru`

## Download Rule

Do not fetch the visible page URL directly.

Always:

1. Start from the attachment URL, for example `https://files.ioannidis.ru/GPVn`
2. Append `?download=1`
3. Send header `Referer: https://files.ioannidis.ru/`

Working example:

```bash
curl -L -e 'https://files.ioannidis.ru/' 'https://files.ioannidis.ru/GPVn?download=1'
```

Observed behavior:

- without `Referer`, the server may return HTML instead of the file
- with `Referer` and `download=1`, the server returns the real file body

## Save Path

Save the downloaded file where it is actually useful for the task.

Examples:

- if the file belongs to a project, save it inside that project
- if the file is an input artifact for code or content work, save it near the files being edited

Prefer preserving the remote filename when it is known from headers or context.

If the user is asking about the file contents, the agent may download it into a temporary working location chosen at its discretion for analysis.

If the user is not asking about the file contents and there is no clear destination path from the task context, do not download the file yet.

In that case, first ask the user what the file is for and where it should live.

## Practical Notes

- Treat the original `https://files.ioannidis.ru/<id>` URL as a landing page, not as the final download URL.
- If the file content is needed for analysis, download it first and then work from the local copy.
- If only metadata is needed, a `HEAD` request with the same `Referer` rule is acceptable.

## Minimal Workflow

1. Detect `files.ioannidis.ru` URL
2. Convert it to `?download=1`
3. Add `Referer: https://files.ioannidis.ru/`
4. Download into the appropriate task-specific location, or into a temporary working location if the user is asking about file contents
5. Continue the task using the local file
