<?php

declare(strict_types=1);

namespace Listeners;

use Events\AiResponseGenerated;
use Core\Database;
use Core\Logger;

/**
 * Listener for logging AI responses to the database
 * Handles audit trail creation for all AI-generated content
 */
final class LogAiResponse
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Handle the AI response generated event
     * Logs the response to ai_requests and ai_response_contents tables
     */
    public function handle(AiResponseGenerated $event): void
    {
        // Validate organization_id
        if ($event->organizationId === null) {
            Logger::error('AI response logging failed: organization_id is required');
            throw new \RuntimeException('organization_id required for audit logging');
        }

        $requestId = $event->response->requestId;
        $response = $event->response;

        try {
            // Insert into ai_requests using prepared statements
            $stmt = $this->db->prepare(
                "INSERT INTO ai_requests 
                (request_id, organization_id, user_id, conversation_id, provider, model, 
                 provider_request_id, watermark_type, is_ai_generated, content_hash, 
                 policy_version, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, '1.0.0', ?, NOW())"
            );
            $stmt->execute([
                $requestId,
                $event->organizationId,
                $event->userId,
                $event->conversationId,
                $response->provider,
                $response->model,
                $response->providerRequestId,
                $response->watermarkType,
                hash('sha256', $response->content),
                $response->timestamp->format('Y-m-d H:i:s')
            ]);

            // Insert content into ai_response_contents
            $contentHash = hash('sha256', $response->content);
            $promptHash = $event->prompt ? hash('sha256', $event->prompt) : null;

            $stmt = $this->db->prepare(
                "INSERT INTO ai_response_contents 
                (request_id, content, prompt_hash, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute([
                $requestId,
                $response->content,
                $promptHash
            ]);

            Logger::info('AI response logged', [
                'request_id' => $requestId,
                'provider' => $response->provider,
                'model' => $response->model,
                'watermark_type' => $response->watermarkType,
                'organization_id' => $event->organizationId,
            ]);

        } catch (\Throwable $e) {
            Logger::error('Failed to log AI response', [
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);
            throw $e;
        }
    }
}
