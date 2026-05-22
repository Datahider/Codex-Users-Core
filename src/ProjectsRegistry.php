<?php

declare(strict_types=1);

namespace CodexRuntime;

use RuntimeException;

final class ProjectsRegistry
{
    public function __construct(private Config $config)
    {
    }

    public function assertProjectExists(string $projectRoot): string
    {
        $projectRoot = trim($projectRoot);
        if ($projectRoot === '') {
            throw new RuntimeException('Job project is required');
        }

        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new RuntimeException("No such project: {$projectRoot} (directory does not exist)");
        }

        $projectFile = $resolvedRoot . '/PROJECT.md';
        if (!is_file($projectFile)) {
            throw new RuntimeException("No such project: {$resolvedRoot} (missing PROJECT.md)");
        }

        return $resolvedRoot;
    }

    public function touch(string $projectRoot, array $context = []): void
    {
        $resolvedRoot = $this->assertProjectExists($projectRoot);
        $statePath = $this->statePath($resolvedRoot);
        $state = [];
        if (is_file($statePath)) {
            $raw = file_get_contents($statePath);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        $state['project_root'] = $resolvedRoot;
        $state['project_file'] = $resolvedRoot . '/PROJECT.md';
        $state['last_touched_at'] = date(DATE_ATOM);
        if (isset($context['job_id'])) {
            $state['last_touched_job_id'] = (string) $context['job_id'];
        }
        if (isset($context['source'])) {
            $state['last_touched_source'] = (string) $context['source'];
        }
        if (isset($context['status'])) {
            $state['last_touched_status'] = (string) $context['status'];
        }

        file_put_contents(
            $statePath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }

    public function listProjects(string $root): array
    {
        $root = trim($root);
        if ($root === '') {
            return [];
        }

        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            return [];
        }

        $projects = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolvedRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'PROJECT.md') {
                continue;
            }

            $projectRoot = $file->getPath();
            $projectMeta = $this->readProjectMeta($file->getPathname());
            $statePath = $this->statePath($projectRoot);
            $state = null;
            if (is_file($statePath)) {
                $raw = file_get_contents($statePath);
                $decoded = $raw === false ? null : json_decode($raw, true);
                if (is_array($decoded)) {
                    $state = $decoded;
                }
            }

            $projects[] = [
                'project_root' => $projectRoot,
                'project_file' => $file->getPathname(),
                'state_file' => $statePath,
                'name' => (string) ($projectMeta['name'] ?? basename($projectRoot)),
                'status' => (string) ($projectMeta['status'] ?? 'unknown'),
                'blocked' => is_array($state) ? (bool) ($state['blocked'] ?? false) : false,
                'blocked_reason' => is_array($state) ? (string) ($state['blocked_reason'] ?? '') : '',
                'last_touched_at' => is_array($state) ? (string) ($state['last_touched_at'] ?? 'never touched') : 'never touched',
            ];
        }

        usort($projects, static function (array $left, array $right): int {
            $leftBlocked = !empty($left['blocked']);
            $rightBlocked = !empty($right['blocked']);
            if ($leftBlocked !== $rightBlocked) {
                return $leftBlocked ? 1 : -1;
            }

            $leftStatusRank = self::statusRank((string) ($left['status'] ?? 'unknown'));
            $rightStatusRank = self::statusRank((string) ($right['status'] ?? 'unknown'));

            if ($leftStatusRank !== $rightStatusRank) {
                return $leftStatusRank <=> $rightStatusRank;
            }

            $leftTouchedAt = (string) ($left['last_touched_at'] ?? 'never touched');
            $rightTouchedAt = (string) ($right['last_touched_at'] ?? 'never touched');

            if ($leftTouchedAt === 'never touched' && $rightTouchedAt !== 'never touched') {
                return -1;
            }

            if ($leftTouchedAt !== 'never touched' && $rightTouchedAt === 'never touched') {
                return 1;
            }

            if ($leftTouchedAt !== $rightTouchedAt) {
                return strcmp($leftTouchedAt, $rightTouchedAt);
            }

            return strcmp((string) $left['project_root'], (string) $right['project_root']);
        });

        return $projects;
    }

    public function recommendedProject(string $root): ?array
    {
        $projects = $this->listProjects($root);
        if ($projects === []) {
            return null;
        }

        foreach ($projects as $project) {
            if ((string) ($project['status'] ?? '') === 'active' && empty($project['blocked'])) {
                return $project;
            }
        }

        foreach ($projects as $project) {
            if (empty($project['blocked'])) {
                return $project;
            }
        }

        return null;
    }

    private function readProjectMeta(string $projectFile): array
    {
        $raw = file_get_contents($projectFile);
        if ($raw === false) {
            return [];
        }

        $name = $this->extractMarkdownSection($raw, 'Name');
        $status = mb_strtolower($this->extractMarkdownSection($raw, 'Status'));

        return array_filter([
            'name' => $name,
            'status' => $status,
        ], static fn (string $value): bool => $value !== '');
    }

    private function extractMarkdownSection(string $markdown, string $heading): string
    {
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$\R+(.+?)(?=\R##\s+|\z)/ms';
        if (!preg_match($pattern, $markdown, $matches)) {
            return '';
        }

        return trim($matches[1]);
    }

    private static function statusRank(string $status): int
    {
        return match ($status) {
            'active' => 0,
            'planned' => 1,
            'maintenance' => 2,
            default => 3,
        };
    }

    private function statePath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/project-state.json';
    }
}
