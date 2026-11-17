<?php

declare(strict_types=1);

namespace ClaudeCode\Core;

final class Config
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function fromEnv(string $workspaceRoot): self
    {
        $values = [];
        $envFile = rtrim($workspaceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                    $pos = strpos($trimmed, '=');
                    if ($pos === false) {
                        continue;
                    }
                    $key = trim(substr($trimmed, 0, $pos));
                    $val = trim(substr($trimmed, $pos + 1));
                    $val = self::stripQuotes($val);
                    $values[$key] = $val;
                }
            }
        }

        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $values[$k] = $v;
            }
        }

        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        $env = getenv($key);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return $default;
    }

    public function getInt(string $key, int $default): int
    {
        $val = $this->get($key);
        return is_numeric($val) ? (int) $val : $default;
    }

    private static function stripQuotes(string $value): string
    {
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
