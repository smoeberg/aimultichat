<?php

declare(strict_types=1);

namespace Services;

final class DocumentUploadService {
    public static function extractText(string $filePath, string $mimeType, string $originalName): string {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        if (in_array($ext, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'log'], true)) {
            return file_get_contents($filePath) ?: '';
        }
        
        if ($ext === 'docx') {
            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml) {
                    $dom = new \DOMDocument();
                    @$dom->loadXML($xml);
                    return strip_tags($dom->saveHTML());
                }
            }
            return '';
        }
        
        if ($ext === 'pdf') {
            $content = file_get_contents($filePath);
            preg_match_all('/\(([^)]+)\)/', $content, $matches);
            if (!empty($matches[1])) {
                return implode(' ', $matches[1]);
            }
            return '[PDF-dokument modtaget: ' . htmlspecialchars($originalName) . ']';
        }

        return file_get_contents($filePath) ?: '';
    }
}
