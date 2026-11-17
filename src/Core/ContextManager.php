<?php

declare(strict_types=1);

namespace ClaudeCode\Core;

use ClaudeCode\Storage\SessionRepository;

final class ContextManager
{
    /** @var array<int, array<string, mixed>> */
    private array $messages = [];
    private int $totalInputTokens = 0;
    private int $totalOutputTokens = 0;

    public function __construct(
        private readonly string $workspaceRoot,
        private readonly ?SessionRepository $repository = null,
        private ?string $sessionId = null,
    ) {
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function buildSystemPrompt(): string
    {
        $os = PHP_OS_FAMILY;
        $base = <<<PROMPT
Você é um agente de programação autônomo no terminal (clone experimental do Claude Code).
Workspace atual: {$this->workspaceRoot}
Sistema operacional: {$os}

Diretrizes:
- Use as ferramentas disponíveis para inspecionar arquivos antes de modificá-los.
- Seja conciso, objetivo e preciso em suas respostas.
- Nunca invente conteúdos de arquivos ou resultados de comandos que você não executou.
PROMPT;

        $claudeMd = rtrim($this->workspaceRoot, '/\\') . DIRECTORY_SEPARATOR . 'CLAUDE.md';
        if (is_file($claudeMd) && is_readable($claudeMd)) {
            $instructions = trim((string) file_get_contents($claudeMd));
            if ($instructions !== '') {
                $base .= "\n\n## Instruções do Projeto (CLAUDE.md)\n" . $instructions;
            }
        }

        return $base;
    }

    /**
     * @param array<int, array<string, mixed>>|string $content
     */
    public function addUserMessage(array|string $content): void
    {
        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        if ($this->repository !== null && $this->sessionId !== null) {
            $this->repository->addMessage($this->sessionId, 'user', $content);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $contentBlocks
     */
    public function addAssistantMessage(array $contentBlocks, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $this->messages[] = [
            'role' => 'assistant',
            'content' => $contentBlocks,
        ];

        $this->totalInputTokens += $inputTokens;
        $this->totalOutputTokens += $outputTokens;

        if ($this->repository !== null && $this->sessionId !== null) {
            $this->repository->addMessage($this->sessionId, 'assistant', $contentBlocks, $inputTokens, $outputTokens);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getTotalInputTokens(): int
    {
        return $this->totalInputTokens;
    }

    public function getTotalOutputTokens(): int
    {
        return $this->totalOutputTokens;
    }

    public function clear(): void
    {
        $this->messages = [];
        $this->totalInputTokens = 0;
        $this->totalOutputTokens = 0;
    }
}
