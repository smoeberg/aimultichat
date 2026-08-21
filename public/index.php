<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
startSecureSession();
use Controllers\AuthController;
use Controllers\ApiController;
use Models\User;
use Models\Chat;
use Models\Bot;
use Services\SettingsService;
use Core\Security;

if (isset($_POST['logout'])) {
    Security::requirePostCsrf();
    (new AuthController())->logout();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_username'])) {
    (new AuthController())->login();
}
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Ikke autoriseret']);
        exit;
    }
    $user = User::findById((int)$_SESSION['uid']);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Ikke autoriseret']);
        exit;
    }
    $api = new ApiController($user);
    $route = (string)$_GET['api'];
    switch ($route) {
        case 'list':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') { $api->list(); exit; }
            break;
        case 'load':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') { $api->loadChat((int)($_GET['id'] ?? 0)); exit; }
            break;
        case 'new':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { $api->newChat(); exit; }
            break;
        case 'send':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { $api->sendMessage(); exit; }
            break;
        case 'log-copy':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                require_once __DIR__ . '/../src/Http/Controllers/Api/CopyLogController.php';
                $controller = new Http\Controllers\Api\CopyLogController();
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
                $result = $controller->log($input, $user->id, $user->id);
                echo json_encode($result);
                exit;
            }
            break;
        case 'export-docx':
        case 'export-pdf':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                require_once __DIR__ . '/../src/Http/Controllers/Api/ExportController.php';
                $controller = new Http\Controllers\Api\ExportController();
                $input = $_POST;
                $format = str_replace('export-', '', $route);
                if ($format === 'docx') {
                    $controller->exportDocx($input, $user->id, $user->id);
                } else {
                    $controller->exportPdf($input, $user->id, $user->id);
                }
                exit;
            }
            break;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Ugyldigt API-endpoint']);
    exit;
}

if (empty($_SESSION['uid'])) {
    require __DIR__.'/login.php';
    exit;
}
$user = User::findById((int)$_SESSION['uid']);
if (!$user) {
    session_destroy();
    require __DIR__.'/login.php';
    exit;
}

$chatId = (int)($_SESSION['cid'] ?? 0);
$chat = $chatId ? Chat::findById($chatId) : null;
if (!$chat || $chat->userId !== $user->id) {
    $chats = $user->getChats();
    $chatId = !empty($chats) ? (int)$chats[0]['id'] : $user->createChat();
    $_SESSION['cid'] = $chatId;
}
$bots = Bot::findAll(true);
$defaultBotKey = SettingsService::get('default_bot', 'gpt-4o');
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>EiraMultiChat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=3">
</head>
<body>
    <div class="app">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-logo-container">
                    <img src="images/logoeira.svg" alt="Eira Logo" class="sidebar-logo">
                </div>
                <div class="sidebar-brand-text">
                    <span class="sidebar-title">EiraMultiChat</span>
                    <span class="sidebar-user"><?= htmlspecialchars($user->name ?: $user->username, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div class="sidebar-header">
                <h2>💬 Samtaler</h2>
                <form method="post" class="logout-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" name="logout" value="1" class="logout-link" title="Log ud">Log ud</button>
                </form>
            </div>

            <button id="newChatBtn" class="new-chat-btn" type="button">
                <span>+</span> Ny samtale
            </button>

            <?php if ($user->role === 'admin'): ?>
                <a href="admin.php" class="admin-link-btn">
                    <span>⚙️</span> Administration
                </a>
            <?php endif; ?>

            <div id="chatList"></div>

            <div class="sidebar-footer">
                <p>&copy; <?= date('Y') ?> EiraMultiChat</p>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="chat-topbar">
                <div class="topbar-info">
                    <img src="images/logoeira.svg" alt="Eira Logo" class="topbar-logo">
                    <div>
                        <h3 class="topbar-title">EiraMultiChat</h3>
                        <p class="topbar-status"><span class="status-dot"></span> Klar til dialog</p>
                    </div>
                </div>
            </header>

            <div id="messageContainer" class="message-container"></div>
            <div id="errorContainer" class="error-container"></div>
            
            <div id="loader" class="loader">
                <span class="spinner"></span> AI tænker...
            </div>

            <form id="messageForm" class="message-form">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                
                <div class="form-controls">
                    <div class="select-wrapper">
                        <select name="bot" id="botSelect" required>
                            <?php foreach ($bots as $bot): ?>
                                <option value="<?= htmlspecialchars($bot->botKey, ENT_QUOTES, 'UTF-8') ?>" <?= $bot->botKey === $defaultBotKey ? 'selected' : '' ?> <?= $bot->isConfigured() ? '' : 'disabled' ?>>
                                    🤖 <?= htmlspecialchars($bot->name, ENT_QUOTES, 'UTF-8') ?><?= $bot->isConfigured() ? '' : ' (ikke konfigureret)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="input-github-wrapper">
                        <input type="text" name="github_repo" id="githubRepo" placeholder="📦 GitHub Repo URL (f.eks. https://github.com/bruger/projekt)">
                    </div>

                    <div>
                        <button type="button" id="templateModalBtn" class="btn-secondary" style="background:#f3f4f6; border:1px solid #d1d5db; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:500; display:inline-flex; align-items:center; gap:6px; white-space:nowrap;">
                            💡 Skabelon-prompts
                        </button>
                    </div>
                </div>

                <div class="input-box-row">
                    <textarea name="message" maxlength="50000" placeholder="Stil et spørgsmål eller skriv din besked…" required rows="1"></textarea>
                    <button type="submit" class="btn-send" title="Send besked">
                        <span>Send</span>
                        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
                            <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
                        </svg>
                    </button>
                </div>
            </form>

            <footer class="main-footer">
                <p>EiraMultiChat &bull; Powered by Eira AI &bull; <?= date('Y') ?></p>
            </footer>
        </main>
    </div>

    <!-- Prompt Templates Modal -->
    <div id="templateModalOverlay" class="template-modal-overlay">
        <div class="template-modal">
            <div class="template-modal-header">
                <h3>💡 Skabelon-prompts fra Systemadministrator</h3>
                <button type="button" id="closeTemplateModal" class="template-modal-close">&times;</button>
            </div>
            <div class="template-modal-body">
                <input type="text" id="templateSearch" class="template-search-input" placeholder="Søg efter skabeloner (f.eks. jobannonce, kundeservice...)...">
                <div id="templateListContainer">
                    <!-- Populated via JS -->
                </div>
            </div>
        </div>
    </div>

    <script>
        window.MULTICHAT = {
            chatId: <?= json_encode($chatId) ?>,
            csrfToken: <?= json_encode($csrf) ?>,
            defaultBot: <?= json_encode($defaultBotKey) ?>,
            promptTemplates: <?= json_encode(array_map(static fn($t) => $t->toArray(), \Models\PromptTemplate::findAll())) ?>
        };
    </script>
    <script src="js/app.js" defer></script>
</body>
</html>
