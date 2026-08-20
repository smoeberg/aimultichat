<?php

declare(strict_types=1);

namespace Http\Controllers\Api;

use Core\Database;
use Core\Logger;
use Core\Security;

/**
 * Controller for logging copy events of AI-generated content
 * Tracks when users copy AI responses for audit purposes
 */
final class CopyLogController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Log a copy event for AI-generated content
     *
     * @param array $input Request input
     * @param int $userId The user ID
     * @param int $organizationId The organization ID
     * @return array Response data
     */
    public function log(array $input, int $userId, int $organizationId): array
    {
        try {
            Security::requireCsrfHeader();

            // Validate input
            if (empty($input['request_id'])) {
                return ['error' => 'Request ID is required', 'success' => false];
            }

            $requestId = trim($input['request_id']);
            $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');

            // Check if request_id exists in this organization
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM ai_requests 
                 WHERE request_id = ? AND organization_id = ?"
            );
            $stmt->execute([$requestId, $organizationId]);
            $exists = (bool)$stmt->fetchColumn();

            if (!$exists) {
                return ['error' => 'Request ID not found', 'success' => false];
            }

            // Log copy event (anonymized - no user_id or content)
            $metadata = json_encode([
                'timestamp' => $timestamp,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);

            $stmt = $this->db->prepare(
                "INSERT INTO audit_events 
                 (event_type, request_id, organization_id, metadata, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                'content_copied',
                $requestId,
                $organizationId,
                $metadata
            ]);

            Logger::info('Copy event logged', [
                'request_id' => $requestId,
                'organization_id' => $organizationId,
            ]);

            return ['success' => true];

        } catch (\Throwable $e) {
            Logger::error('Copy log failed', ['error' => $e->getMessage()]);
            return ['error' => 'Failed to log copy event', 'success' => false];
        }
    }
}
