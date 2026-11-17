<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Core;

use ClaudeCode\Core\AgentLoop;
use ClaudeCode\Core\Config;
use ClaudeCode\Core\ContextManager;
use ClaudeCode\Permissions\PermissionManager;
use ClaudeCode\Providers\LlmProviderInterface;
use ClaudeCode\Providers\LlmResponse;
use ClaudeCode\Providers\ToolCall;
use ClaudeCode\Storage\Database;
use ClaudeCode\Storage\SessionRepository;
use ClaudeCode\Tools\ToolRegistry;
use ClaudeCode\Tools\WriteFileTool;
use PHPUnit\Framework\TestCase;

final class AgentLoopTest extends TestCase
{
    public function testExecutesToolCallAndPersistsHistory(): void
    {
        $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_loop_' . uniqid();
        mkdir($workspace, 0777, true);

        try {
            $db = Database::fromConfig(new Config(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:']));
            $repo = new SessionRepository($db);
            $sessionId = $repo->createSession($workspace, 'mock-model');

            $permissions = new PermissionManager($workspace, PermissionManager::MODE_ASK);
            $tools = new ToolRegistry([new WriteFileTool($permissions)]);
            $context = new ContextManager($workspace, $repo, $sessionId);

            $provider = new class implements LlmProviderInterface {
                private int $step = 0;

                public function streamMessage(
                    string $systemPrompt,
                    array $messages,
                    array $tools = [],
                    ?callable $onTextDelta = null,
                ): LlmResponse {
                    $this->step++;
                    if ($this->step === 1) {
                        return new LlmResponse(
                            text: '',
                            toolCalls: [new ToolCall('toolu_1', 'write_file', ['path' => 'out.txt', 'content' => 'hello'])],
                            contentBlocks: [
                                [
                                    'type' => 'tool_use',
                                    'id' => 'toolu_1',
                                    'name' => 'write_file',
                                    'input' => ['path' => 'out.txt', 'content' => 'hello'],
                                ],
                            ],
                            stopReason: 'tool_use',
                            inputTokens: 50,
                            outputTokens: 20,
                        );
                    }

                    if ($onTextDelta !== null) {
                        $onTextDelta('Arquivo criado!');
                    }

                    return new LlmResponse(
                        text: 'Arquivo criado!',
                        toolCalls: [],
                        contentBlocks: [['type' => 'text', 'text' => 'Arquivo criado!']],
                        stopReason: 'end_turn',
                        inputTokens: 80,
                        outputTokens: 10,
                    );
                }

                public function getModel(): string
                {
                    return 'mock-model';
                }
            };

            $agent = new AgentLoop($provider, $tools, $permissions, $context, $repo);
            $streamed = '';
            $response = $agent->runTurn(
                'Crie out.txt',
                function (string $delta) use (&$streamed): void {
                    $streamed .= $delta;
                },
                fn (): bool => true,
            );

            $this->assertSame('Arquivo criado!', $streamed);
            $this->assertSame('Arquivo criado!', $response->text);
            $this->assertFileExists($workspace . DIRECTORY_SEPARATOR . 'out.txt');
            $this->assertSame('hello', file_get_contents($workspace . DIRECTORY_SEPARATOR . 'out.txt'));
            $this->assertCount(4, $repo->getMessages($sessionId));
            $this->assertCount(1, $repo->getToolCalls($sessionId));
        } finally {
            @unlink($workspace . DIRECTORY_SEPARATOR . 'out.txt');
            @rmdir($workspace);
        }
    }
}
