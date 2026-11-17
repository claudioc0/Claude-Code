<?php

declare(strict_types=1);

namespace ClaudeCode\Providers;

use RuntimeException;
use stdClass;

final class OpenAiCompatibleProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'llama-3.3-70b-versatile',
        private readonly string $baseUrl = 'https://api.groq.com/openai/v1',
        private readonly int $maxTokens = 4096,
        private readonly bool $allowEmptyApiKey = false,
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
        if (!$this->allowEmptyApiKey && $this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY não configurada no arquivo .env.');
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/chat/completions';
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->convertMessages($systemPrompt, $messages),
            'stream' => true,
        ];

        $convertedTools = $this->convertTools($tools);
        if ($convertedTools !== []) {
            $payload['tools'] = $convertedTools;
            $payload['tool_choice'] = 'auto';
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = [
            'content-type: application/json',
            'accept: text/event-stream',
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL.');
        }

        $buffer = '';
        $state = $this->createStreamState();
        $rawErrorBody = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
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
            throw new RuntimeException("Erro da API OpenAI-compatible (HTTP {$httpCode}): {$rawErrorBody}");
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
     * Converts canonical (Anthropic-style) messages to OpenAI chat/completions format.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function convertMessages(string $systemPrompt, array $messages): array
    {
        $result = [];
        if ($systemPrompt !== '') {
            $result[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $result[] = ['role' => $role, 'content' => $content];
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            if ($role === 'user') {
                $textParts = [];
                foreach ($content as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = (string) ($block['type'] ?? '');
                    if ($type === 'tool_result') {
                        $result[] = [
                            'role' => 'tool',
                            'tool_call_id' => (string) ($block['tool_use_id'] ?? ''),
                            'content' => (string) ($block['content'] ?? ''),
                        ];
                    } elseif ($type === 'text') {
                        $textParts[] = (string) ($block['text'] ?? '');
                    }
                }
                if ($textParts !== []) {
                    $result[] = ['role' => 'user', 'content' => implode("\n", $textParts)];
                }
                continue;
            }

            if ($role === 'assistant') {
                $textParts = [];
                $toolCalls = [];

                foreach ($content as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = (string) ($block['type'] ?? '');
                    if ($type === 'text') {
                        $text = (string) ($block['text'] ?? '');
                        if ($text !== '') {
                            $textParts[] = $text;
                        }
                    } elseif ($type === 'tool_use') {
                        $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                        $argsJson = $input === []
                            ? '{}'
                            : json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $toolCalls[] = [
                            'id' => (string) ($block['id'] ?? ''),
                            'type' => 'function',
                            'function' => [
                                'name' => (string) ($block['name'] ?? ''),
                                'arguments' => $argsJson,
                            ],
                        ];
                    }
                }

                $assistantMsg = [
                    'role' => 'assistant',
                    'content' => $textParts !== [] ? implode('', $textParts) : null,
                ];
                if ($toolCalls !== []) {
                    $assistantMsg['tool_calls'] = $toolCalls;
                }
                $result[] = $assistantMsg;
            }
        }

        return $result;
    }

    /**
     * Converts ToolRegistry schemas to OpenAI function tool definitions.
     *
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array<string, mixed>>
     */
    public function convertTools(array $tools): array
    {
        $converted = [];
        foreach ($tools as $tool) {
            $schema = $tool['input_schema'] ?? ['type' => 'object', 'properties' => new stdClass()];
            $converted[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) ($tool['name'] ?? ''),
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => $schema,
                ],
            ];
        }

        return $converted;
    }

    /**
     * @return array<string, mixed>
     */
    private function createStreamState(): array
    {
        return [
            'text' => '',
            'toolCalls' => [],
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
        if ($line === '' || !str_starts_with($line, 'data:')) {
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

        if (isset($data['error']) && is_array($data['error'])) {
            $msg = (string) ($data['error']['message'] ?? 'Erro desconhecido no stream.');
            throw new RuntimeException("Erro no stream OpenAI-compatible: {$msg}");
        }

        if (isset($data['usage']) && is_array($data['usage'])) {
            $state['inputTokens'] = (int) ($data['usage']['prompt_tokens'] ?? $state['inputTokens']);
            $state['outputTokens'] = (int) ($data['usage']['completion_tokens'] ?? $state['outputTokens']);
        }

        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice)) {
            return;
        }

        if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
            $state['stopReason'] = match ((string) $choice['finish_reason']) {
                'tool_calls' => 'tool_use',
                'stop' => 'end_turn',
                default => (string) $choice['finish_reason'],
            };
        }

        $delta = $choice['delta'] ?? null;
        if (!is_array($delta)) {
            return;
        }

        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            $state['text'] .= $delta['content'];
            if ($onTextDelta !== null) {
                $onTextDelta($delta['content']);
            }
        }

        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tcDelta) {
                if (!is_array($tcDelta)) {
                    continue;
                }
                $index = (int) ($tcDelta['index'] ?? 0);
                if (!isset($state['toolCalls'][$index])) {
                    $state['toolCalls'][$index] = [
                        'id' => '',
                        'name' => '',
                        'arguments' => '',
                    ];
                }

                if (isset($tcDelta['id']) && is_string($tcDelta['id']) && $tcDelta['id'] !== '') {
                    $state['toolCalls'][$index]['id'] = $tcDelta['id'];
                }

                $fn = $tcDelta['function'] ?? [];
                if (is_array($fn)) {
                    if (isset($fn['name']) && is_string($fn['name']) && $fn['name'] !== '') {
                        $state['toolCalls'][$index]['name'] .= $fn['name'];
                    }
                    if (isset($fn['arguments']) && is_string($fn['arguments'])) {
                        $state['toolCalls'][$index]['arguments'] .= $fn['arguments'];
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    private function finalizeStreamState(array $state): LlmResponse
    {
        $contentBlocks = [];
        $text = (string) $state['text'];
        if ($text !== '') {
            $contentBlocks[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        ksort($state['toolCalls']);
        $toolCalls = [];

        foreach ($state['toolCalls'] as $index => $rawCall) {
            $id = (string) ($rawCall['id'] !== '' ? $rawCall['id'] : 'call_' . $index);
            $name = (string) $rawCall['name'];
            $rawArgs = trim((string) $rawCall['arguments']);
            $decoded = $rawArgs !== '' ? json_decode($rawArgs, true) : [];
            $input = is_array($decoded) ? $decoded : [];

            $toolCalls[] = new ToolCall($id, $name, $input);
            $contentBlocks[] = [
                'type' => 'tool_use',
                'id' => $id,
                'name' => $name,
                'input' => $input,
            ];
        }

        return new LlmResponse(
            text: $text,
            toolCalls: $toolCalls,
            contentBlocks: $contentBlocks,
            stopReason: (string) $state['stopReason'],
            inputTokens: (int) $state['inputTokens'],
            outputTokens: (int) $state['outputTokens'],
        );
    }
}
