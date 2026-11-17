<?php

declare(strict_types=1);

namespace ClaudeCode\Providers;

interface LlmProviderInterface
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param callable(string): void|null $onTextDelta
     */
    public function streamMessage(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        ?callable $onTextDelta = null,
    ): LlmResponse;

    public function getModel(): string;
}
