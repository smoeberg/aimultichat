<?php

declare(strict_types=1);

namespace Services\Export;

use Core\Logger;
use Core\SettingsService;

/**
 * PDF Export Service
 * Generates PDF documents with AI content marking
 * Uses Dompdf if available, otherwise falls back to simple HTML
 */
final class PdfExportService
{
    private bool $dompdfAvailable;

    public function __construct()
    {
        $this->dompdfAvailable = class_exists('\Dompdf\Dompdf');
    }

    /**
     * Export content to PDF format with AI marking
     *
     * @param string $content The AI-generated content
     * @param string $requestId The request ID
     * @param string $provider The AI provider name
     * @param string $model The AI model name
     * @return string The PDF file content
     */
    public function export(string $content, string $requestId, string $provider, string $model): string
    {
        if ($this->dompdfAvailable) {
            return $this->exportWithDompdf($content, $requestId, $provider, $model);
        }

        // Fallback: return HTML that can be printed to PDF
        return $this->exportAsHtml($content, $requestId, $provider, $model);
    }

    /**
     * Export using Dompdf library
     */
    private function exportWithDompdf(string $content, string $requestId, string $provider, string $model): string
    {
        try {
            $options = new \Dompdf\Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'Arial');
            $options->set('isPhpEnabled', true);

            $dompdf = new \Dompdf\Dompdf($options);

            $policyVersion = SettingsService::get('ai_policy_version', '1.0.0');
            $date = date('d.m.Y H:i');

            // ===== HTML med synlig mærkning =====
            $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .header { 
            background: #f0f4ff; 
            border-left: 4px solid #3b82f6; 
            padding: 15px; 
            margin-bottom: 20px;
        }
        .header h2 { color: #1e40af; margin: 0 0 5px 0; }
        .header p { color: #666; margin: 2px 0; font-size: 12px; }
        .divider { border-top: 1px solid #e5e7eb; margin: 20px 0; }
        .footer { 
            margin-top: 30px; 
            padding-top: 15px; 
            border-top: 1px solid #e5e7eb;
            text-align: center; 
            color: #999; 
            font-size: 10px;
        }
        .content { margin: 20px 0; }
        .badge { 
            display: inline-block; 
            background: #dbeafe; 
            color: #1e40af; 
            padding: 4px 12px; 
            border-radius: 9999px; 
            font-size: 12px; 
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>🤖 AI-GENERERET INDHOLD</h2>
        <p>
            <span class="badge">{$provider} · {$model}</span>
        </p>
        <p>
            <strong>Request ID:</strong> {$requestId}<br>
            <strong>Genereret:</strong> {$date}
        </p>
        <p style="font-size:11px;color:#888;margin-top:8px;">
            Dette indhold er genereret af kunstig intelligens via Eira MultiChat.
        </p>
    </div>

    <div class="divider"></div>

    <div class="content">
        {$this->escapeHtml($content)}
    </div>

    <div class="divider"></div>

    <div class="footer">
        Eira MultiChat • AI-genereret indhold • Request: {$requestId}<br>
        Policy version: {$policyVersion}
    </div>
</body>
</html>
HTML;

            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');

            // ===== PDF METADATA =====
            $dompdf->addInfo('Creator', 'Eira AI');
            $dompdf->addInfo('Author', 'Eira AI');
            $dompdf->addInfo('Title', 'AI-genereret indhold');
            $dompdf->addInfo('Subject', "Request ID: {$requestId}");
            $dompdf->addInfo('Keywords', 'AI, Eira, syntetisk');
            $dompdf->addInfo('Producer', 'Eira AI v1.0');

            $dompdf->render();

            return $dompdf->output();

        } catch (\Throwable $e) {
            Logger::error('PDF export failed', ['error' => $e->getMessage()]);
            // Fallback to HTML
            return $this->exportAsHtml($content, $requestId, $provider, $model);
        }
    }

    /**
     * Fallback export as HTML (can be printed to PDF manually)
     */
    private function exportAsHtml(string $content, string $requestId, string $provider, string $model): string
    {
        $policyVersion = SettingsService::get('ai_policy_version', '1.0.0');
        $date = date('d.m.Y H:i');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="creator" content="Eira AI">
    <meta name="author" content="Eira AI">
    <meta name="title" content="AI-genereret indhold">
    <meta name="subject" content="Request ID: {$requestId}">
    <meta name="keywords" content="AI, Eira, syntetisk">
    <meta name="producer" content="Eira AI v1.0">
    <title>AI-genereret indhold - Eira MultiChat</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .header { 
            background: #f0f4ff; 
            border-left: 4px solid #3b82f6; 
            padding: 15px; 
            margin-bottom: 20px;
        }
        .header h2 { color: #1e40af; margin: 0 0 5px 0; }
        .header p { color: #666; margin: 2px 0; font-size: 12px; }
        .divider { border-top: 1px solid #e5e7eb; margin: 20px 0; }
        .footer { 
            margin-top: 30px; 
            padding-top: 15px; 
            border-top: 1px solid #e5e7eb;
            text-align: center; 
            color: #999; 
            font-size: 10px;
        }
        .content { margin: 20px 0; }
        .badge { 
            display: inline-block; 
            background: #dbeafe; 
            color: #1e40af; 
            padding: 4px 12px; 
            border-radius: 9999px; 
            font-size: 12px; 
            font-weight: bold;
        }
        @media print {
            body { margin: 0; padding: 20px; }
            .header { background: #f0f4ff !important; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>🤖 AI-GENERERET INDHOLD</h2>
        <p>
            <span class="badge">{$provider} · {$model}</span>
        </p>
        <p>
            <strong>Request ID:</strong> {$requestId}<br>
            <strong>Genereret:</strong> {$date}
        </p>
        <p style="font-size:11px;color:#888;margin-top:8px;">
            Dette indhold er genereret af kunstig intelligens via Eira MultiChat.
        </p>
    </div>

    <div class="divider"></div>

    <div class="content">
        {$this->escapeHtml($content)}
    </div>

    <div class="divider"></div>

    <div class="footer">
        Eira MultiChat • AI-genereret indhold • Request: {$requestId}<br>
        Policy version: {$policyVersion}
    </div>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * Escape HTML special characters
     */
    private function escapeHtml(string $content): string
    {
        return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
