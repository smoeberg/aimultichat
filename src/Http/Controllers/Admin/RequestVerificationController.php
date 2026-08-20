<?php

declare(strict_types=1);

namespace Http\Controllers\Admin;

use Core\Database;
use Core\Logger;
use Core\Security;

/**
 * Controller for verifying AI request IDs
 * Allows administrators to look up and verify AI-generated content
 */
final class RequestVerificationController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Look up a request by ID
     *
     * @param string $requestId The request ID to look up
     * @param int $userId The user ID (for organization scoping)
     * @param int $organizationId The organization ID
     * @return array Response data
     */
    public function lookup(string $requestId, int $userId, int $organizationId): array
    {
        try {
            // Tenant-scoped lookup
            $stmt = $this->db->prepare(
                "SELECT * FROM ai_requests 
                 WHERE request_id = ? AND organization_id = ?"
            );
            $stmt->execute([$requestId, $organizationId]);
            $response = $stmt->fetch();

            if (!$response) {
                return [
                    'found' => false,
                    'message' => 'Request ID not found in this organization',
                ];
            }

            // Get content
            $stmt = $this->db->prepare(
                "SELECT content FROM ai_response_contents WHERE request_id = ?"
            );
            $stmt->execute([$requestId]);
            $content = $stmt->fetchColumn();

            return [
                'found' => true,
                'request_id' => $response['request_id'],
                'provider' => $response['provider'],
                'model' => $response['model'],
                'watermark_type' => $response['watermark_type'],
                'provider_request_id' => $response['provider_request_id'],
                'is_ai_generated' => (bool)$response['is_ai_generated'],
                'policy_version' => $response['policy_version'],
                'timestamp' => $response['created_at'],
                'content' => $content,
                'has_watermark' => $response['watermark_type'] && $response['watermark_type'] !== 'none',
                'organization_id' => $response['organization_id'],
                'user_id' => $response['user_id'],
                'conversation_id' => $response['conversation_id'],
            ];

        } catch (\Throwable $e) {
            Logger::error('Request verification failed', ['error' => $e->getMessage()]);
            return [
                'found' => false,
                'message' => 'Verification failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Show the verification form
     *
     * @return string HTML content
     */
    public function showForm(): string
    {
        return $this->renderForm();
    }

    /**
     * Render the verification form HTML
     */
    private function renderForm(): string
    {
        $csrf = $_SESSION['csrf_token'] ?? '';

        return <<<HTML
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verificer AI-request - EiraMultiChat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: #f8fafc; 
            margin: 0; 
            padding: 0; 
            color: #1e293b; 
        }
        .container { 
            max-width: 800px; 
            margin: 40px auto; 
            padding: 20px; 
        }
        h1 { 
            color: #1e40af; 
            margin-bottom: 20px; 
            font-size: 1.5rem; 
        }
        .card { 
            background: white; 
            border-radius: 12px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
            padding: 24px; 
            margin-bottom: 20px; 
        }
        .form-group { 
            display: flex; 
            gap: 12px; 
        }
        input[type="text"] { 
            flex: 1; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            padding: 10px 14px; 
            font-size: 14px; 
            outline: none; 
            transition: border-color 0.2s; 
        }
        input[type="text"]:focus { 
            border-color: #3b82f6; 
        }
        button { 
            background: #3b82f6; 
            color: white; 
            border: none; 
            border-radius: 8px; 
            padding: 10px 20px; 
            font-size: 14px; 
            cursor: pointer; 
            transition: background-color 0.2s; 
        }
        button:hover { 
            background: #2563eb; 
        }
        .result { 
            margin-top: 20px; 
        }
        .result.success { 
            border: 1px solid #bbf7d0; 
            background: #bbf7d0; 
        }
        .result.error { 
            border: 1px solid #fecaca; 
            background: #fecaca; 
        }
        .dl-grid { 
            display: grid; 
            grid-template-columns: repeat(2, 1fr); 
            gap: 12px; 
            margin-top: 16px; 
        }
        .dl-grid dt { 
            font-weight: 600; 
            color: #475569; 
        }
        .dl-grid dd { 
            color: #64748b; 
        }
        .badge { 
            display: inline-block; 
            background: #dbeafe; 
            color: #1e40af; 
            padding: 4px 12px; 
            border-radius: 9999px; 
            font-size: 12px; 
            font-weight: bold; 
        }
        .content-preview { 
            margin-top: 16px; 
            padding: 12px; 
            background: #f8fafc; 
            border: 1px solid #e2e8f0; 
            border-radius: 8px; 
            max-height: 200px; 
            overflow-y: auto; 
            font-size: 13px; 
            white-space: pre-wrap; 
            word-wrap: break-word; 
        }
        .spinner { 
            display: inline-block; 
            width: 20px; 
            height: 20px; 
            border: 3px solid #f3f4f6; 
            border-top-color: #3b82f6; 
            border-radius: 50%; 
            animation: spin 1s ease-in-out infinite; 
        }
        @keyframes spin { 
            to { transform: rotate(360deg); } 
        }
        .loading { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            color: #64748b; 
        }
        details { 
            margin-top: 16px; 
        }
        details summary { 
            cursor: pointer; 
            color: #64748b; 
            font-size: 13px; 
        }
        details summary:hover { 
            color: #3b82f6; 
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Verificer AI-request</h1>
        
        <div class="card">
            <form id="verifyForm" onsubmit="return verifyRequest(event)" class="form-group">
                <input type="text" 
                       id="requestId" 
                       placeholder="Indtast Request ID (f.eks. a1b2c3d4-...)" 
                       class="flex-1 border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <button type="submit" 
                        class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition">
                    Verificer
                </button>
            </form>

            <div id="result" class="result hidden"></div>
        </div>
    </div>

    <script>
    async function verifyRequest(event) {
        event.preventDefault();
        const requestId = document.getElementById('requestId').value.trim();
        if (!requestId) {
            alert('Indtast venligst et Request ID');
            return false;
        }

        const resultDiv = document.getElementById('result');
        resultDiv.classList.remove('hidden');
        resultDiv.className = 'result';
        resultDiv.innerHTML = `
            <div class="loading">
                <div class="spinner"></div>
                <span>Søger...</span>
            </div>
        `;

        try {
            const response = await fetch('/admin.php?action=verify-request&requestId=' + encodeURIComponent(requestId), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();

            if (data.found) {
                resultDiv.className = 'result success';
                resultDiv.innerHTML = `
                    <h3 style="color: #166534; margin: 0 0 16px 0;">✅ AI-genereret indhold verificeret</h3>
                    
                    <dl class="dl-grid">
                        <dt>Request ID:</dt>
                        <dd><code style="color: #3b82f6;">${escapeHtml(data.request_id)}</code></dd>
                        
                        <dt>Provider:</dt>
                        <dd>${escapeHtml(data.provider)}</dd>
                        
                        <dt>Model:</dt>
                        <dd>${escapeHtml(data.model)}</dd>
                        
                        <dt>Watermark:</dt>
                        <dd>${escapeHtml(data.watermark_type || 'none')}</dd>
                        
                        <dt>Watermark aktiv:</dt>
                        <dd>${data.has_watermark ? '✅ Ja' : '❌ Nej'}</dd>
                        
                        <dt>Policy version:</dt>
                        <dd>${escapeHtml(data.policy_version)}</dd>
                        
                        <dt>Timestamp:</dt>
                        <dd>${new Date(data.timestamp).toLocaleString('da-DK')}</dd>
                        
                        <dt>Organisation:</dt>
                        <dd>${escapeHtml(data.organization_id || 'N/A')}</dd>
                    </dl>
                    
                    ${data.content ? `
                        <details>
                            <summary>📄 Vis indhold (audit)</summary>
                            <div class="content-preview">
                                ${escapeHtml(data.content)}
                            </div>
                        </details>
                    ` : ''}
                `;
            } else {
                resultDiv.className = 'result error';
                resultDiv.innerHTML = `
                    <p style="color: #991b1b; margin: 0;">❌ Request ID ikke fundet i denne organisation</p>
                    <p style="color: #78716c; margin: 8px 0 0 0; font-size: 13px;">
                        Kontroller at ID'et er korrekt, eller at requesten tilhører din organisation.
                    </p>
                `;
            }
        } catch (error) {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = `
                <p style="color: #991b1b; margin: 0;">❌ Fejl under verifikation</p>
                <p style="color: #78716c; margin: 8px 0 0 0; font-size: 13px;">${escapeHtml(error.message)}</p>
            `;
        }

        return false;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    </script>
</body>
</html>
HTML;
    }
}
