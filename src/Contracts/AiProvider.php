<?php

declare(strict_types=1);

namespace Contracts;

use DTOs\AiResponse;

/**
 * Interface for AI providers
 * All AI providers must implement this interface
 */
interface AiProvider
{
    /**
     * Generate a response from the AI provider
     *
     * @param string $prompt The user prompt
     * @param array $options Provider-specific options
     * @return AiResponse The AI response with metadata
     */
    public function generate(string $prompt, array $options = []): AiResponse;

    /**
     * Get the provider name (e.g., 'claude', 'gemini')
     */
    public function getProviderName(): string;

    /**
     * Get the model name (e.g., 'claude-3-opus-20240229')
     */
    public function getModelName(): string;

    /**
     * Check if this provider supports watermarking
     */
    public function supportsWatermark(): bool;

    /**
     * Get the watermark type (e.g., 'synthid', 'none')
     */
    public function getWatermarkType(): string;
}
