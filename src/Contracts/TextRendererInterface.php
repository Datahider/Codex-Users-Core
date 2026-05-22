<?php

declare(strict_types=1);

namespace CodexRuntime\Contracts;

interface TextRendererInterface
{
    public function renderCommentary(string $text): string;

    public function renderFinal(string $text): string;
}
