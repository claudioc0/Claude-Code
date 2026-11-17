<?php

declare(strict_types=1);

namespace ClaudeCode\Ui;

final class DiffRenderer
{
    private const ANSI_RED = "\033[31m";
    private const ANSI_GREEN = "\033[32m";
    private const ANSI_CYAN = "\033[36m";
    private const ANSI_RESET = "\033[0m";

    public function __construct(private readonly bool $useColors = true)
    {
    }

    public function renderEditDiff(string $path, string $oldString, string $newString): string
    {
        $lines = [];
        $lines[] = $this->color("--- a/{$path}", self::ANSI_CYAN);
        $lines[] = $this->color("+++ b/{$path}", self::ANSI_CYAN);

        foreach ($this->splitLines($oldString) as $line) {
            $lines[] = $this->color("- {$line}", self::ANSI_RED);
        }
        foreach ($this->splitLines($newString) as $line) {
            $lines[] = $this->color("+ {$line}", self::ANSI_GREEN);
        }

        return implode("\n", $lines);
    }

    public function renderWritePreview(string $path, string $content, int $maxLines = 30): string
    {
        $lines = [$this->color("+++ {$path}", self::ANSI_CYAN)];
        $contentLines = $this->splitLines($content);
        $slice = array_slice($contentLines, 0, $maxLines);

        foreach ($slice as $line) {
            $lines[] = $this->color("+ {$line}", self::ANSI_GREEN);
        }

        $remaining = count($contentLines) - count($slice);
        if ($remaining > 0) {
            $lines[] = $this->color("... (+{$remaining} linhas omitidas)", self::ANSI_CYAN);
        }

        return implode("\n", $lines);
    }

    /**
     * @return string[]
     */
    private function splitLines(string $text): array
    {
        if ($text === '') {
            return [''];
        }

        return preg_split("/\r?\n/", $text) ?: [$text];
    }

    private function color(string $text, string $ansi): string
    {
        if (!$this->useColors) {
            return $text;
        }

        return $ansi . $text . self::ANSI_RESET;
    }
}
