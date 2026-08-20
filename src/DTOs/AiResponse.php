<?php

declare(strict_types=1);

namespace DTOs;

use DateTimeImmutable;

/**
 * Data Transfer Object for AI responses
 * Contains all metadata needed for AI content marking and audit
 */
final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $requestId,
        public string $provider,
        public string $model,
        public ?string $providerRequestId = null,
        public string $watermarkType = 'none',
        public DateTimeImmutable $timestamp = new DateTimeImmutable(),
    ) {}

    /**
     * Convert to array for logging/storage
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'request_id' => $this->requestId,
            'provider' => $this->provider,
            'model' => $this->model,
            'provider_request_id' => $this->providerRequestId,
            'watermark_type' => $this->watermarkType,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get formatted timestamp for display
     */
    public function getFormattedTimestamp(): string
    {
        return $this->timestamp->format('d.m.Y H:i');
    }

    /**
     * Check if this response has watermark support
     */
    public function hasWatermark(): bool
    {
        return $this->watermarkType !== 'none' && !empty($this->watermarkType);
    }
}
