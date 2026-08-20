<?php

declare(strict_types=1);

namespace Services\AiGateway;

use Contracts\AiProvider;
use DTOs\AiResponse;
use Services\AiProviders\ClaudeProvider;
use Services\AiProviders\GeminiProvider;
use InvalidArgumentException;
use Core\Logger;

/**
 * AI Gateway - Main entry point for AI provider interactions
 * Manages multiple AI providers and routes requests appropriately
 */
final class AiGateway
{
    /** @var array<string, AiProvider> */
    private array $providers = [];

    public function __construct()
    {
        $this->providers = [
            'claude' => new ClaudeProvider(),
            'gemini' => new GeminiProvider(),
        ];
    }

    /**
     * Generate a response from the specified AI provider
     *
     * @param string $prompt The user prompt
     * @param string $provider Provider name (claude, gemini, etc.)
     * @param array $options Provider-specific options
     * @return AiResponse The AI response with metadata
     * @throws InvalidArgumentException If provider is not supported
     */
    public function generate(string $prompt, string $provider = 'claude', array $options = []): AiResponse
    {
        if (!isset($this->providers[$provider])) {
            throw new InvalidArgumentException("Provider '{$provider}' not supported. Supported: " . implode(', ', $this->getSupportedProviders()));
        }

        $providerInstance = $this->providers[$provider];
        $response = $providerInstance->generate($prompt, $options);

        // Log watermark status
        Logger::info('AI generation', [
            'provider' => $provider,
            'request_id' => $response->requestId,
            'watermark_type' => $response->watermarkType,
            'has_watermark' => $providerInstance->supportsWatermark(),
        ]);

        return $response;
    }

    /**
     * Get list of supported providers
     *
     * @return array<string> List of provider names
     */
    public function getSupportedProviders(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get a specific provider instance
     *
     * @param string $name Provider name
     * @return AiProvider The provider instance
     * @throws InvalidArgumentException If provider not found
     */
    public function getProvider(string $name): AiProvider
    {
        if (!isset($this->providers[$name])) {
            throw new InvalidArgumentException("Provider '{$name}' not found");
        }
        return $this->providers[$name];
    }

    /**
     * Check if a provider supports watermarking
     *
     * @param string $name Provider name
     * @return bool True if provider supports watermarking
     */
    public function providerSupportsWatermark(string $name): bool
    {
        if (!isset($this->providers[$name])) {
            return false;
        }
        return $this->providers[$name]->supportsWatermark();
    }
}
