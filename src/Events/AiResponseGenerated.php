<?php

declare(strict_types=1);

namespace Events;

use DTOs\AiResponse;

/**
 * Event fired when an AI response is generated
 * Used for audit logging and content marking
 */
final readonly class AiResponseGenerated
{
    public function __construct(
        public AiResponse $response,
        public ?int $organizationId = null,
        public ?int $userId = null,
        public ?int $conversationId = null,
        public ?string $prompt = null,
    ) {}
}
