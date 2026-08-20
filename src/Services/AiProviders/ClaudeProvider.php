<?php

declare(strict_types=1);

namespace Services\AiProviders;

use Contracts\AiProvider;
use DTOs\AiResponse;
use Core\Logger;
use Core\SettingsService;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Claude AI Provider implementation
 * Uses Anthropic's Claude API
 */
final class ClaudeProvider implements AiProvider
{
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey = SettingsService::getSecret('claude_api_key') ?? '';
        $this->model = SettingsService::get('claude_model', 'claude-3-opus-20240229');
        $this->endpoint = SettingsService::get('claude_endpoint', 'https://api.anthropic.com/v1/messages');

        if (empty($this->apiKey)) {
            throw new RuntimeException('Claude API key not configured');
        }
    }

    /**
     * Generate a response from Claude
     *
     * @param string $prompt The user prompt
     * @param array $options Provider-specific options
     * @return AiResponse The AI response with metadata
     */
    public function generate(string $prompt, array $options = []): AiResponse
    {
        $maxTokens = $options['max_tokens'] ?? 1024;
        $temperature = $options['temperature'] ?? 0.7;

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => $messages,
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ];

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Logger::error('Claude API curl error', ['error' => $error]);
            throw new RuntimeException('Claude API request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            Logger::error('Claude API error', [
                'http_code' => $httpCode,
                'response' => $response ?? 'null'
            ]);
            throw new RuntimeException('Claude API returned error: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Claude API invalid JSON', ['response' => $response ?? 'null']);
            throw new RuntimeException('Claude API returned invalid JSON');
        }

        $content = $data['content'][0]['text'] ?? '';
        $providerRequestId = $data['id'] ?? null;

        return new AiResponse(
            content: $content,
            requestId: $this->generateRequestId(),
            provider: $this->getProviderName(),
            model: $this->getModelName(),
            providerRequestId: $providerRequestId,
            watermarkType: $this->getWatermarkType(),
            timestamp: new DateTimeImmutable(),
        );
    }

    /**
     * Get the provider name
     */
    public function getProviderName(): string
    {
        return 'claude';
    }

    /**
     * Get the model name
     */
    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * Check if this provider supports watermarking
     * Claude supports SynthID watermark
     */
    public function supportsWatermark(): bool
    {
        return true;
    }

    /**
     * Get the watermark type
     */
    public function getWatermarkType(): string
    {
        return 'synthid';
    }

    /**
     * Generate a unique request ID
     */
    private function generateRequestId(): string
    {
        return 'claude_' . bin2hex(random_bytes(16)) . '_' . time();
    }
}
