<?php

declare(strict_types=1);

namespace ClaudeCode\Storage;

use PDO;

final class SessionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createSession(
        string $workspaceRoot,
        string $model,
        string $permissionMode = 'ask',
        ?string $title = null
    ): string {
        $id = $this->generateUuidV4();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO sessions (id, workspace_root, model, permission_mode, title) VALUES (:id, :workspace_root, :model, :permission_mode, :title)'
        );
        $stmt->execute([
            ':id' => $id,
            ':workspace_root' => $workspaceRoot,
            ':model' => $model,
            ':permission_mode' => $permissionMode,
            ':title' => $title,
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed>|string $content
     */
    public function addMessage(
        string $sessionId,
        string $role,
        array|string $content,
        int $inputTokens = 0,
        int $outputTokens = 0
    ): int {
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO messages (session_id, role, content_json, input_tokens, output_tokens) VALUES (:session_id, :role, :content_json, :input_tokens, :output_tokens)'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':role' => $role,
            ':content_json' => $json,
            ':input_tokens' => $inputTokens,
            ':output_tokens' => $outputTokens,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, role, content_json, input_tokens, output_tokens, created_at FROM messages WHERE session_id = :session_id ORDER BY id ASC'
        );
        $stmt->execute([':session_id' => $sessionId]);

        $messages = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['content'] = json_decode((string) $row['content_json'], true, 512, JSON_THROW_ON_ERROR);
            $messages[] = $row;
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function logToolCall(
        string $sessionId,
        string $toolUseId,
        string $toolName,
        array $input,
        string $status,
        ?string $outputText = null,
        ?int $durationMs = null
    ): int {
        $inputJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tool_calls (session_id, tool_use_id, tool_name, input_json, output_text, status, duration_ms) VALUES (:session_id, :tool_use_id, :tool_name, :input_json, :output_text, :status, :duration_ms)'
        );
        $stmt->execute([
            ':session_id' => $sessionId,
            ':tool_use_id' => $toolUseId,
            ':tool_name' => $toolName,
            ':input_json' => $inputJson,
            ':output_text' => $outputText,
            ':status' => $status,
            ':duration_ms' => $durationMs,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getToolCalls(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tool_calls WHERE session_id = :session_id ORDER BY id ASC'
        );
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->fetchAll();
    }

    public function setSetting(string $key, string $value): void
    {
        if ($this->db->driver() === 'sqlite') {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
        }

        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();

        return $row === false ? $default : (string) $row['setting_value'];
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
