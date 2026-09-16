<?php

declare(strict_types=1);

namespace CodexRuntime\Voice;

use CodexRuntime\Config;
use RuntimeException;

final class CodexLunaVoiceSuitabilityClassifier implements VoiceSuitabilityClassifierInterface
{
    public function __construct(private Config $config, private string $tmp_dir)
    {
    }

    public function classify(string $text): array
    {
        if (!is_dir($this->tmp_dir) && !mkdir($this->tmp_dir, 0775, true) && !is_dir($this->tmp_dir)) {
            throw new RuntimeException('Cannot create voice classifier temporary directory');
        }
        $schema_path = rtrim($this->tmp_dir, '/') . '/voice-classifier-schema-' . bin2hex(random_bytes(6)) . '.json';
        $output_path = rtrim($this->tmp_dir, '/') . '/voice-classifier-output-' . bin2hex(random_bytes(6)) . '.json';
        $schema = [
            'type' => 'object',
            'properties' => [
                'voice' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['voice', 'reason'],
            'additionalProperties' => false,
        ];
        file_put_contents($schema_path, json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        $command = [
            (string) $this->config->require('codex', 'bin'),
            'exec',
            '--skip-git-repo-check',
            '--ephemeral',
            '--ignore-user-config',
            '--sandbox',
            'read-only',
            '--model',
            (string) $this->config->get('voice_response', 'classifier_model', 'gpt-5.6-luna'),
            '--output-schema',
            $schema_path,
            '-o',
            $output_path,
            '-',
        ];
        $prompt = "Оцени, сохранит ли ответ смысл при прослушивании без экрана. "
            . "Простой линейный список допустим. Сложные таблицы, вложенные структуры, множество точных ссылок, путей или команд требуют текста. "
            . "Не переписывай ответ. Верни voice=true и пустой reason либо voice=false и краткую причину на русском.\n\nОтвет:\n{$text}";
        $descriptor_spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        try {
            $process = proc_open($command, $descriptor_spec, $pipes, (string) $this->config->require('codex', 'cwd'));
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start voice classifier');
            }
            fwrite($pipes[0], $prompt);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit_code = proc_close($process);
            if ($exit_code !== 0) {
                throw new RuntimeException('Voice classifier failed: ' . trim((string) $stderr . "\n" . (string) $stdout));
            }
            if (!is_file($output_path)) {
                throw new RuntimeException('Voice classifier did not produce output');
            }
            $decision = json_decode((string) file_get_contents($output_path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decision)) {
                throw new RuntimeException('Invalid voice classifier output');
            }

            return $decision;
        } finally {
            if (is_file($schema_path)) {
                unlink($schema_path);
            }
            if (is_file($output_path)) {
                unlink($output_path);
            }
        }
    }
}
