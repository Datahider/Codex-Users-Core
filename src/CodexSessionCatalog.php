<?php

declare(strict_types=1);

namespace CodexRuntime;

use PDO;
use RuntimeException;

final class CodexSessionCatalog
{
    /**
     * @return list<array{id: string, title: string, cwd: string, updated_at: int, archived: int}>
     */
    public function listForHomeDirectory(?string $homeDirectory = null): array
    {
        $homeDirectory = rtrim(trim((string) ($homeDirectory ?: getenv('HOME') ?: '/home/web')), '/');
        if ($homeDirectory === '') {
            $homeDirectory = '/home/web';
        }

        $dbPath = $this->resolveStateDbPath($homeDirectory);
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            'SELECT id, title, cwd, updated_at, archived
             FROM threads
             WHERE cwd = :cwd AND archived = 0 AND source = :source
             ORDER BY updated_at ASC'
        );
        $stmt->execute([
            'cwd' => $homeDirectory,
            'source' => 'local',
        ]);

        $rows = $stmt->fetchAll();
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(static function (array $row): array {
            return [
                'id' => trim((string) ($row['id'] ?? '')),
                'title' => trim((string) ($row['title'] ?? '')),
                'cwd' => trim((string) ($row['cwd'] ?? '')),
                'updated_at' => (int) ($row['updated_at'] ?? 0),
                'archived' => (int) ($row['archived'] ?? 0),
            ];
        }, array_filter($rows, static fn (array $row): bool => trim((string) ($row['id'] ?? '')) !== '')));
    }

    private function resolveStateDbPath(string $homeDirectory): string
    {
        $candidates = [];
        $codexHome = trim((string) getenv('CODEX_HOME'));
        if ($codexHome !== '') {
            $candidates[] = $codexHome . '/state_5.sqlite';
            $candidates[] = $codexHome . '/state.sqlite';
        }

        $candidates[] = $homeDirectory . '/.codex/state_5.sqlite';
        $candidates[] = $homeDirectory . '/.codex/state.sqlite';
        $candidates[] = $homeDirectory . '/snap/codex/current/state_5.sqlite';
        $candidates[] = $homeDirectory . '/snap/codex/current/state.sqlite';

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $glob = glob($homeDirectory . '/snap/codex/*/state_*.sqlite');
        if (is_array($glob) && $glob !== []) {
            rsort($glob, SORT_STRING);
            foreach ($glob as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException('Codex session database not found');
    }
}
