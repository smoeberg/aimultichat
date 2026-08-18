<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Security;
use PDO;
use RuntimeException;

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
            $this->provider = (string)($data['provider'] ?? 'openai');
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
            'endpoint' => $this->endpoint,
            'model' => $this->model,
            'system_prompt' => $this->systemPrompt,
            'enabled' => $this->enabled,
            'is_configured' => $this->isConfigured(),
            'key_status' => $this->getKeyStatus(),
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

        if ($botKey === '') {
            throw new RuntimeException('Bot-nøgle (bot_key) må ikke være tom.');
        }

        $existing = self::findByKey($botKey);

        $apiKeyEncrypted = $existing ? $existing->apiKeyEncrypted : null;
        if (!empty($data['api_key'])) {
            $apiKeyEncrypted = Security::encrypt((string)$data['api_key']);
        }

        $configEncrypted = $existing ? $existing->configJsonEncrypted : null;
        if (array_key_exists('config_json', $data)) {
            $rawConfig = (string)($data['config_json'] ?? '');
            if ($rawConfig !== '') {
                $configEncrypted = Security::encrypt($rawConfig);
            } else {
                $configEncrypted = null;
            }
        }

        $name = trim((string)($data['name'] ?? ''));
        $provider = trim((string)($data['provider'] ?? 'openai'));
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        $model = trim((string)($data['model'] ?? ''));
        $systemPrompt = trim((string)($data['system_prompt'] ?? ''));
        $enabled = !empty($data['enabled']) ? 1 : 0;

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

        $targetEncrypted = $this->apiKeyEncrypted ?: $this->configJsonEncrypted;
        if (empty($targetEncrypted)) {
            $this->keyStatus = 'MISSING';
            return 'MISSING';
        }

        $result = Security::decryptWithStatus($targetEncrypted);
        $this->keyStatus = $result['status'];

        if ($result['status'] === 'MIGRATED' && $result['data'] !== null) {
            $this->reencryptWithPrimaryKey();
        }

        return $this->keyStatus;
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
        return $status === 'VALID' || $status === 'MIGRATED';
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

    public function __get(string $name): mixed
    {
        return match ($name) {
            'bot_key' => $this->botKey,
            'system_prompt' => $this->systemPrompt,
            'api_key' => $this->apiKeyEncrypted,
            'config_json' => $this->configJsonEncrypted,
            default => null,
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['bot_key', 'system_prompt', 'api_key', 'config_json'], true);
    }
}