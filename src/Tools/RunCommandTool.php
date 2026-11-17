<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

use ClaudeCode\Permissions\PermissionManager;

final class RunCommandTool implements ToolInterface
{
    private const MAX_OUTPUT_CHARS = 12000;

    public function __construct(
        private readonly PermissionManager $permissions,
        private readonly int $defaultTimeout = 30,
    ) {
    }

    public function getName(): string
    {
        return 'run_command';
    }

    public function getDescription(): string
    {
        return 'Executa um comando no shell local dentro do diretório raiz do workspace e retorna exit code, stdout e stderr.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Comando shell a ser executado.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout em segundos (padrão: 30).',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function execute(array $input): ToolResult
    {
        $command = trim((string) ($input['command'] ?? ''));
        if ($command === '') {
            return new ToolResult('Comando vazio.', true);
        }

        $timeout = max(1, (int) ($input['timeout'] ?? $this->defaultTimeout));
        $cwd = $this->permissions->getWorkspaceRoot();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return new ToolResult("Falha ao iniciar processo para: {$command}", true);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) >= $timeout) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(20_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($timedOut) {
            return new ToolResult(
                "Comando excedeu o timeout de {$timeout}s.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
                true
            );
        }

        $parts = ["Exit code: {$exitCode}"];
        if (trim($stdout) !== '') {
            $parts[] = "stdout:\n" . $this->truncate($stdout);
        }
        if (trim($stderr) !== '') {
            $parts[] = "stderr:\n" . $this->truncate($stderr);
        }

        return new ToolResult(implode("\n\n", $parts), $exitCode !== 0);
    }

    private function truncate(string $text): string
    {
        if (strlen($text) <= self::MAX_OUTPUT_CHARS) {
            return rtrim($text);
        }

        $omitted = strlen($text) - self::MAX_OUTPUT_CHARS;
        return rtrim(substr($text, 0, self::MAX_OUTPUT_CHARS)) . "\n... (output truncado: {$omitted} caracteres omitidos)";
    }
}
