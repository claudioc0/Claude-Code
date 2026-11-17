<?php

declare(strict_types=1);

namespace ClaudeCode\Providers;

use ClaudeCode\Core\Config;
use InvalidArgumentException;

final class ProviderFactory
{
    public static function fromConfig(Config $config): LlmProviderInterface
    {
        $provider = strtolower($config->get('LLM_PROVIDER', 'openai'));

        return match ($provider) {
            'anthropic' => new AnthropicProvider(
                apiKey: $config->get('ANTHROPIC_API_KEY', ''),
                model: $config->get('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
                maxTokens: $config->getInt('ANTHROPIC_MAX_TOKENS', 4096),
            ),
            'openai', 'openai-compatible', 'groq', 'openrouter', 'ollama' => self::createOpenAiCompatible($config, $provider),
            default => throw new InvalidArgumentException("Provider LLM não suportado: {$provider}"),
        };
    }

    private static function createOpenAiCompatible(Config $config, string $provider): OpenAiCompatibleProvider
    {
        $defaultBaseUrl = match ($provider) {
            'openrouter' => 'https://openrouter.ai/api/v1',
            'ollama' => 'http://127.0.0.1:11434/v1',
            default => 'https://api.groq.com/openai/v1',
        };

        $defaultModel = match ($provider) {
            'openrouter' => 'meta-llama/llama-3.3-70b-instruct:free',
            'ollama' => 'qwen2.5-coder:7b',
            default => 'llama-3.3-70b-versatile',
        };

        $baseUrl = $config->get('OPENAI_BASE_URL', $defaultBaseUrl);
        $apiKey = $config->get('OPENAI_API_KEY', '');
        $model = $config->get('OPENAI_MODEL', $defaultModel);
        $maxTokens = $config->getInt('OPENAI_MAX_TOKENS', 4096);
        $allowEmptyKey = $provider === 'ollama' || str_contains($baseUrl, '127.0.0.1') || str_contains($baseUrl, 'localhost');

        return new OpenAiCompatibleProvider(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            maxTokens: $maxTokens,
            allowEmptyApiKey: $allowEmptyKey,
        );
    }
}
