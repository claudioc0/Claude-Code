<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Storage;

use ClaudeCode\Core\Config;
use ClaudeCode\Storage\Database;
use ClaudeCode\Storage\SessionRepository;
use PHPUnit\Framework\TestCase;

final class SessionRepositoryTest extends TestCase
{
    public function testPersistsSessionMessagesToolCallsAndSettings(): void
    {
        $config = new Config([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ]);
        $db = Database::fromConfig($config);
        $repo = new SessionRepository($db);

        $sessionId = $repo->createSession('/tmp/workspace', 'claude-sonnet-4-20250514', 'ask', 'Teste');
        $session = $repo->getSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('/tmp/workspace', $session['workspace_root']);

        $repo->addMessage($sessionId, 'user', 'Olá');
        $repo->addMessage($sessionId, 'assistant', [['type' => 'text', 'text' => 'Oi!']], 10, 5);

        $messages = $repo->getMessages($sessionId);
        $this->assertCount(2, $messages);
        $this->assertSame('Olá', $messages[0]['content']);
        $this->assertSame(10, (int) $messages[1]['input_tokens']);

        $repo->logToolCall($sessionId, 'toolu_1', 'read_file', ['path' => 'README.md'], 'executed', 'ok', 12);
        $calls = $repo->getToolCalls($sessionId);
        $this->assertCount(1, $calls);
        $this->assertSame('read_file', $calls[0]['tool_name']);

        $repo->setSetting('default_model', 'claude-sonnet-4-20250514');
        $this->assertSame('claude-sonnet-4-20250514', $repo->getSetting('default_model'));
    }
}
