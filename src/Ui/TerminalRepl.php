<?php

declare(strict_types=1);

namespace ClaudeCode\Ui;

use ClaudeCode\Core\AgentLoop;
use ClaudeCode\Core\ContextManager;
use ClaudeCode\Permissions\PermissionManager;
use ClaudeCode\Providers\LlmProviderInterface;
use ClaudeCode\Tools\ToolResult;
use Throwable;

final class TerminalRepl
{
    private DiffRenderer $diffRenderer;

    public function __construct(
        private readonly AgentLoop $agent,
        private readonly ContextManager $context,
        private readonly PermissionManager $permissions,
        private readonly LlmProviderInterface $provider,
        private readonly string $storageStatus,
        ?DiffRenderer $diffRenderer = null,
    ) {
        $this->diffRenderer = $diffRenderer ?? new DiffRenderer();
    }

    public function run(): int
    {
        $this->printBanner();

        while (true) {
            echo "\n> ";
            $line = fgets(STDIN);
            if ($line === false) {
                echo "\nEncerrando.\n";
                break;
            }

            $input = trim($line);
            if ($input === '') {
                continue;
            }

            if (in_array(strtolower($input), ['exit', 'quit', '/exit', '/quit'], true)) {
                echo "Até logo!\n";
                break;
            }

            if (str_starts_with($input, '/')) {
                $this->handleSlashCommand($input);
                continue;
            }

            $this->executePrompt($input);
        }

        return 0;
    }

    public function runHeadless(string $prompt): int
    {
        return $this->executePrompt($prompt) ? 0 : 1;
    }

    private function executePrompt(string $prompt): bool
    {
        $printedText = false;

        try {
            $this->agent->runTurn(
                $prompt,
                function (string $delta) use (&$printedText): void {
                    if (!$printedText) {
                        echo "\n";
                        $printedText = true;
                    }
                    echo $delta;
                    flush();
                },
                function (string $toolName, array $input): bool {
                    return $this->askConfirmation($toolName, $input);
                },
                function (string $toolName, array $input, ?ToolResult $result): void {
                    if ($result === null) {
                        $summary = $this->formatToolSummary($toolName, $input);
                        echo "\n[tool] {$toolName} {$summary}\n";
                    } elseif ($result->isError) {
                        echo "[erro] {$result->output}\n";
                    }
                },
            );

            if ($printedText) {
                echo "\n";
            }

            return true;
        } catch (Throwable $e) {
            fwrite(STDERR, "\n[Erro] {$e->getMessage()}\n");
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function askConfirmation(string $toolName, array $input): bool
    {
        if ($toolName === 'edit_file') {
            echo "\n" . $this->diffRenderer->renderEditDiff(
                (string) ($input['path'] ?? ''),
                (string) ($input['old_string'] ?? ''),
                (string) ($input['new_string'] ?? ''),
            ) . "\n";
        } elseif ($toolName === 'write_file') {
            echo "\n" . $this->diffRenderer->renderWritePreview(
                (string) ($input['path'] ?? ''),
                (string) ($input['content'] ?? ''),
            ) . "\n";
        }

        $summary = $this->formatToolSummary($toolName, $input);
        echo "\nPermitir {$toolName} {$summary}? [s/N]: ";
        $answer = fgets(STDIN);
        if ($answer === false) {
            return false;
        }

        $normalized = strtolower(trim($answer));
        return in_array($normalized, ['s', 'sim', 'y', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function formatToolSummary(string $toolName, array $input): string
    {
        return match ($toolName) {
            'read_file', 'write_file', 'edit_file' => '(' . (string) ($input['path'] ?? '') . ')',
            'glob' => '(' . (string) ($input['pattern'] ?? '') . ')',
            'grep_search' => '(' . (string) ($input['query'] ?? '') . ')',
            'run_command' => '(`' . (string) ($input['command'] ?? '') . '`)',
            default => json_encode($input, JSON_UNESCAPED_UNICODE) ?: '',
        };
    }

    private function handleSlashCommand(string $command): void
    {
        $parts = preg_split('/\s+/', $command, 2) ?: [$command];
        $cmd = strtolower($parts[0]);
        $arg = trim($parts[1] ?? '');

        switch ($cmd) {
            case '/help':
                echo "Comandos disponíveis:\n";
                echo "  /help              Exibe esta ajuda\n";
                echo "  /clear             Limpa o histórico da conversa atual\n";
                echo "  /mode <modo>       Altera modo (ask | auto-edit | plan)\n";
                echo "  /tokens            Exibe uso acumulado de tokens na sessão\n";
                echo "  exit | quit        Sai do REPL\n";
                break;

            case '/clear':
                $this->context->clear();
                echo "Histórico da conversa limpo.\n";
                break;

            case '/mode':
                if ($arg === '') {
                    echo "Modo atual: {$this->permissions->getMode()}\n";
                    break;
                }
                try {
                    $this->permissions->setMode($arg);
                    echo "Modo alterado para: {$arg}\n";
                } catch (Throwable $e) {
                    echo "{$e->getMessage()}\n";
                }
                break;

            case '/tokens':
                echo sprintf(
                    "Tokens na sessão — input: %d | output: %d\n",
                    $this->context->getTotalInputTokens(),
                    $this->context->getTotalOutputTokens(),
                );
                break;

            default:
                echo "Comando desconhecido: {$cmd}. Use /help para listar os comandos.\n";
        }
    }

    private function printBanner(): void
    {
        echo "========================================\n";
        echo " Claude Code (Clone Experimental PHP)\n";
        echo "========================================\n";
        echo "Workspace : {$this->permissions->getWorkspaceRoot()}\n";
        echo "Modelo    : {$this->provider->getModel()}\n";
        echo "Permissão : {$this->permissions->getMode()}\n";
        echo "Storage   : {$this->storageStatus}\n";
        echo "Digite /help para comandos ou exit para sair.\n";
    }
}
