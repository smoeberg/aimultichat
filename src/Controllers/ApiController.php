<?php
declare(strict_types=1);

namespace Controllers;

use Core\Database;
use Core\Logger;
use Core\Security;
use Models\Bot;
use Models\Chat;
use Models\User;
use Services\BotService;
use Services\GitHubService;
use Services\ProviderException;
use Services\RateLimiter;
use Services\SettingsService;

final class ApiController
{
    private BotService $bots;
    private GitHubService $github;
    private RateLimiter $limiter;

    public function __construct(private readonly User $user)
    {
        $this->bots = new BotService();
        $this->github = new GitHubService();
        $this->limiter = new RateLimiter(
            'chat',
            max(1, (int)(\configValue('RATE_LIMIT_MAX_REQUESTS', '20') ?? 20)),
            max(1, (int)(\configValue('RATE_LIMIT_WINDOW', '60') ?? 60))
        );
    }

    public function list(): void
    {
        try {
            $this->json([
                'chats' => $this->user->getChats(),
                'bots' => array_map(
                    static fn(Bot $bot): array => $bot->toPublicArray(),
                    Bot::findAll(true)
                ),
            ]);
        } catch (\Throwable $exception) {
            $this->handleException($exception, 'list_chats');
        }
    }

    public function loadChat(int $id): void
    {
        try {
            $chat = Chat::findById($id);
            if ($chat === null || $chat->userId !== $this->user->id) {
                $this->json(['error' => 'Chat ikke fundet'], 404);
            }
            $historyLimit = max(1, min(100, (int)(\configValue('MAX_HISTORY_MESSAGES', '40') ?? 40)));
            $this->json([
                'chat' => ['id' => $chat->id, 'title' => $chat->getTitle()],
                'messages' => $chat->getMessages($historyLimit),
            ]);
        } catch (\Throwable $exception) {
            $this->handleException($exception, 'load_chat');
        }
    }

    public function newChat(): void
    {
        try {
            Security::requireCsrfHeader();
            $id = $this->user->createChat();
            $_SESSION['cid'] = $id;
            $this->json(['id' => $id]);
        } catch (\Throwable $exception) {
            $this->handleException($exception, 'new_chat');
        }
    }

    public function deleteChat(): void
    {
        $chatLock = null;
        try {
            Security::requireCsrfHeader();
            $input = $this->jsonInput();
            $chat = Chat::findById((int)($input['chat_id'] ?? 0));
            if ($chat === null || $chat->userId !== $this->user->id) {
                $this->json(['error' => 'Chat ikke fundet'], 404);
            }
            $chatLock = $this->acquireChatLock($chat->id);
            $chat->delete();
            $this->releaseChatLock($chatLock);
            $chatLock = null;
            if ((int)($_SESSION['cid'] ?? 0) === $chat->id) {
                unset($_SESSION['cid']);
            }
            $this->json(['deleted' => true]);
        } catch (\Throwable $exception) {
            if ($chatLock !== null) {
                $this->releaseChatLock($chatLock);
            }
            $this->handleException($exception, 'delete_chat');
        }
    }

