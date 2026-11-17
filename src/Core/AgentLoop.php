<?php

declare(strict_types=1);

namespace ClaudeCode\Core;

use ClaudeCode\Permissions\PermissionManager;
use ClaudeCode\Providers\LlmProviderInterface;
use ClaudeCode\Providers\LlmResponse;
use ClaudeCode\Storage\SessionRepository;
use ClaudeCode\Tools\ToolRegistry;
use ClaudeCode\Tools\ToolResult;

final class AgentLoop
{
    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly ToolRegistry $tools,
        private readonly PermissionManager $permissions,
        private readonly ContextManager $context,
        private readonly ?SessionRepository $repository = null,
        private readonly int $maxIterations = 25,
    ) {
    }

    /**
     * @param callable(string): void $onTextDelta
     * @param callable(string, array<string, mixed>): bool $confirmCallback
     * @param callable(string, array<string, mixed>, ToolResult|null): void|null $onToolEvent
     */
    public function runTurn(
        string $userInput,
        callable $onTextDelta,
        callable $confirmCallback,
        ?callable $onToolEvent = null,
    ): LlmResponse {
        $this->context->addUserMessage($userInput);
        $systemPrompt = $this->context->buildSystemPrompt();
        $toolSchemas = $this->tools->getSchemas();

        $lastResponse = new LlmResponse('', [], []);

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $lastResponse = $this->provider->streamMessage(
                $systemPrompt,
                $this->context->getMessages(),
                $toolSchemas,
                $onTextDelta,
            );

            $blocks = $lastResponse->contentBlocks;
            if ($blocks === [] && $lastResponse->text !== '') {
                $blocks = [['type' => 'text', 'text' => $lastResponse->text]];
            }

            $this->context->addAssistantMessage(
                $blocks,
                $lastResponse->inputTokens,
                $lastResponse->outputTokens,
            );

            if (!$lastResponse->hasToolCalls()) {
                return $lastResponse;
            }

            $toolResults = [];
            foreach ($lastResponse->toolCalls as $call) {
                if ($onToolEvent !== null) {
                    $onToolEvent($call->name, $call->input, null);
                }

                $tool = $this->tools->get($call->name);
                if ($tool === null) {
                    $result = new ToolResult("Ferramenta desconhecida: {$call->name}", true);
                    $this->logTool($call->id, $call->name, $call->input, 'error', $result->output, 0);
                } else {
                    $approved = $this->permissions->authorize(
                        $tool->getName(),
                        $call->input,
                        $tool->isMutating(),
                        $confirmCallback,
                    );

                    if (!$approved) {
                        $reason = $this->permissions->getMode() === PermissionManager::MODE_PLAN
                            ? 'Ação bloqueada: o agente está em modo somente leitura (plan).'
                            : 'Ação recusada pelo usuário.';
                        $result = new ToolResult($reason, true);
                        $this->logTool($call->id, $call->name, $call->input, 'denied', $result->output, 0);
                    } else {
                        $start = hrtime(true);
                        $result = $tool->execute($call->input);
                        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
                        $status = $result->isError ? 'error' : 'executed';
                        $this->logTool($call->id, $call->name, $call->input, $status, $result->output, $durationMs);
                    }
                }

                if ($onToolEvent !== null) {
                    $onToolEvent($call->name, $call->input, $result);
                }

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $call->id,
                    'content' => $result->output,
                    'is_error' => $result->isError,
                ];
            }

            $this->context->addUserMessage($toolResults);
        }

        return $lastResponse;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function logTool(
        string $toolUseId,
        string $toolName,
        array $input,
        string $status,
        string $output,
        int $durationMs,
    ): void {
        $sessionId = $this->context->getSessionId();
        if ($this->repository === null || $sessionId === null) {
            return;
        }

        $this->repository->logToolCall($sessionId, $toolUseId, $toolName, $input, $status, $output, $durationMs);
    }
}
