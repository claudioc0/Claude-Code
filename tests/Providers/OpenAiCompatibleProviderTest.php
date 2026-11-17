<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Providers;

use ClaudeCode\Core\Config;
use ClaudeCode\Providers\AnthropicProvider;
use ClaudeCode\Providers\OpenAiCompatibleProvider;
use ClaudeCode\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleProviderTest extends TestCase
{
    public function testParsesSseTextAndToolCalls(): void
    {
        $sse = <<<SSE
data: {"id":"1","choices":[{"index":0,"delta":{"role":"assistant","content":"Vou "},"finish_reason":null}]}

data: {"id":"1","choices":[{"index":0,"delta":{"content":"verificar."},"finish_reason":null}]}

data: {"id":"1","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"call_abc","type":"function","function":{"name":"read_file","arguments":"{\"path\":"}}]},"finish_reason":null}]}

data: {"id":"1","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"README.md\"}"}}]},"finish_reason":null}]}

data: {"id":"1","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}],"usage":{"prompt_tokens":95,"completion_tokens":32}}

data: [DONE]

SSE;

        $provider = new OpenAiCompatibleProvider('test-key');
        $chunks = [];
        $response = $provider->parseSseStream($sse, function (string $delta) use (&$chunks): void {
            $chunks[] = $delta;
        });

        $this->assertSame(['Vou ', 'verificar.'], $chunks);
        $this->assertSame('Vou verificar.', $response->text);
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertSame(95, $response->inputTokens);
        $this->assertSame(32, $response->outputTokens);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('call_abc', $response->toolCalls[0]->id);
        $this->assertSame('read_file', $response->toolCalls[0]->name);
        $this->assertSame(['path' => 'README.md'], $response->toolCalls[0]->input);
    }

    public function testConvertsCanonicalMessagesAndToolsToOpenAiFormat(): void
    {
        $provider = new OpenAiCompatibleProvider('test-key');

        $messages = [
            ['role' => 'user', 'content' => 'Leia README.md'],
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Lendo...'],
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'read_file', 'input' => ['path' => 'README.md']],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '1: # Title', 'is_error' => false],
                ],
            ],
        ];

        $converted = $provider->convertMessages('System prompt', $messages);

        $this->assertSame('system', $converted[0]['role']);
        $this->assertSame('user', $converted[1]['role']);
        $this->assertSame('assistant', $converted[2]['role']);
        $this->assertSame('call_1', $converted[2]['tool_calls'][0]['id']);
        $this->assertSame('tool', $converted[3]['role']);
        $this->assertSame('call_1', $converted[3]['tool_call_id']);

        $tools = $provider->convertTools([
            [
                'name' => 'read_file',
                'description' => 'Reads a file',
                'input_schema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            ],
        ]);

        $this->assertSame('function', $tools[0]['type']);
        $this->assertSame('read_file', $tools[0]['function']['name']);
    }

    public function testProviderFactorySelectsConfiguredProvider(): void
    {
        $groq = ProviderFactory::fromConfig(new Config(['LLM_PROVIDER' => 'groq']));
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $groq);
        $this->assertSame('llama-3.3-70b-versatile', $groq->getModel());

        $ollama = ProviderFactory::fromConfig(new Config(['LLM_PROVIDER' => 'ollama']));
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $ollama);
        $this->assertSame('qwen2.5-coder:7b', $ollama->getModel());

        $anthropic = ProviderFactory::fromConfig(new Config(['LLM_PROVIDER' => 'anthropic']));
        $this->assertInstanceOf(AnthropicProvider::class, $anthropic);
    }
}
