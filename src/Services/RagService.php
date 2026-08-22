<?php

declare(strict_types=1);

namespace Services;

use Core\Database;

final class RagService {
    
    /**
     * Search relevant document chunks and format them with precise citations.
     */
    public static function searchRelevantContext(string $query, int $userRoleId): string {
        $db = Database::getConnection();
        
        // Find documents accessible by user's role
        $stmt = $db->prepare("
            SELECT id, title, filename, extracted_content 
            FROM rag_documents 
            WHERE min_role_id <= ? 
            ORDER BY id DESC 
            LIMIT 5
        ");
        $stmt->execute([$userRoleId]);
        $docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($docs)) {
            return '';
        }

        $context = "

--- START INTERN VIDENSBASE (RAG KILDER MED CITERING) ---\n";
        $hasMatch = false;

        $queryLower = mb_strtolower($query);
        $keywords = array_filter(explode(' ', $queryLower), fn($w) => mb_strlen($w) > 3);

        foreach ($docs as $doc) {
            $content = $doc['extracted_content'];
            $contentLower = mb_strtolower($content);
            
            // Chunking: split content into chunks of ~500 characters
            $chunks = mb_split('\n\s*\n', $content) ?: [$content];
            foreach ($chunks as $index => $chunk) {
                $chunk = trim($chunk);
                if (mb_strlen($chunk) < 20) continue;

                $score = 0;
                $chunkLower = mb_strtolower($chunk);
                foreach ($keywords as $kw) {
                    if (str_contains($chunkLower, $kw)) {
                        $score++;
                    }
                }

                if ($score > 0 || count($keywords) === 0) {
                    $hasMatch = true;
                    $snippet = mb_substr($chunk, 0, 600);
                    $context += sprintf(
                        "[Kilde: %s | Afsnit %d]
%s

",
                        $doc['title'] ?? $doc['filename'],
                        $index + 1,
                        $snippet
                    );
                    break; // Take best matching chunk per doc
                }
            }
        }

        $context .= "--- SLUT INTERN VIDENSBASE ---\n
";
        return $hasMatch ? $context : '';
    }

    public static function logUsage(int $userId, ?int $botId, string $modelName, int $promptTokens, int $completionTokens): void {
        try {
            $db = Database::getConnection();
            $total = $promptTokens + $completionTokens;
            
            $costPer1kPrompt = 0.003; 
            $costPer1kCompletion = 0.015;
            if (str_contains(strtolower($modelName), 'gemini')) {
                $costPer1kPrompt = 0.001;
                $costPer1kCompletion = 0.002;
            }

            $cost = (($promptTokens / 1000) * $costPer1kPrompt) + (($completionTokens / 1000) * $costPer1kCompletion);

            $stmt = $db->prepare("
                INSERT INTO ai_usage_logs (user_id, bot_id, model_name, prompt_tokens, completion_tokens, total_tokens, estimated_cost)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $botId, $modelName, $promptTokens, $completionTokens, $total, $cost]);
        } catch (\Throwable $e) {
            // Silent fail
        }
    }
}
