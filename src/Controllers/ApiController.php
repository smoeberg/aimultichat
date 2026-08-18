<?php

declare(strict_types=1);

namespace Controllers;

use Models\User;
use Models\Bot;
use Models\Chat;
use Services\BotService;
use Services\GitHubService;
use Services\RateLimiter;
use Services\SettingsService;
use Core\Security;
use Core\Logger;

final class ApiController {
    private User $user; 
    private BotService $bots; 
    private GitHubService $github; 
    private RateLimiter $limiter;

    public function __construct(User $u){
        $this->user=$u;
        $this->bots=new BotService();
        $this->github=new GitHubService();
        $this->limiter=new RateLimiter();
    }

    public function list():void{
        // Only expose the public bot projection. Never serialize the model directly:
        // Bot contains encrypted provider credentials and has no toArray() contract.
        $this->json([
            'chats' => $this->user->getChats(),
            'bots' => array_map(
                static fn(Bot $bot): array => $bot->toPublicArray(),
                Bot::findAll(true)
            )
        ]);
    }

    public function loadChat(int $id):void{
        $chat=Chat::findById($id);
        if(!$chat||$chat->userId!==$this->user->id){
            $this->json(['error'=>'Chat ikke fundet'],404);
            return;
        }
        $this->json(['chat'=>['id'=>$chat->id,'title'=>$chat->getTitle()],'messages'=>$chat->getMessages()]);
    }

    public function newChat():void{
        try {
            Security::requireCsrfHeader();
            $id = $this->user->createChat();
            $_SESSION['cid'] = $id;
            $this->json(['id' => $id]);
        } catch (\Throwable $e) {
            Logger::error('New chat error', ['error' => $e->getMessage()]);
            $this->json(['error' => 'Kunne ikke oprette en ny chat: ' . $e->getMessage()], 400);
        }
    }

    public function sendMessage():void{
        try {
            Security::requireCsrfHeader();
            if(!$this->limiter->check((int)$this->user->id)){
                $this->json(['error'=>'For mange anmodninger. Vent et øjeblik.'],429);
                return;
            }

            $input=json_decode(file_get_contents('php://input'),true) ?: [];
            $message=trim((string)($input['message']??''));
            $botKey=trim((string)($input['bot']??''));
            $chatId=(int)($input['chat_id']??$_SESSION['cid']??0);
            $githubRepo=trim((string)($input['github_repo']??''));

            if($message===''||$botKey===''){
                $this->json(['error'=>'Besked og AI-bot er påkrævet.'],400);
                return;
            }

            $bot=Bot::findByKey($botKey);
            if(!$bot||!$bot->enabled||!$bot->isConfigured()){
                $this->json(['error'=>'Den valgte AI-bot er ikke tilgængelig.'],400);
                return;
            }

            $chat=Chat::findById($chatId);
            if(!$chat||$chat->userId!==$this->user->id){
                $this->json(['error'=>'Chat-session ikke fundet.'],404);
                return;
            }

            // Hent eksisterende beskeder før ny tilføjelse for at tjekke om dette er første brugerbesked
            $existingMessages = $chat->getMessages();
            $hasUserMessage = false;
            foreach ($existingMessages as $msg) {
                if (($msg['role'] ?? '') === 'user') {
                    $hasUserMessage = true;
                    break;
                }
            }

            // Hent GitHub lager-kontekst hvis et repo URL er angivet
            $githubContext = '';
            if ($githubRepo !== '') {
                try {
                    $githubToken = SettingsService::getSecret('github_token') ?? '';
                    $githubContext = $this->github->fetchRepositoryContext($githubRepo, $githubToken);
                } catch (\Throwable $e) {
                    Logger::error('GitHub fetch error', ['error' => $e->getMessage()]);
                }
            }

            $chat->addMessage('user', $message);

            // Auto-generér eller opdater titel ud fra den første besked hvis den hedder "Ny chat" / "New chat"
            $currentTitle = trim($chat->getTitle());
            if (!$hasUserMessage || $currentTitle === '' || $currentTitle === 'Ny chat' || $currentTitle === 'New chat') {
                $lines = explode("\n", $message);
                $firstLine = trim($lines[0] ?? $message);
                $newTitle = mb_substr($firstLine, 0, 45);
                if (mb_strlen($firstLine) > 45) {
                    $newTitle .= '...';
                }
                if ($newTitle !== '') {
                    $chat->updateTitle($newTitle);
                }
            }

            $messages = $chat->getMessages();

            // Inkluder GitHub lager indhold som system/kontekst
            if ($githubContext !== '') {
                array_unshift($messages, ['role' => 'system', 'content' => $githubContext]);
            }

            $reply = $this->bots->callBot($bot, $messages);
            $chat->addMessage('assistant', $reply);
            $this->json([
                'reply' => $reply,
                'chat_id' => $chat->id,
                'chat_title' => $chat->getTitle()
            ]);
        } catch (\Throwable $e) {
            Logger::error('API message error', ['error' => $e->getMessage()]);
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function json(array $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        exit;
    }
}
