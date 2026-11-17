<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use ClaudeCode\Permissions\PermissionManager;
use Throwable;

final class WriteFileTool implements ToolInterface
{
    public function __construct(private readonly PermissionManager $permissions)
    {
    }

    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Cria um novo arquivo ou sobrescreve um arquivo existente no workspace com o conteúdo fornecido.';
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
                'content' => [
                    'type' => 'string',
                    'description' => 'Conteúdo completo a ser gravado no arquivo.',
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function execute(array $input): ToolResult
    {
        try {
            $path = $this->permissions->resolveWorkspacePath((string) ($input['path'] ?? ''));
        } catch (Throwable $e) {
            return new ToolResult($e->getMessage(), true);
        }

        if (!array_key_exists('content', $input)) {
            return new ToolResult('Parâmetro obrigatório ausente: content', true);
        }

        $content = (string) $input['content'];
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return new ToolResult("Falha ao criar diretório pai: {$dir}", true);
        }

        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            return new ToolResult("Falha ao gravar arquivo: {$path}", true);
        }

        $lines = $content === '' ? 0 : substr_count($content, "\n") + 1;

        return new ToolResult("Arquivo gravado com sucesso: {$path} ({$bytes} bytes, {$lines} linhas).");
    }
}
