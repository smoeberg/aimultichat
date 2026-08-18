<?php

namespace Services;

use Core\Security;

class BotService 
{
    /**
     * Safe diagnostic: returns status only, never the API key.
     */
    public function getApiKeyStatus($bot): string
    {
        if (!is_object($bot) || !method_exists($bot, 'getDecryptedApiKey')) {
            return 'UNSUPPORTED';
        }

        try {
            $key = $bot->getDecryptedApiKey();
            $cfg = method_exists($bot, 'getDecryptedConfig') ? $bot->getDecryptedConfig() : null;
            return (($key !== null && trim((string)$key) !== '') || ($cfg !== null && trim((string)$cfg) !== ''))
                ? 'VALID'
                : 'MISSING_OR_UNREADABLE';
        } catch (\Throwable $e) {
            error_log('[BotService] API key diagnostic failed: ' . $e->getMessage());
            return 'UNREADABLE';
        }
    }

    public function callBot($bot, array $messages, ?string $localFolderContext = null)
    {
        $provider     = is_object($bot) ? $bot->provider : ($bot['provider'] ?? 'openai');
        $endpoint     = is_object($bot) ? $bot->endpoint : ($bot['endpoint'] ?? '');
        $model        = is_object($bot) ? $bot->model : ($bot['model'] ?? 'gpt-4o');
        $systemPrompt = is_object($bot) ? $bot->system_prompt : ($bot['system_prompt'] ?? null);

        $formattedMessages = $this->formatMessagesForApi($messages, $systemPrompt, $localFolderContext);

        if ($provider === 'gpai') {
            $rawConfig = is_object($bot) && method_exists($bot, 'getDecryptedConfig') ? $bot->getDecryptedConfig() : null;
            $config = json_decode($rawConfig ?? '{}', true);
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? (is_object($bot) && method_exists($bot, 'getDecryptedApiKey') ? $bot->getDecryptedApiKey() : '');

            if (empty($endpoint)) {
                $endpoint = 'https://api.gpai.dk/v1/chat/completions';
            }

            return $this->sendGpai($username, $password, $endpoint, $model, $formattedMessages);
        }

        $rawApiKey = '';
        if (is_object($bot) && method_exists($bot, 'getDecryptedApiKey')) {
            $rawApiKey = (string)($bot->getDecryptedApiKey() ?? '');
        } elseif (is_array($bot)) {
            $rawApiKey = (string)($bot['api_key'] ?? $bot['apikey'] ?? '');
        }

        $apiKey = trim($rawApiKey);
        if (empty($apiKey)) {
            throw new \Exception("API-nøglen for denne bot mangler eller er tom i databasen.");
        }

        if (empty($endpoint)) {
            $endpoint = ($provider === 'mistral' || str_contains($model, 'mistral')) 
                ? 'https://api.mistral.ai/v1/chat/completions' 
                : 'https://api.openai.com/v1/chat/completions';
        }

        if ($provider === 'mistral' || str_contains($endpoint, 'mistral.ai')) {
            return $this->sendToMistral($apiKey, $endpoint, $model, $formattedMessages);
        }

        return $this->sendOpenAICompatible($apiKey, $endpoint, $model, $formattedMessages);
    }

    public function formatMessagesForApi(array $rawMessages, ?string $systemPrompt = null, ?string $localFolderContext = null): array 
    {
        $formatted = [];

        if (!empty($systemPrompt)) {
            $formatted[] = [
                'role' => 'system',
                'content' => $systemPrompt
            ];
        }

        if (!empty($localFolderContext)) {
            $formatted[] = [
                'role' => 'system',
                'content' => "Følgende filkontekst fra valgt lokal mappe/repository er tilgængelig:\n\n" . $localFolderContext
            ];
        }

        foreach ($rawMessages as $msg) {
            $msgArr = is_object($msg) ? (array)$msg : (array)$msg;
            $role   = $msgArr['role'] ?? 'user';

            if ($role === 'bot' || $role === 'model') {
                $role = 'assistant';
            }

            $formatted[] = [
                'role'    => $role,
                'content' => (string) ($msgArr['content'] ?? '')
            ];
        }

        return $formatted;
    }

    public function sendOpenAICompatible(string $apiKey, string $endpoint, string $model, array $formattedMessages) 
    {
        $body = json_encode([
            'model'    => $model,
            'messages' => $formattedMessages
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($apiKey)
        ]);

        $response = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errStr   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new \Exception("cURL Forbindelsesfejl (nr. {$errNo}): {$errStr} ved endpoint: {$endpoint}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("AI API fejl (HTTP {$httpCode}): " . $response);
        }

        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }

    public function sendToMistral(string $apiKey, string $endpoint, string $model, array $formattedMessages) 
    {
        $body = json_encode([
            'model'    => $model ?: 'mistral-small-latest',
            'messages' => $formattedMessages
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim($apiKey)
        ]);

        $response = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errStr   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new \Exception("Mistral cURL fejl (nr. {$errNo}): {$errStr}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("Mistral API fejl (HTTP {$httpCode}): " . $response);
        }

        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }

    public function sendGpai(string $username, string $password, string $endpoint, string $model, array $formattedMessages)
    {
        $body = json_encode([
            'model'    => $model ?: 'gpai-v1',
            'messages' => $formattedMessages
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        
        $headers = ['Content-Type: application/json'];
        
        if (!empty($username) && !empty($password)) {
            // Hvis GPAI bruger Basic Auth
            $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
        } elseif (!empty($password)) {
            $headers[] = 'Authorization: Bearer ' . trim($password);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $errNo    = curl_errno($ch);
        $errStr   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new \Exception("GPAI cURL Forbindelsesfejl (nr. {$errNo}): {$errStr} ved endpoint: {$endpoint}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("GPAI API fejl (HTTP {$httpCode}): " . $response);
        }

        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }
}
