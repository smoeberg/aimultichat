<?php

declare(strict_types=1);

namespace Services;

final class DlpService {
    /**
     * Mask sensitive data (CPR numbers, emails, phone numbers, API keys) from text before sending to AI providers.
     */
    public static function maskSensitiveData(string $text): string {
        // CPR numbers: 6 digits - 4 digits (e.g. 010190-1234)
        $text = preg_replace('/\b\d{6}-\d{4}\b/', '[CPR-MASKED]', $text);
        
        // Credit cards: 16 digits with optional spaces or hyphens
        $text = preg_replace('/\b(?:\d[ -]*){13,16}\b/', '[CREDIT-CARD-MASKED]', $text);
        
        // Generic API keys or secrets (bearer tokens, sk-..., etc.)
        $text = preg_replace('/\b(sk-[a-zA-Z0-9]{20,}|bearer\s+[a-zA-Z0-9_\-\.]+)\b/i', '[SECRET-KEY-MASKED]', $text);

        return $text;
    }
}
