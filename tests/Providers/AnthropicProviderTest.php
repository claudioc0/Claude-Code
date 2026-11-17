<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Providers;

use ClaudeCode\Providers\AnthropicProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicProviderTest extends TestCase
{
    public function testParsesSseTextAndToolUseStream(): void
    {
        $sse = <<<SSE
event: message_start
data: {"type":"message_start","message":{"usage":{"input_tokens":120}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Vou ler "}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"o arquivo."}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: content_block_start
data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_123","name":"read_file","input":{}}}

event: content_block_delta
data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"path\":"}}

event: content_block_delta
data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"\"README.md\"}"}}

event: content_block_stop
data: {"type":"content_block_stop","index":1}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":45}}

event: message_stop
data: {"type":"message_stop"}

SSE;

        $provider = new AnthropicProvider('test-key');
        $chunks = [];
        $response = $provider->parseSseStream($sse, function (string $delta) use (&$chunks): void {
            $chunks[] = $delta;
        });

        $this->assertSame(['Vou ler ', 'o arquivo.'], $chunks);
        $this->assertSame('Vou ler o arquivo.', $response->text);
        $this->assertSame('tool_use', $response->stopReason);
        $this->assertSame(120, $response->inputTokens);
        $this->assertSame(45, $response->outputTokens);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('toolu_123', $response->toolCalls[0]->id);
        $this->assertSame('read_file', $response->toolCalls[0]->name);
        $this->assertSame(['path' => 'README.md'], $response->toolCalls[0]->input);
    }
}
