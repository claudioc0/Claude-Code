<?php

declare(strict_types=1);

namespace ClaudeCode\Storage;

use ClaudeCode\Core\Config;
use PDO;

final class Database
{
    private PDO $pdo;
    private string $driver;

    public function __construct(PDO $pdo, string $driver = 'mysql')
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function fromConfig(Config $config): self
    {
        $driver = strtolower($config->get('DB_CONNECTION', 'mysql'));

        if ($driver === 'sqlite') {
            $path = $config->get('DB_DATABASE', ':memory:');
            $pdo = new PDO("sqlite:{$path}");
            $db = new self($pdo, 'sqlite');
            $db->initSqliteSchema();
            return $db;
        }

        $host = $config->get('DB_HOST', '127.0.0.1');
        $port = $config->get('DB_PORT', '3306');
        $database = $config->get('DB_DATABASE', 'claude_code');
        $username = $config->get('DB_USERNAME', 'root');
        $password = $config->get('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return new self($pdo, 'mysql');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Initializes compatible tables when using SQLite (e.g. for tests or local fallback).
     */
    public function initSqliteSchema(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                workspace_root TEXT NOT NULL,
                model TEXT NOT NULL,
                permission_mode TEXT NOT NULL DEFAULT 'ask',
                title TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                role TEXT NOT NULL,
                content_json TEXT NOT NULL,
                input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS tool_calls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                tool_use_id TEXT NOT NULL,
                tool_name TEXT NOT NULL,
                input_json TEXT NOT NULL,
                output_text TEXT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                duration_ms INTEGER NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                message_id INTEGER NULL,
                file_path TEXT NOT NULL,
                previous_content TEXT NULL,
                file_existed INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        SQL);
    }
}
