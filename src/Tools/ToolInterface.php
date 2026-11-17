<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema for Anthropic tool input_schema.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    public function isMutating(): bool;

    /**
     * @param array<string, mixed> $input
     */
    public function execute(array $input): ToolResult;
}