    public function sendMessage(): void
    {
        $chatLock = null;
        try {
            Security::requireCsrfHeader();
            $input = $this->jsonInput();
            $message = trim((string)($input['message'] ?? ''));
            $botKey = trim((string)($input['bot'] ?? ''));
            $chatId = (int)($input['chat_id'] ?? $_SESSION['cid'] ?? 0);
            $githubRepo = trim((string)($input['github_repo'] ?? ''));

            $maxMessageChars = max(1, (int)(\configValue('MAX_MESSAGE_CHARS', '20000') ?? 20000));
            if ($message === '' || $botKey === '') {
                throw new \InvalidArgumentException('Besked og AI-bot er påkrævet.');
            }
            if (mb_strlen($message) > $maxMessageChars) {
                throw new \InvalidArgumentException("Beskeden må højst være {$maxMessageChars} tegn.");
            }

            $bot = Bot::findByKey($botKey);
            if ($bot === null || !$bot->enabled || !$bot->isConfigured()) {
                throw new \InvalidArgumentException('Den valgte AI-bot er ikke tilgængelig.');
            }
            $chat = Chat::findById($chatId);
            if ($chat === null || $chat->userId !== $this->user->id) {
                $this->json(['error' => 'Chat-session ikke fundet.'], 404);
            }
            if (!$this->limiter->check($this->user->id)) {
                $this->json(['error' => 'For mange anmodninger. Vent et øjeblik.'], 429);
            }

            $chatLock = $this->acquireChatLock($chat->id);
            $historyLimit = max(1, min(100, (int)(\configValue('MAX_HISTORY_MESSAGES', '40') ?? 40)));
            $messages = $this->limitHistory($chat->getMessages($historyLimit));
            $hasUserMessage = false;
            foreach ($messages as $item) {
                if (($item['role'] ?? '') === 'user') {
                    $hasUserMessage = true;
                    break;
                }
            }

            $repositoryContext = null;
            if ($githubRepo !== '') {
                $allowedRepositories = SettingsService::getList('github_allowed_repositories');
                $repositoryContext = $this->github->fetchRepositoryContext(
                    $githubRepo,
                    SettingsService::getSecret('github_token') ?? '',
                    $allowedRepositories
                );
            }

            $messages[] = ['role' => 'user', 'content' => $message];
            $reply = $this->bots->callBot($bot, $messages, $repositoryContext);
            $newTitle = null;
            $currentTitle = trim($chat->getTitle());
            if (!$hasUserMessage || in_array($currentTitle, ['', 'Ny chat', 'New chat'], true)) {
                $firstLine = trim(explode("\n", $message)[0] ?? $message);
                $newTitle = mb_substr($firstLine, 0, 45);
                if (mb_strlen($firstLine) > 45) {
                    $newTitle .= '...';
                }
            }

            $chat->addExchange($message, $reply, $bot->id, $newTitle);
            $this->releaseChatLock($chatLock);
            $chatLock = null;
            $this->json([
                'reply' => $reply,
                'chat_id' => $chat->id,
                'chat_title' => $chat->getTitle(),
            ]);
        } catch (\Throwable $exception) {
            if ($chatLock !== null) {
                $this->releaseChatLock($chatLock);
                $chatLock = null;
            }
            $this->handleException($exception, 'send_message');
        }
    }

    private function jsonInput(): array
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($contentType, 'application/json')) {
            throw new \InvalidArgumentException('API-requesten skal være JSON.');
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 250000) {
            throw new \InvalidArgumentException('API-requesten er tom eller for stor.');
        }
        try {
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('API-requesten indeholder ugyldig JSON.', 0, $exception);
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException('API-requesten skal være et JSON-objekt.');
        }
        return $input;
    }

    private function acquireChatLock(int $chatId): string
    {
        $name = 'multichat:chat:' . $chatId;
        $statement = Database::getInstance()->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$name]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new \RuntimeException('Chatten behandler allerede en anden besked. Prøv igen.', 409);
        }
        return $name;
    }

    private function releaseChatLock(string $name): void
    {
        try {
            $statement = Database::getInstance()->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);
        } catch (\Throwable $exception) {
            Logger::warning('Could not release chat lock', ['type' => get_class($exception)]);
        }
    }

    private function limitHistory(array $messages): array
    {
        $maxChars = max(1, (int)(\configValue('MAX_HISTORY_CHARS', '100000') ?? 100000));
        $selected = [];
        $used = 0;
        foreach (array_reverse($messages) as $message) {
            $content = (string)($message['content'] ?? '');
            $remaining = $maxChars - $used;
            if ($remaining <= 0) {
                break;
            }
            if (mb_strlen($content) > $remaining) {
                if ($selected !== []) {
                    break;
                }
                $message['content'] = mb_substr($content, 0, $remaining);
            }
            $used += mb_strlen((string)($message['content'] ?? ''));
            $selected[] = $message;
        }
        return array_reverse($selected);
    }

    private function handleException(\Throwable $exception, string $operation): never
    {
        $requestId = bin2hex(random_bytes(8));
        Logger::error('API operation failed', [
            'request_id' => $requestId,
            'operation' => $operation,
            'type' => get_class($exception),
        ]);

        if ($exception instanceof \InvalidArgumentException) {
            $this->json(['error' => $exception->getMessage()], 400);
        }
        if ($exception instanceof ProviderException) {
            $this->json(['error' => $exception->getMessage(), 'request_id' => $requestId], $exception->httpStatus);
        }
        if ($exception instanceof \RuntimeException && in_array($exception->getCode(), [403, 404, 405, 409, 502], true)) {
            $this->json(['error' => $exception->getMessage()], (int)$exception->getCode());
        }
        $this->json(['error' => 'Intern serverfejl.', 'request_id' => $requestId], 500);
    }

    private function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        exit;
    }
}
