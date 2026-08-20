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
 * Google Gemini AI Provider implementation
 * Uses Google's Generative Language API
 */
final class GeminiProvider implements AiProvider
{
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey = SettingsService::getSecret('gemini_api_key') ?? '';
        $this->model = SettingsService::get('gemini_model', 'gemini-1.5-pro');
        $this->endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->model . ':generateContent';

        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key not configured');
        }
    }

    /**
     * Generate a response from Gemini
     *
     * @param string $prompt The user prompt
     * @param array $options Provider-specific options
     * @return AiResponse The AI response with metadata
     */
    public function generate(string $prompt, array $options = []): AiResponse
    {
        $maxTokens = $options['max_tokens'] ?? 1024;
        $temperature = $options['temperature'] ?? 0.7;

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => $temperature,
            ]
        ];

        $url = $this->endpoint . '?key=' . $this->apiKey;

        $headers = [
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);
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
            Logger::error('Gemini API curl error', ['error' => $error]);
            throw new RuntimeException('Gemini API request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            Logger::error('Gemini API error', [
                'http_code' => $httpCode,
                'response' => $response ?? 'null'
            ]);
            throw new RuntimeException('Gemini API returned error: HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Gemini API invalid JSON', ['response' => $response ?? 'null']);
            throw new RuntimeException('Gemini API returned invalid JSON');
        }

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $providerRequestId = $data['candidates'][0]['finishReason'] ?? null;

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
        return 'gemini';
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
     * Gemini supports SynthID-Text watermark
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
        return 'gemini_' . bin2hex(random_bytes(16)) . '_' . time();
    }
}
