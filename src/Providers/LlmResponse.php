<?php

declare(strict_types=1);

namespace ClaudeCode\Providers;

final class LlmResponse
{
    /**
     * @param ToolCall[] $toolCalls
     * @param array<int, array<string, mixed>> $contentBlocks
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $contentBlocks,
        public readonly string $stopReason = 'end_turn',
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {
    }

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
