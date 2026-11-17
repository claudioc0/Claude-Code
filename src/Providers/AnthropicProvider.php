<?php

declare(strict_types=1);

namespace ClaudeCode\Providers;

use RuntimeException;

final class AnthropicProvider implements LlmProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
        private readonly int $maxTokens = 4096,
        private readonly string $apiUrl = self::API_URL,
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param callable(string): void|null $onTextDelta
     */
    public function streamMessage(
        string $systemPrompt,
        array $messages,
        array $tools = [],
        ?callable $onTextDelta = null,
    ): LlmResponse {
        if ($this->apiKey === '' || str_starts_with($this->apiKey, 'sk-ant-...')) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada no arquivo .env.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
            'stream' => true,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }

        $buffer = '';
        $state = $this->createStreamState();
        $rawErrorBody = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
                'accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$state, &$rawErrorBody, $onTextDelta): int {
                $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode >= 400) {
                    $rawErrorBody .= $chunk;
                    return strlen($chunk);
                }

                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);
                    $this->processSseLine($line, $state, $onTextDelta);
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false) {
            throw new RuntimeException("Erro na requisição cURL: {$curlError}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("Erro da API Anthropic (HTTP {$httpCode}): {$rawErrorBody}");
        }

        if ($buffer !== '') {
            $this->processSseLine(rtrim($buffer, "\r"), $state, $onTextDelta);
        }

        return $this->finalizeStreamState($state);
    }

    /**
     * Parses a complete SSE response string (useful for unit testing).
     *
     * @param callable(string): void|null $onTextDelta
     */
    public function parseSseStream(string $ssePayload, ?callable $onTextDelta = null): LlmResponse
    {
        $state = $this->createStreamState();
        $lines = preg_split("/\r?\n/", $ssePayload) ?: [];

        foreach ($lines as $line) {
            $this->processSseLine($line, $state, $onTextDelta);
        }

        return $this->finalizeStreamState($state);
    }

    /**
     * @return array<string, mixed>
     */
    private function createStreamState(): array
    {
        return [
            'currentEvent' => '',
            'text' => '',
            'blocks' => [],
            'toolJsonBuffers' => [],
            'stopReason' => 'end_turn',
            'inputTokens' => 0,
            'outputTokens' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param callable(string): void|null $onTextDelta
     */
    private function processSseLine(string $line, array &$state, ?callable $onTextDelta): void
    {
        if ($line === '') {
            return;
        }

        if (str_starts_with($line, 'event:')) {
            $state['currentEvent'] = trim(substr($line, 6));
            return;
        }

        if (!str_starts_with($line, 'data:')) {
            return;
        }

        $jsonStr = trim(substr($line, 5));
        if ($jsonStr === '' || $jsonStr === '[DONE]') {
            return;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($jsonStr, true);
        if (!is_array($data)) {
            return;
        }

        $type = (string) ($data['type'] ?? $state['currentEvent']);

        switch ($type) {
            case 'message_start':
                $usage = $data['message']['usage'] ?? [];
                if (is_array($usage)) {
                    $state['inputTokens'] = (int) ($usage['input_tokens'] ?? 0);
                }
                break;

            case 'content_block_start':
                $index = (int) ($data['index'] ?? 0);
                $block = $data['content_block'] ?? [];
                if (is_array($block)) {
                    $blockType = (string) ($block['type'] ?? 'text');
                    if ($blockType === 'text') {
                        $state['blocks'][$index] = [
                            'type' => 'text',
                            'text' => (string) ($block['text'] ?? ''),
                        ];
                    } elseif ($blockType === 'tool_use') {
                        $state['blocks'][$index] = [
                            'type' => 'tool_use',
                            'id' => (string) ($block['id'] ?? ''),
                            'name' => (string) ($block['name'] ?? ''),
                            'input' => [],
                        ];
                        $state['toolJsonBuffers'][$index] = '';
                    }
                }
                break;

            case 'content_block_delta':
                $index = (int) ($data['index'] ?? 0);
                $delta = $data['delta'] ?? [];
                if (!is_array($delta)) {
                    break;
                }
                $deltaType = (string) ($delta['type'] ?? '');
                if ($deltaType === 'text_delta') {
                    $chunk = (string) ($delta['text'] ?? '');
                    $state['text'] .= $chunk;
                    if (isset($state['blocks'][$index])) {
                        $state['blocks'][$index]['text'] .= $chunk;
                    }
                    if ($onTextDelta !== null && $chunk !== '') {
                        $onTextDelta($chunk);
                    }
                } elseif ($deltaType === 'input_json_delta') {
                    $partial = (string) ($delta['partial_json'] ?? '');
                    $state['toolJsonBuffers'][$index] = ($state['toolJsonBuffers'][$index] ?? '') . $partial;
                }
                break;

            case 'content_block_stop':
                $index = (int) ($data['index'] ?? 0);
                if (isset($state['blocks'][$index]) && $state['blocks'][$index]['type'] === 'tool_use') {
                    $rawJson = trim((string) ($state['toolJsonBuffers'][$index] ?? ''));
                    if ($rawJson !== '') {
                        $decoded = json_decode($rawJson, true);
                        $state['blocks'][$index]['input'] = is_array($decoded) ? $decoded : [];
                    }
                }
                break;

            case 'message_delta':
                $delta = $data['delta'] ?? [];
                if (is_array($delta) && isset($delta['stop_reason'])) {
                    $state['stopReason'] = (string) $delta['stop_reason'];
                }
                $usage = $data['usage'] ?? [];
                if (is_array($usage) && isset($usage['output_tokens'])) {
                    $state['outputTokens'] = (int) $usage['output_tokens'];
                }
                break;

            case 'error':
                $msg = (string) ($data['error']['message'] ?? 'Erro desconhecido no stream Anthropic.');
                throw new RuntimeException("Erro no stream Anthropic: {$msg}");
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function finalizeStreamState(array $state): LlmResponse
    {
        ksort($state['blocks']);
        $contentBlocks = array_values($state['blocks']);
        $toolCalls = [];

        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $toolCalls[] = new ToolCall(
                    id: (string) ($block['id'] ?? ''),
                    name: (string) ($block['name'] ?? ''),
                    input: $input,
                );
            }
        }

        return new LlmResponse(
            text: (string) $state['text'],
            toolCalls: $toolCalls,
            contentBlocks: $contentBlocks,
            stopReason: (string) $state['stopReason'],
            inputTokens: (int) $state['inputTokens'],
            outputTokens: (int) $state['outputTokens'],
        );
    }
}
