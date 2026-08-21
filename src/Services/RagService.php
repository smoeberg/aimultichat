<?php

declare(strict_types=1);

namespace Services;

use Core\Database;

final class RagService {
    
    public static function searchRelevantContext(string $query, int $userRoleId): string {
        $db = Database::getConnection();
        
        // Find documents accessible by user's role (where min_role_id <= userRoleId)
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

        $context = "\n\n--- START INTERN VIDENSBASE (RAG KILDER) ---\n";
        $hasMatch = false;

        $queryLower = mb_strtolower($query);
        $keywords = array_filter(explode(' ', $queryLower), fn($w) => mb_strlen($w) > 3);

        foreach ($docs as $doc) {
            $contentLower = mb_strtolower($doc['extracted_content']);
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($contentLower, $kw)) {
                    $score++;
                }
            }

            // If keywords match or we just include recent docs if query is short
            if ($score > 0 || count($keywords) === 0) {
                $hasMatch = true;
                $snippet = mb_substr($doc['extracted_content'], 0, 1000);
                $context .= sprintf(
                    "[Kilde ID: %d | Titel: %s | Fil: %s]\n%s...\n\n",
                    $doc['id'],
                    $doc['title'],
                    $doc['filename'],
                    $snippet
                );
            }
        }

        $context .= "--- SLUT INTERN VIDENSBASE ---\n\n";
        return $hasMatch ? $context : '';
    }

    public static function logUsage(int $userId, ?int $botId, string $modelName, int $promptTokens, int $completionTokens): void {
        try {
            $db = Database::getConnection();
            $total = $promptTokens + $completionTokens;
            
            // Cost calculation estimates per 1M tokens (e.g., Claude 3.5 Sonnet / GPT-4o / Gemini Pro)
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
            // Silent fail for logging to not block main chat flow
        }
    }
}
