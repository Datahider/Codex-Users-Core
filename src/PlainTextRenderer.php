<?php

declare(strict_types=1);

namespace CodexRuntime;

use CodexRuntime\Contracts\TextRendererInterface;

final class PlainTextRenderer implements TextRendererInterface
{
    public function renderCommentary(string $text): string
    {
        return trim($text);
    }

    public function renderFinal(string $text): string
    {
        return trim($text);
    }
}
