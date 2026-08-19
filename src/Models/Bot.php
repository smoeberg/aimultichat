<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Security;
use PDO;
use RuntimeException;
use Services\HttpJsonClient;

final class Bot
{
    public int $id = 0;
    public string $botKey = '';
    public string $name = '';
    public string $provider = 'openai';
    public string $endpoint = '';
    public string $model = '';
    public ?string $apiKeyEncrypted = null;
    public ?string $configJsonEncrypted = null;
    public ?string $systemPrompt = null;
    public bool $enabled = true;

    private ?string $keyStatus = null;
    private ?string $decryptedApiKey = null;
    private ?string $decryptedConfig = null;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = (int)($data['id'] ?? 0);
            $this->botKey = (string)($data['bot_key'] ?? '');
            $this->name = (string)($data['name'] ?? '');
            $this->provider = strtolower((string)($data['provider'] ?? 'openai'));
            $this->endpoint = (string)($data['endpoint'] ?? '');
            $this->model = (string)($data['model'] ?? '');

            // Konverter eventuelle PDO BLOB streams til strenge
            $this->apiKeyEncrypted = self::castToString($data['api_key'] ?? null);
            $this->configJsonEncrypted = self::castToString($data['config_json'] ?? null);
            
            $this->systemPrompt = self::castToString($data['system_prompt'] ?? null);
            $this->enabled = !empty($data['enabled']);
        }
    }

    private static function castToString(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        if (is_resource($val)) {
            return stream_get_contents($val);
        }
        return (string)$val;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'bot_key' => $this->botKey,
            'name' => $this->name,
            'provider' => $this->provider,
            'model' => $this->model,
            'enabled' => $this->enabled,
            'is_configured' => $this->isConfigured(),
        ];
    }

    public static function findAll(bool $onlyEnabled = false): array
    {
        $db = Database::getInstance();
        $sql = 'SELECT * FROM bots';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bots = [];
        foreach ($rows as $row) {
            $bots[] = new self($row);
        }

        return $bots;
    }

    public static function findByKey(string $botKey): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM bots WHERE bot_key = ? LIMIT 1');
        $stmt->execute([$botKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new self($row) : null;
    }

    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM bots WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new self($row) : null;
    }

    public static function createOrUpdate(array $data): self
    {
        $db = Database::getInstance();
        $botKey = trim((string)($data['bot_key'] ?? ''));

        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', $botKey)) {
            throw new RuntimeException('Bot-nøglen har et ugyldigt format.');
        }

        $existing = self::findByKey($botKey);

        $apiKeyEncrypted = $existing ? $existing->apiKeyEncrypted : null;
        if (!empty($data['api_key'])) {
            $rawApiKey = (string)$data['api_key'];
            if (strlen($rawApiKey) > 10000 || str_contains($rawApiKey, "\r") || str_contains($rawApiKey, "\n")) {
                throw new RuntimeException('Provider-nøglen indeholder ugyldige tegn eller er for lang.');
            }
            $apiKeyEncrypted = Security::encrypt($rawApiKey);
        }

        $configEncrypted = $existing ? $existing->configJsonEncrypted : null;
        if (array_key_exists('config_json', $data)) {
            $rawConfig = (string)($data['config_json'] ?? '');
            if ($rawConfig !== '') {
                if (strlen($rawConfig) > 20000) {
                    throw new RuntimeException('Provider-konfigurationen er for lang.');
                }
                try {
                    json_decode($rawConfig, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new RuntimeException('Provider-konfigurationen indeholder ugyldig JSON.', 0, $exception);
                }
                $configEncrypted = Security::encrypt($rawConfig);
            } else {
                $configEncrypted = null;
            }
        }

        $name = trim((string)($data['name'] ?? ''));
        $provider = strtolower(trim((string)($data['provider'] ?? 'openai')));
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        $model = trim((string)($data['model'] ?? ''));
        $systemPrompt = trim((string)($data['system_prompt'] ?? ''));
        $enabled = !empty($data['enabled']) ? 1 : 0;

        $allowedProviders = ['openai', 'claude', 'anthropic', 'mistral', 'gemini', 'deepseek', 'rool', 'gpai', 'librechat'];
        if (!in_array($provider, $allowedProviders, true)) {
            throw new RuntimeException('Ukendt AI-provider.');
        }
        if ($name === '' || mb_strlen($name) > 100 || $model === '' || mb_strlen($model) > 150) {
            throw new RuntimeException('Bot-navn eller model er ugyldig.');
        }
        if (mb_strlen($systemPrompt) > 10000) {
            throw new RuntimeException('System-prompten er for lang.');
        }
        try {
            $endpoint = HttpJsonClient::validateEndpoint($endpoint, $provider);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        if ($existing) {
            $stmt = $db->prepare('
                UPDATE bots 
                SET name = ?, provider = ?, endpoint = ?, model = ?, api_key = ?, config_json = ?, system_prompt = ?, enabled = ? 
                WHERE bot_key = ?
            ');
            $stmt->execute([$name, $provider, $endpoint, $model, $apiKeyEncrypted, $configEncrypted, $systemPrompt, $enabled, $botKey]);
        } else {
            $stmt = $db->prepare('
                INSERT INTO bots (bot_key, name, provider, endpoint, model, api_key, config_json, system_prompt, enabled) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$botKey, $name, $provider, $endpoint, $model, $apiKeyEncrypted, $configEncrypted, $systemPrompt, $enabled]);
        }

        $bot = self::findByKey($botKey);
        if (!$bot) {
            throw new RuntimeException('Kunne ikke hente bot efter oprettelse/opdatering.');
        }

        return $bot;
    }

    public function getKeyStatus(): string
    {
        if ($this->keyStatus !== null) {
            return $this->keyStatus;
        }

        $candidates = $this->provider === 'gpai'
            ? [$this->apiKeyEncrypted, $this->configJsonEncrypted]
            : [$this->apiKeyEncrypted];
        $candidates = array_values(array_filter(
            $candidates,
            static fn(?string $value): bool => $value !== null && $value !== ''
        ));
        if ($candidates === []) {
            $this->keyStatus = 'MISSING';
            return 'MISSING';
        }

        foreach ($candidates as $targetEncrypted) {
            $result = Security::decryptWithStatus($targetEncrypted);
            if ($result['data'] === null || trim($result['data']) === '') {
                continue;
            }
            $this->keyStatus = $result['status'];
            if ($result['status'] === 'MIGRATED') {
                $this->reencryptWithPrimaryKey();
            }
            return $this->keyStatus;
        }

        $this->keyStatus = 'UNREADABLE';
        return 'UNREADABLE';
    }

    public function reencryptWithPrimaryKey(): void
    {
        if ($this->id <= 0) {
            return;
        }

        $db = Database::getInstance();
        $updatedApiKey = $this->apiKeyEncrypted;
        $updatedConfig = $this->configJsonEncrypted;

        if (!empty($this->apiKeyEncrypted)) {
            $res = Security::decryptWithStatus($this->apiKeyEncrypted);
            if ($res['data'] !== null) {
                $updatedApiKey = Security::encrypt($res['data']);
                $this->decryptedApiKey = $res['data'];
            }
        }

        if (!empty($this->configJsonEncrypted)) {
            $res = Security::decryptWithStatus($this->configJsonEncrypted);
            if ($res['data'] !== null) {
                $updatedConfig = Security::encrypt($res['data']);
                $this->decryptedConfig = $res['data'];
            }
        }

        $stmt = $db->prepare('UPDATE bots SET api_key = ?, config_json = ? WHERE id = ?');
        $stmt->execute([$updatedApiKey, $updatedConfig, $this->id]);

        $this->apiKeyEncrypted = $updatedApiKey;
        $this->configJsonEncrypted = $updatedConfig;
    }

    public function isConfigured(): bool
    {
        $status = $this->getKeyStatus();
        if ($status !== 'VALID' && $status !== 'MIGRATED') {
            return false;
        }
        if ($this->provider !== 'gpai') {
            return trim((string)($this->getDecryptedApiKey() ?? '')) !== '';
        }

        $config = json_decode((string)($this->getDecryptedConfig() ?? ''), true);
        $password = is_array($config) ? trim((string)($config['password'] ?? '')) : '';
        return $password !== '' || trim((string)($this->getDecryptedApiKey() ?? '')) !== '';
    }

    public function getDecryptedApiKey(): ?string
    {
        if ($this->decryptedApiKey !== null) {
            return $this->decryptedApiKey;
        }

        if (empty($this->apiKeyEncrypted)) {
            return null;
        }

        $result = Security::decryptWithStatus($this->apiKeyEncrypted);
        if ($result['status'] === 'MIGRATED' && $result['data'] !== null) {
            $this->reencryptWithPrimaryKey();
        }

        $this->decryptedApiKey = $result['data'];
        return $this->decryptedApiKey;
    }

    public function getDecryptedConfig(): ?string
    {
        if ($this->decryptedConfig !== null) {
            return $this->decryptedConfig;
        }

        if (empty($this->configJsonEncrypted)) {
            return null;
        }

        $result = Security::decryptWithStatus($this->configJsonEncrypted);
        if ($result['status'] === 'MIGRATED' && $result['data'] !== null) {
            $this->reencryptWithPrimaryKey();
        }

        $this->decryptedConfig = $result['data'];
        return $this->decryptedConfig;
    }

}
