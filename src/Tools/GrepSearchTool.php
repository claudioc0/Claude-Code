<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use ClaudeCode\Permissions\PermissionManager;
use Throwable;

final class GrepSearchTool implements ToolInterface
{
    private const MAX_MATCHES = 100;

    public function __construct(private readonly PermissionManager $permissions)
    {
    }

    public function getName(): string
    {
        return 'grep_search';
    }

    public function getDescription(): string
    {
        return 'Busca por texto literal ou expressão regular nos arquivos do workspace (respeitando .gitignore) e retorna arquivo, linha e trecho.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Texto ou expressão regular a buscar.',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Arquivo ou subdiretório opcional relativo ao workspace.',
                ],
                'is_regex' => [
                    'type' => 'boolean',
                    'description' => 'Se true, interpreta query como regex PCRE. Padrão: false.',
                ],
                'case_sensitive' => [
                    'type' => 'boolean',
                    'description' => 'Se true, diferencia maiúsculas de minúsculas. Padrão: false.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function execute(array $input): ToolResult
    {
        $query = (string) ($input['query'] ?? '');
        if ($query === '') {
            return new ToolResult('Parâmetro obrigatório ausente: query', true);
        }

        $isRegex = (bool) ($input['is_regex'] ?? false);
        $caseSensitive = (bool) ($input['case_sensitive'] ?? false);

        $pattern = $this->buildPattern($query, $isRegex, $caseSensitive);
        if (@preg_match($pattern, '') === false) {
            return new ToolResult("Expressão regular inválida: {$query}", true);
        }

        $root = $this->permissions->getWorkspaceRoot();
        $target = $root;

        if (isset($input['path']) && trim((string) $input['path']) !== '') {
            try {
                $target = $this->permissions->resolveWorkspacePath((string) $input['path']);
            } catch (Throwable $e) {
                return new ToolResult($e->getMessage(), true);
            }
        }

        if (!file_exists($target)) {
            return new ToolResult("Caminho não encontrado: {$target}", true);
        }

        $rootNormalized = str_replace('\\', '/', rtrim($root, '/\\'));
        if (is_file($target)) {
            $full = str_replace('\\', '/', $target);
            $rel = ltrim(substr($full, strlen($rootNormalized)), '/');
            $files = [$rel];
        } else {
            $matcher = new GitIgnoreMatcher($root);
            $files = $matcher->listFiles($target);
        }

        $results = [];
        $totalMatches = 0;

        foreach ($files as $relPath) {
            $fullPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if ($this->isBinaryFile($fullPath)) {
                continue;
            }

            $lines = file($fullPath, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $idx => $lineText) {
                if (preg_match($pattern, $lineText) === 1) {
                    $totalMatches++;
                    if (count($results) < self::MAX_MATCHES) {
                        $lineNum = $idx + 1;
                        $snippet = strlen($lineText) > 200 ? substr($lineText, 0, 200) . '...' : $lineText;
                        $results[] = "{$relPath}:{$lineNum}: {$snippet}";
                    }
                }
            }
        }

        if ($totalMatches === 0) {
            return new ToolResult("Nenhuma ocorrência encontrada para: {$query}");
        }

        $header = "Encontradas {$totalMatches} ocorrência(s):";
        $output = $header . "\n" . implode("\n", $results);
        if ($totalMatches > self::MAX_MATCHES) {
            $omitted = $totalMatches - self::MAX_MATCHES;
            $output .= "\n... ({$omitted} ocorrências omitidas; refine a busca)";
        }

        return new ToolResult($output);
    }

    private function buildPattern(string $query, bool $isRegex, bool $caseSensitive): string
    {
        $body = $isRegex ? str_replace('#', '\#', $query) : preg_quote($query, '#');
        $flags = $caseSensitive ? 'u' : 'iu';

        return "#{$body}#{$flags}";
    }

    private function isBinaryFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }
        $sample = fread($handle, 1024);
        fclose($handle);

        return $sample === false || str_contains($sample, "\0");
    }
}
