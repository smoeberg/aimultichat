<?php
declare(strict_types=1);

namespace Services;

final class BotService
{
    public function __construct(private readonly HttpJsonClient $http = new HttpJsonClient())
    {
    }

    public function callBot(object|array $bot, array $messages, ?string $repositoryContext = null): string
    {
        $provider = strtolower((string)(is_object($bot) ? $bot->provider : ($bot['provider'] ?? 'openai')));
        $endpoint = (string)(is_object($bot) ? $bot->endpoint : ($bot['endpoint'] ?? ''));
        $model = (string)(is_object($bot) ? $bot->model : ($bot['model'] ?? ''));
        $systemPrompt = is_object($bot) ? ($bot->systemPrompt ?? null) : ($bot['system_prompt'] ?? null);
        $formatted = $this->formatMessagesForApi($messages, is_string($systemPrompt) ? $systemPrompt : null, $repositoryContext);

        if ($provider === 'gpai') {
            return $this->sendGpai($bot, $endpoint, $model, $formatted);
        }

        $apiKey = $this->apiKey($bot);
        if ($apiKey === '') {
            throw new ProviderException('Den valgte AI-bot mangler gyldige legitimationsoplysninger.', 400);
        }

        if (in_array($provider, ['claude', 'anthropic'], true)) {
            return $this->sendAnthropic($apiKey, $endpoint, $model, $formatted, $bot);
        }

        return $this->sendOpenAiCompatible($apiKey, $endpoint, $provider, $model, $formatted);
    }

    public function formatMessagesForApi(
        array $rawMessages,
        ?string $systemPrompt = null,
        ?string $repositoryContext = null
    ): array {
        $formatted = [];
        if ($systemPrompt !== null && trim($systemPrompt) !== '') {
            $formatted[] = ['role' => 'system', 'content' => trim($systemPrompt)];
        }
        if ($repositoryContext !== null && trim($repositoryContext) !== '') {
            $formatted[] = [
                'role' => 'system',
                'content' => "Godkendt repository-kontekst:\n\n" . $repositoryContext,
            ];
        }

        foreach ($rawMessages as $message) {
            $data = is_object($message) ? get_object_vars($message) : (array)$message;
            $role = strtolower((string)($data['role'] ?? 'user'));
            if (in_array($role, ['bot', 'model'], true)) {
                $role = 'assistant';
            }
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $content = $this->extractText($data['content'] ?? '');
            if ($content !== '') {
                $formatted[] = ['role' => $role, 'content' => $content];
            }
        }

        return $formatted;
    }

    public function extractText(mixed $content): string
    {
        if (is_scalar($content)) {
            return trim((string)$content);
        }
        if (!is_array($content)) {
            return '';
        }
        if (isset($content['text']) && is_scalar($content['text'])) {
            return trim((string)$content['text']);
        }
        if (array_key_exists('content', $content)) {
            return $this->extractText($content['content']);
        }

        $parts = [];
        foreach ($content as $block) {
            $text = $this->extractText($block);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return trim(implode("\n", $parts));
    }

    private function sendOpenAiCompatible(
        string $apiKey,
        string $endpoint,
        string $provider,
        string $model,
        array $messages
    ): string {
        $response = $this->http->post(
            $endpoint,
            $provider,
            ['Authorization: Bearer ' . $apiKey],
            ['model' => $model, 'messages' => $messages]
        );
        $decoded = $this->decodeResponse($response['body']);
        $text = $this->extractText($decoded['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            throw new ProviderException('AI-provideren returnerede et tomt eller ukendt svarformat.');
        }
        return $this->validateReply($text);
    }

    private function sendAnthropic(
        string $apiKey,
        string $endpoint,
        string $model,
        array $messages,
        object|array $bot
    ): string {
        $system = [];
        $conversation = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $system[] = (string)$message['content'];
            } else {
                $role = ($message['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
                $content = (string)($message['content'] ?? '');
                if ($conversation === [] && $role === 'assistant') {
                    continue;
                }
                $lastIndex = count($conversation) - 1;
                if ($lastIndex >= 0 && $conversation[$lastIndex]['role'] === $role) {
                    $conversation[$lastIndex]['content'] .= "\n\n" . $content;
                } else {
                    $conversation[] = ['role' => $role, 'content' => $content];
                }
            }
        }

        $config = $this->config($bot);
        $payload = [
            'model' => $model,
            'max_tokens' => max(1, min(8192, (int)($config['max_tokens'] ?? 2048))),
            'messages' => $conversation,
        ];
        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        $response = $this->http->post(
            $endpoint,
            'anthropic',
            ['x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
            $payload
        );
        $decoded = $this->decodeResponse($response['body']);
        $text = $this->extractText($decoded['content'] ?? '');
        if ($text === '') {
            throw new ProviderException('Anthropic returnerede et tomt eller ukendt svarformat.');
        }
        return $this->validateReply($text);
    }

    private function sendGpai(object|array $bot, string $endpoint, string $model, array $messages): string
    {
        $config = $this->config($bot);
        $username = trim((string)($config['username'] ?? ''));
        $password = trim((string)($config['password'] ?? ''));
        if ($password === '') {
            $password = $this->apiKey($bot);
        }
        if ($password === '') {
            throw new ProviderException('GPAI-botten mangler gyldige legitimationsoplysninger.', 400);
        }

        $headers = [];
        if ($username !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
        } else {
            $headers[] = 'Authorization: Bearer ' . $password;
        }

        $response = $this->http->post(
            $endpoint,
            'gpai',
            $headers,
            ['model' => $model, 'messages' => $messages]
        );
        $decoded = $this->decodeResponse($response['body']);
        $text = $this->extractText($decoded['choices'][0]['message']['content'] ?? ($decoded['content'] ?? ''));
        if ($text === '') {
            throw new ProviderException('GPAI returnerede et tomt eller ukendt svarformat.');
        }
        return $this->validateReply($text);
    }

    private function apiKey(object|array $bot): string
    {
        if (is_object($bot) && method_exists($bot, 'getDecryptedApiKey')) {
            return trim((string)($bot->getDecryptedApiKey() ?? ''));
        }
        return trim((string)($bot['api_key'] ?? $bot['apikey'] ?? ''));
    }

    private function config(object|array $bot): array
    {
        $raw = is_object($bot) && method_exists($bot, 'getDecryptedConfig')
            ? $bot->getDecryptedConfig()
            : ($bot['config_json'] ?? null);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            throw new ProviderException('Bot-konfigurationen indeholder ugyldig JSON.', 400);
        }
    }

    private function decodeResponse(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ProviderException('AI-provideren returnerede ugyldig JSON.', 502, null, $exception);
        }
        if (!is_array($decoded)) {
            throw new ProviderException('AI-provideren returnerede et ukendt svarformat.');
        }
        return $decoded;
    }

    private function validateReply(string $text): string
    {
        $maxChars = max(1, (int)(\configValue('MAX_RESPONSE_CHARS', '50000') ?? 50000));
        if (mb_strlen($text) > $maxChars) {
            throw new ProviderException('AI-providerens svar overskred den tilladte tekstlængde.', 502);
        }
        return $text;
    }
}
