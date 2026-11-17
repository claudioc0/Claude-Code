<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use ClaudeCode\Permissions\PermissionManager;
use Throwable;

final class ReadFileTool implements ToolInterface
{
    private const DEFAULT_LINE_LIMIT = 200;

    public function __construct(private readonly PermissionManager $permissions)
    {
    }

    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Lê o conteúdo de um arquivo do workspace com numeração de linhas e paginação por intervalo (start_line, end_line).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Caminho do arquivo relativo ao workspace (ou absoluto dentro dele).',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'Linha inicial (1-indexed). Padrão: 1.',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'Linha final (inclusiva). Padrão: start_line + 199.',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function execute(array $input): ToolResult
    {
        try {
            $path = $this->permissions->resolveWorkspacePath((string) ($input['path'] ?? ''));
        } catch (Throwable $e) {
            return new ToolResult($e->getMessage(), true);
        }

        if (!is_file($path) || !is_readable($path)) {
            return new ToolResult("Arquivo não encontrado ou sem permissão de leitura: {$path}", true);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return new ToolResult("Falha ao ler arquivo: {$path}", true);
        }

        $totalLines = count($lines);
        if ($totalLines === 0) {
            return new ToolResult('(arquivo vazio, 0 linhas)');
        }

        $startLine = max(1, (int) ($input['start_line'] ?? 1));
        $endLine = isset($input['end_line'])
            ? min($totalLines, max($startLine, (int) $input['end_line']))
            : min($totalLines, $startLine + self::DEFAULT_LINE_LIMIT - 1);

        if ($startLine > $totalLines) {
            return new ToolResult("start_line ({$startLine}) excede o total de linhas ({$totalLines}).", true);
        }

        $output = ["Exibindo linhas {$startLine}-{$endLine} de {$totalLines}:"];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $output[] = sprintf('%d: %s', $i, $lines[$i - 1]);
        }

        if ($endLine < $totalLines) {
            $remaining = $totalLines - $endLine;
            $output[] = "... ({$remaining} linhas restantes)";
        }

        return new ToolResult(implode("\n", $output));
    }
}
