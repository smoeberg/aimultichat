<?php

declare(strict_types=1);

namespace Services\Export;

use Core\Logger;
use Core\SettingsService;

/**
 * DOCX Export Service
 * Generates Word documents with AI content marking
 * Uses PhpOffice/PhpWord if available, otherwise falls back to simple HTML
 */
final class DocxExportService
{
    private bool $phpWordAvailable;

    public function __construct()
    {
        $this->phpWordAvailable = class_exists('\PhpOffice\PhpWord\PhpWord');
    }

    /**
     * Export content to DOCX format with AI marking
     *
     * @param string $content The AI-generated content
     * @param string $requestId The request ID
     * @param string $provider The AI provider name
     * @param string $model The AI model name
     * @return string The DOCX file content
     */
    public function export(string $content, string $requestId, string $provider, string $model): string
    {
        if ($this->phpWordAvailable) {
            return $this->exportWithPhpWord($content, $requestId, $provider, $model);
        }

        // Fallback: return HTML that can be converted to DOCX
        return $this->exportAsHtml($content, $requestId, $provider, $model);
    }

    /**
     * Export using PhpWord library
     */
    private function exportWithPhpWord(string $content, string $requestId, string $provider, string $model): string
    {
        try {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $phpWord->setDefaultFontName('Arial');
            $phpWord->setDefaultFontSize(11);

            $section = $phpWord->addSection([
                'margin' => ['top' => 720, 'right' => 720, 'bottom' => 720, 'left' => 720]
            ]);

            // ===== SYNLIG MÆRKNING =====
            $section->addText(
                '🤖 AI-GENERERET INDHOLD',
                ['bold' => true, 'size' => 14, 'color' => '1e40af'],
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
            );
            $section->addTextBreak(1);

            $section->addText(
                "Dette indhold er genereret af kunstig intelligens via Eira MultiChat.",
                ['italic' => true, 'size' => 10],
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
            );
            $section->addText(
                "Kilde: {$provider} ({$model}) • Request ID: {$requestId}",
                ['size' => 9, 'color' => '666666'],
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
            );
            $section->addText(
                "Genereret: " . date('d.m.Y H:i'),
                ['size' => 9, 'color' => '666666'],
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
            );
            $section->addTextBreak(1);
            $section->addText(str_repeat('=', 70), ['size' => 8, 'color' => 'cccccc']);
            $section->addTextBreak(1);

            // ===== INDHOLD =====
            $section->addText($content, ['size' => 11]);

            // ===== FOOTER (synlig) =====
            $section->addTextBreak(2);
            $section->addText(str_repeat('=', 70), ['size' => 8, 'color' => 'cccccc']);
            $section->addText(
                "Eira MultiChat • AI-genereret indhold • Request: {$requestId}",
                ['size' => 8, 'color' => '999999'],
                ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]
            );

            // ===== METADATA (skjult) =====
            $properties = $phpWord->getDocInfo();
            $properties->setCreator('Eira AI');
            $properties->setCompany('Eira');
            $properties->setTitle('AI-genereret indhold');
            $properties->setSubject("Request ID: {$requestId}");
            $properties->setDescription("Genereret af {$provider} ({$model}) - Request: {$requestId}");
            $properties->setKeywords('AI, Eira, syntetisk, genereret');
            $properties->setCategory('AI-genereret');

            // Custom properties
            $customProps = $phpWord->getCustomProperties();
            $customProps->add('AIGenerated', 'boolean', true);
            $customProps->add('RequestID', 'string', $requestId);
            $customProps->add('Provider', 'string', $provider);
            $customProps->add('Model', 'string', $model);
            $customProps->add('PolicyVersion', 'string', SettingsService::get('ai_policy_version', '1.0.0'));

            // ===== GENERER =====
            $tempFile = tempnam(sys_get_temp_dir(), 'eira_docx_');
            $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $objWriter->save($tempFile);

            $content = file_get_contents($tempFile);
            unlink($tempFile);

            return $content;

        } catch (\Throwable $e) {
            Logger::error('DOCX export failed', ['error' => $e->getMessage()]);
            // Fallback to HTML
            return $this->exportAsHtml($content, $requestId, $provider, $model);
        }
    }

    /**
     * Fallback export as HTML (can be saved as .docx or opened in Word)
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
    <meta name="title" content="AI-genereret indhold">
    <meta name="subject" content="Request ID: {$requestId}">
    <meta name="description" content="Genereret af {$provider} ({$model}) - Request: {$requestId}">
    <meta name="keywords" content="AI, Eira, syntetisk, genereret">
    <meta name="category" content="AI-genereret">
    <meta name="AIGenerated" content="true">
    <meta name="RequestID" content="{$requestId}">
    <meta name="Provider" content="{$provider}">
    <meta name="Model" content="{$model}">
    <meta name="PolicyVersion" content="{$policyVersion}">
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
