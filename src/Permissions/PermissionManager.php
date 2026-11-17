<?php

declare(strict_types=1);

namespace ClaudeCode\Permissions;

use InvalidArgumentException;

final class PermissionManager
{
    public const MODE_ASK = 'ask';
    public const MODE_AUTO_EDIT = 'auto-edit';
    public const MODE_PLAN = 'plan';

    private string $workspaceRoot;

    public function __construct(
        string $workspaceRoot,
        private string $mode = self::MODE_ASK,
    ) {
        $real = realpath($workspaceRoot);
        $this->workspaceRoot = $real !== false ? $real : rtrim($workspaceRoot, '/\\');
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_ASK, self::MODE_AUTO_EDIT, self::MODE_PLAN], true)) {
            throw new InvalidArgumentException("Modo de permissão inválido: {$mode}");
        }
        $this->mode = $mode;
    }

    public function getWorkspaceRoot(): string
    {
        return $this->workspaceRoot;
    }

    /**
     * Resolves a path relative to the workspace root and ensures it does not escape it.
     */
    public function resolveWorkspacePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Caminho de arquivo vazio.');
        }

        $isAbsolute = $this->isAbsolutePath($trimmed);
        $candidate = $isAbsolute
            ? $trimmed
            : $this->workspaceRoot . DIRECTORY_SEPARATOR . $trimmed;

        $normalized = $this->normalizePath($candidate);
        $normalizedRoot = $this->normalizePath($this->workspaceRoot);

        $rootPrefix = rtrim($normalizedRoot, '/\\') . DIRECTORY_SEPARATOR;
        if (
            strcasecmp($normalized, $normalizedRoot) !== 0
            && !str_starts_with(strtolower($normalized), strtolower($rootPrefix))
        ) {
            throw new InvalidArgumentException("Acesso negado fora do workspace: {$path}");
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $input
     * @param callable(string, array<string, mixed>): bool $confirmCallback
     */
    public function authorize(
        string $toolName,
        array $input,
        bool $isMutating,
        callable $confirmCallback,
    ): bool {
        if (!$isMutating) {
            return true;
        }

        if ($this->mode === self::MODE_PLAN) {
            return false;
        }

        if ($this->mode === self::MODE_AUTO_EDIT && in_array($toolName, ['write_file', 'edit_file'], true)) {
            return true;
        }

        return $confirmCallback($toolName, $input);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $prefix = '';

        if (preg_match('/^([A-Za-z]:)(.*)$/', $path, $matches)) {
            $prefix = strtoupper($matches[1]);
            $path = $matches[2];
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = substr($path, 1);
        }

        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), static fn (string $p): bool => $p !== '' && $p !== '.');
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                if ($stack === []) {
                    throw new InvalidArgumentException('Path traversal detectado.');
                }
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }

        if ($prefix !== '' && !str_ends_with($prefix, DIRECTORY_SEPARATOR)) {
            return $prefix . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $stack);
        }

        return $prefix . implode(DIRECTORY_SEPARATOR, $stack);
    }
}
