<?php

declare(strict_types=1);

namespace CodexRuntime\Telegram;

use CodexRuntime\Contracts\TextRendererInterface;
use FastVolt\Helper\Markdown;

final class TelegramTextRenderer implements TextRendererInterface
{
    public function renderFinal(string $markdown): string
    {
        return $this->markdownToMaxHtml($markdown);
    }

    public function renderCommentary(string $markdown): string
    {
        return '<i>' . $this->markdownToMaxHtml($markdown) . '</i>';
    }

    private function markdownToMaxHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $this->loadBrokenFastVoltDependencies();

        if (!class_exists(Markdown::class)) {
            return htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $instance = Markdown::new()->setContent($markdown);
        $html = method_exists($instance, 'getHtml')
            ? $instance->getHtml()
            : $instance->toHtml();

        return trim($this->replaceUnsupported((string) $html));
    }

    private function loadBrokenFastVoltDependencies(): void
    {
        if (class_exists(\FastVolt\Helper\Libs\Markdown\Process\ParseMarkdown::class)) {
            return;
        }

        $path = dirname(__DIR__, 2) . '/vendor/fastvolt/markdown/src/libs/Markdown/Process/ParseMarkdown.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    private function replaceUnsupported(string $html): string
    {
        $html = $this->processOrderedLists($html);

        $html = str_replace('<p>', "\n", $html);
        $html = str_replace('</p>', '', $html);
        $html = str_replace('<ul>', '', $html);
        $html = str_replace('</ul>', '', $html);
        $html = (string) preg_replace('/<ol\b[^>]*>/i', '', $html);
        $html = str_replace('</ol>', '', $html);
        $html = (string) preg_replace('/(?<!\n)<li\b[^>]*>/i', "\n<li>", $html);
        $html = (string) preg_replace('/<li\b[^>]*>/i', '• ', $html);
        $html = str_replace('</li>', '', $html);
        $html = str_replace('<p>', "\n", $html);
        $html = str_replace('</p>', '', $html);

        $html = str_replace('<br>', '', $html);
        $html = str_replace('<br />', '', $html);
        $html = str_replace('<hr>', '──────────', $html);
        $html = str_replace('<hr />', '──────────', $html);

        $html = (string) preg_replace('/<blockquote>\s*/', '<blockquote expandable>', $html);
        $html = (string) preg_replace_callback(
            '/<h1>(.*?)<\/h1>/i',
            static fn (array $m): string => '<b>' . mb_strtoupper($m[1]) . '</b>',
            $html
        );
        $html = (string) preg_replace_callback(
            '/<h2>(.*?)<\/h2>/i',
            static fn (array $m): string => '<b>' . mb_strtoupper($m[1]) . '</b>',
            $html
        );
        $html = (string) preg_replace('/<h3>(.*?)<\/h3>/i', '<b>$1</b>', $html);
        $html = (string) preg_replace('/<h4>(.*?)<\/h4>/i', '<b>$1</b>', $html);
        $html = (string) preg_replace('/<h5>(.*?)<\/h5>/i', '<u>$1</u>', $html);
        $html = (string) preg_replace('/<h6>(.*?)<\/h6>/i', '<u>$1</u>', $html);

        return ltrim($html);
    }

    private function processOrderedLists(string $html): string
    {
        return (string) preg_replace_callback('/<ol\b([^>]*)>(.*?)<\/ol>/is', static function (array $matches): string {
            $attributes = $matches[1] ?? '';
            $index = 1;

            if (preg_match('/\bstart\s*=\s*["\']?(\d+)["\']?/i', $attributes, $startMatches) === 1) {
                $index = max(1, (int) $startMatches[1]);
            }

            return (string) preg_replace_callback('/<li\b[^>]*>(.*?)<\/li>/is', static function (array $itemMatches) use (&$index): string {
                $line = $index . '. ' . trim($itemMatches[1]);
                $index++;

                return $line . "\n";
            }, $matches[2]);
        }, $html);
    }
}
