<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class GitIgnoreMatcher
{
    /** @var array<int, array{pattern: string, dirOnly: bool, negated: bool, regex: string}> */
    private array $rules = [];

    public function __construct(private readonly string $workspaceRoot)
    {
        $this->loadRules();
    }

    /**
     * Lists non-ignored file paths relative to workspaceRoot (using forward slashes).
     *
     * @return string[]
     */
    public function listFiles(string $baseDir = ''): array
    {
        $startDir = $baseDir !== '' ? $baseDir : $this->workspaceRoot;
        if (!is_dir($startDir)) {
            return [];
        }

        $rootNormalized = str_replace('\\', '/', rtrim($this->workspaceRoot, '/\\'));

        $dirIterator = new RecursiveDirectoryIterator(
            $startDir,
            RecursiveDirectoryIterator::SKIP_DOTS
        );

        $filterIterator = new RecursiveCallbackFilterIterator(
            $dirIterator,
            function (SplFileInfo $current) use ($rootNormalized): bool {
                $full = str_replace('\\', '/', $current->getPathname());
                $rel = ltrim(substr($full, strlen($rootNormalized)), '/');
                if ($rel === '') {
                    return true;
                }
                return !$this->isIgnored($rel, $current->isDir());
            }
        );

        $iterator = new RecursiveIteratorIterator($filterIterator, RecursiveIteratorIterator::LEAVES_ONLY);
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($full, strlen($rootNormalized)), '/');
            $files[] = $rel;
        }

        sort($files);
        return $files;
    }

    public function isIgnored(string $relativePath, bool $isDir = false): bool
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($path === '') {
            return false;
        }

        $segments = explode('/', $path);
        if (in_array('.git', $segments, true)) {
            return true;
        }

        $ignored = false;
        foreach ($this->rules as $rule) {
            if ($rule['dirOnly'] && !$isDir) {
                // Also match if any parent directory segment matches a dirOnly rule
                if (!$this->matchesDirectoryPrefix($path, $rule['regex'])) {
                    continue;
                }
                $ignored = !$rule['negated'];
                continue;
            }

            if (preg_match($rule['regex'], $path) === 1) {
                $ignored = !$rule['negated'];
            }
        }

        return $ignored;
    }

    private function matchesDirectoryPrefix(string $path, string $regex): bool
    {
        $parts = explode('/', $path);
        array_pop($parts);
        $prefix = '';
        foreach ($parts as $part) {
            $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
            if (preg_match($regex, $prefix) === 1) {
                return true;
            }
        }

        return false;
    }

    private function loadRules(): void
    {
        $gitignore = rtrim($this->workspaceRoot, '/\\') . DIRECTORY_SEPARATOR . '.gitignore';
        if (!is_file($gitignore) || !is_readable($gitignore)) {
            return;
        }

        $lines = file($gitignore, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $negated = false;
            if (str_starts_with($trimmed, '!')) {
                $negated = true;
                $trimmed = substr($trimmed, 1);
            }

            $dirOnly = str_ends_with($trimmed, '/');
            $pattern = trim($trimmed, '/');
            if ($pattern === '') {
                continue;
            }

            $this->rules[] = [
                'pattern' => $pattern,
                'dirOnly' => $dirOnly,
                'negated' => $negated,
                'regex' => $this->compilePattern($pattern),
            ];
        }
    }

    private function compilePattern(string $pattern): string
    {
        $hasSlash = str_contains($pattern, '/');
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace(['\*\*/', '\*\*', '\*', '\?'], ['(?:.+/)?', '.*', '[^/]*', '[^/]'], $escaped);

        if ($hasSlash) {
            return '#^' . $escaped . '(?:/.*)?$#';
        }

        return '#^(?:.+/)?' . $escaped . '(?:/.*)?$#';
    }
}
