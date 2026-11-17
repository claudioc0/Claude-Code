<?php

declare(strict_types=1);

namespace ClaudeCode\Tools;

final class ToolResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $isError = false,
    ) {
    }
}
