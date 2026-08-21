<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use PDO;
use RuntimeException;

final class PromptTemplate
{
    public int $id = 0;
    public string $title = '';
    public string $category = 'Generel';
    public string $promptText = '';
    public string $targetRole = 'all';
    public int $usageCount = 0;
    public string $createdAt = '';
    public string $updatedAt = '';

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->id = (int)($data['id'] ?? 0);
            $this->title = (string)($data['title'] ?? '');
            $this->category = (string)($data['category'] ?? 'Generel');
            $this->promptText = (string)($data['prompt_text'] ?? '');
            $this->targetRole = (string)($data['target_role'] ?? 'all');
            $this->usageCount = (int)($data['usage_count'] ?? 0);
            $this->createdAt = (string)($data['created_at'] ?? '');
            $this->updatedAt = (string)($data['updated_at'] ?? '');
        }
    }

    public static function findAll(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query('SELECT * FROM prompt_templates ORDER BY usage_count DESC, category ASC, title ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $templates = [];
        foreach ($rows as $row) {
            $templates[] = new self($row);
        }
        return $templates;
    }

    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM prompt_templates WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new self($row) : null;
    }

    public static function create(array $data): self
    {
        $db = Database::getInstance();
        $title = trim((string)($data['title'] ?? ''));
        $category = trim((string)($data['category'] ?? 'Generel'));
        $promptText = trim((string)($data['prompt_text'] ?? ''));
        $targetRole = trim((string)($data['target_role'] ?? 'all'));

        if ($title === '') {
            throw new RuntimeException('Titel på skabelon-prompt skal udfyldes.');
        }
        if ($promptText === '') {
            throw new RuntimeException('Selve prompt-teksten må ikke være tom.');
        }

        $stmt = $db->prepare('INSERT INTO prompt_templates (title, category, prompt_text, target_role, usage_count) VALUES (?, ?, ?, ?, 0)');
        $stmt->execute([$title, $category, $promptText, $targetRole]);

        $id = (int)$db->lastInsertId();
        return self::findById($id);
    }

    public static function update(int $id, array $data): self
    {
        $db = Database::getInstance();
        $template = self::findById($id);
        if (!$template) {
            throw new RuntimeException('Skabelon-prompt ikke fundet.');
        }

        $title = trim((string)($data['title'] ?? $template->title));
        $category = trim((string)($data['category'] ?? $template->category));
        $promptText = trim((string)($data['prompt_text'] ?? $template->promptText));
        $targetRole = trim((string)($data['target_role'] ?? $template->targetRole));

        if ($title === '') {
            throw new RuntimeException('Titel på skabelon-prompt skal udfyldes.');
        }
        if ($promptText === '') {
            throw new RuntimeException('Selve prompt-teksten må ikke være tom.');
        }

        $stmt = $db->prepare('UPDATE prompt_templates SET title = ?, category = ?, prompt_text = ?, target_role = ? WHERE id = ?');
        $stmt->execute([$title, $category, $promptText, $targetRole, $id]);

        return self::findById($id);
    }

    public static function incrementUsage(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE prompt_templates SET usage_count = usage_count + 1 WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function delete(int $id): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('DELETE FROM prompt_templates WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'prompt_text' => $this->promptText,
            'target_role' => $this->targetRole,
            'usage_count' => $this->usageCount,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
