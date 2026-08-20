<?php

declare(strict_types=1);

namespace Http\Controllers\Api;

use Core\Database;
use Core\Logger;
use Core\Security;
use Services\Export\DocxExportService;
use Services\Export\PdfExportService;

/**
 * Controller for exporting AI-generated content to DOCX and PDF
 * Adds AI content marking to exported documents
 */
final class ExportController
{
    private Database $db;
    private DocxExportService $docxExporter;
    private PdfExportService $pdfExporter;

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->docxExporter = new DocxExportService();
        $this->pdfExporter = new PdfExportService();
    }

    /**
     * Export to DOCX format
     *
     * @param array $input Request input
     * @param int $userId The user ID
     * @param int $organizationId The organization ID
     * @return void Outputs the DOCX file
     */
    public function exportDocx(array $input, int $userId, int $organizationId): void
    {
        $this->export($input, $userId, $organizationId, 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /**
     * Export to PDF format
     *
     * @param array $input Request input
     * @param int $userId The user ID
     * @param int $organizationId The organization ID
     * @return void Outputs the PDF file
     */
    public function exportPdf(array $input, int $userId, int $organizationId): void
    {
        $this->export($input, $userId, $organizationId, 'pdf', 'application/pdf');
    }

    /**
     * Generic export method
     *
     * @param array $input Request input
     * @param int $userId The user ID
     * @param int $organizationId The organization ID
     * @param string $format Export format (docx or pdf)
     * @param string $mimeType MIME type for the response
     * @return void
     */
    private function export(array $input, int $userId, int $organizationId, string $format, string $mimeType): void
    {
        try {
            Security::requireCsrfHeader();

            // Validate input
            if (empty($input['request_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Request ID is required']);
                exit;
            }

            $requestId = trim($input['request_id']);

            // Get content and metadata
            $stmt = $this->db->prepare(
                "SELECT r.*, c.content 
                 FROM ai_requests r
                 LEFT JOIN ai_response_contents c ON r.request_id = c.request_id
                 WHERE r.request_id = ? AND r.organization_id = ?"
            );
            $stmt->execute([$requestId, $organizationId]);
            $data = $stmt->fetch();

            if (!$data) {
                http_response_code(404);
                echo json_encode(['error' => 'Request ID not found']);
                exit;
            }

            // Generate document
            $exporter = $format === 'docx' ? $this->docxExporter : $this->pdfExporter;
            $content = $exporter->export(
                content: $data['content'] ?? '',
                requestId: $data['request_id'],
                provider: $data['provider'] ?? 'unknown',
                model: $data['model'] ?? 'unknown'
            );

            // Filename with request ID
            $shortRequestId = substr($data['request_id'], 0, 8);
            $filename = sprintf(
                'Eira_AI_%s_%s.%s',
                date('Y-m-d'),
                $shortRequestId,
                $format
            );

            // Send response
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: private, no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            echo $content;
            exit;

        } catch (\Throwable $e) {
            Logger::error('Export failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['error' => 'Export failed: ' . $e->getMessage()]);
            exit;
        }
    }
}
