<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use ClaudeCode\Permissions\PermissionManager;
use Throwable;

final class EditFileTool implements ToolInterface
{
    public function __construct(private readonly PermissionManager $permissions)
    {
    }

    public function getName(): string
    {
        return 'edit_file';
    }

    public function getDescription(): string
    {
        return 'Edita um arquivo substituindo exatamente uma ocorrência única de old_string por new_string.';
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
                'old_string' => [
                    'type' => 'string',
                    'description' => 'Trecho exato a ser substituído (deve aparecer exatamente uma vez no arquivo).',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'Novo trecho que substituirá old_string.',
                ],
            ],
            'required' => ['path', 'old_string', 'new_string'],
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

        if (!is_file($path) || !is_readable($path)) {
            return new ToolResult("Arquivo não encontrado ou sem permissão de leitura: {$path}", true);
        }

        $oldString = (string) ($input['old_string'] ?? '');
        if ($oldString === '') {
            return new ToolResult('Parâmetro old_string não pode ser vazio.', true);
        }

        if (!array_key_exists('new_string', $input)) {
            return new ToolResult('Parâmetro obrigatório ausente: new_string', true);
        }
        $newString = (string) $input['new_string'];

        $content = file_get_contents($path);
        if ($content === false) {
            return new ToolResult("Falha ao ler arquivo: {$path}", true);
        }

        $count = substr_count($content, $oldString);
        if ($count === 0) {
            return new ToolResult("old_string não encontrado em {$path}.", true);
        }
        if ($count > 1) {
            return new ToolResult(
                "old_string encontrado {$count} vezes em {$path}. Forneça mais contexto para garantir ocorrência única.",
                true
            );
        }

        $updated = str_replace($oldString, $newString, $content);
        if (file_put_contents($path, $updated) === false) {
            return new ToolResult("Falha ao gravar arquivo: {$path}", true);
        }

        return new ToolResult("Arquivo editado com sucesso: {$path}");
    }
}
