<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use ClaudeCode\Permissions\PermissionManager;
use Throwable;

final class GlobTool implements ToolInterface
{
    private const MAX_RESULTS = 200;

    public function __construct(private readonly PermissionManager $permissions)
    {
    }

    public function getName(): string
    {
        return 'glob';
    }

    public function getDescription(): string
    {
        return 'Lista arquivos do workspace que correspondem a um padrão glob (ex: "**/*.php", "src/*.php"), respeitando .gitignore.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Padrão glob (ex: "*.php", "src/**/*.php", "tests/*Test.php").',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Subdiretório opcional relativo ao workspace para restringir a busca.',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function execute(array $input): ToolResult
    {
        $pattern = trim((string) ($input['pattern'] ?? ''));
        if ($pattern === '') {
            return new ToolResult('Parâmetro obrigatório ausente: pattern', true);
        }

        $root = $this->permissions->getWorkspaceRoot();
        $searchDir = $root;

        if (isset($input['path']) && trim((string) $input['path']) !== '') {
            try {
                $searchDir = $this->permissions->resolveWorkspacePath((string) $input['path']);
            } catch (Throwable $e) {
                return new ToolResult($e->getMessage(), true);
            }
        }

        if (!is_dir($searchDir)) {
            return new ToolResult("Diretório não encontrado: {$searchDir}", true);
        }

        $matcher = new GitIgnoreMatcher($root);
        $files = $matcher->listFiles($searchDir);
        $regex = $this->globToRegex(ltrim(str_replace('\\', '/', $pattern), '/'));

        $matches = [];
        foreach ($files as $relPath) {
            if (preg_match($regex, $relPath) === 1) {
                $matches[] = $relPath;
            }
        }

        if ($matches === []) {
            return new ToolResult("Nenhum arquivo encontrado para o padrão: {$pattern}");
        }

        $total = count($matches);
        $truncated = array_slice($matches, 0, self::MAX_RESULTS);
        $output = "Encontrados {$total} arquivo(s):\n" . implode("\n", $truncated);

        if ($total > self::MAX_RESULTS) {
            $omitted = $total - self::MAX_RESULTS;
            $output .= "\n... ({$omitted} arquivos omitidos; refine o padrão glob)";
        }

        return new ToolResult($output);
    }

    private function globToRegex(string $pattern): string
    {
        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace(
            ['\*\*/', '\*\*', '\*', '\?'],
            ['(?:.+/)?', '.*', '[^/]*', '[^/]'],
            $escaped
        );

        // If pattern has no slash (e.g. "*.php"), match anywhere or at root
        if (!str_contains($pattern, '/')) {
            return '#^(?:.+/)?' . $escaped . '$#i';
        }

        return '#^' . $escaped . '$#i';
    }
}
