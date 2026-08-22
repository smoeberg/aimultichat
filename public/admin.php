<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
startSecureSession();

use Models\User;
use Models\Bot;
use Core\Security;
use Core\Database;
use Services\SettingsService;

if (empty($_SESSION['uid'])) {
    header('Location: index.php');
    exit;
}

$user = User::findById((int)$_SESSION['uid']);
if (!$user || $user->role !== 'admin') {
    http_response_code(403);
    exit('Adgang nægtet. Kun administratorer har adgang til denne side.');
}

$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'bots';

// Handlinger for Brugere og Bots
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Ugyldig forespørgsel (CSRF).');
        }

        $action = $_POST['action'] ?? '';

        // --- REQUEST VERIFICATION ---
        if (isset($_GET['action']) && $_GET['action'] === 'verify-request') {
            require_once __DIR__ . '/../src/Http/Controllers/Admin/RequestVerificationController.php';
            $controller = new Http\Controllers\Admin\RequestVerificationController();
            $requestId = $_GET['requestId'] ?? '';
            if ($requestId) {
                header('Content-Type: application/json');
                echo json_encode($controller->lookup($requestId, $user->id, $user->id));
                exit;
            }
            echo $controller->showForm();
            exit;
        }

        // --- BRUGER HÅNDTERING ---
        if ($action === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

            if ($username === '' || $name === '' || strlen($password) < 6) {
                throw new RuntimeException('Brugernavn, Navn og en adgangskode på mindst 6 tegn er påkrævet.');
            }

            if (User::findByUsername($username)) {
                throw new RuntimeException('Brugernavnet optaget. Vælg venligst et andet.');
            }

            $hash = Security::hashPassword($password);
            $db = Database::getInstance();
            $stmt = $db->prepare('INSERT INTO users (name, username, password_hash, role, enabled) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$name, $username, $hash, $role]);

            $success = "Brugeren '$username' blev oprettet succesfuldt!";
            $activeTab = 'users';
        } 
        elseif ($action === 'update_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $u = User::findById($userId);
            if (!$u) throw new RuntimeException('Bruger blev ikke fundet.');

            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $newPassword = (string)($_POST['new_password'] ?? '');

            $db = Database::getInstance();
            if (strlen($newPassword) >= 6) {
                $hash = Security::hashPassword($newPassword);
                $stmt = $db->prepare('UPDATE users SET role = ?, enabled = ?, password_hash = ? WHERE id = ?');
                $stmt->execute([$role, $enabled, $hash, $userId]);
                $success = "Bruger '{$u->username}' opdateret inkl. ny adgangskode!";
            } else {
                $stmt = $db->prepare('UPDATE users SET role = ?, enabled = ? WHERE id = ?');
                $stmt->execute([$role, $enabled, $userId]);
                $success = "Bruger '{$u->username}' opdateret!";
            }
            $activeTab = 'users';
        }

        // --- GITHUB FORBINDELSE ---
        elseif ($action === 'save_github') {
            $githubUser = trim((string)($_POST['github_username'] ?? ''));
            $githubToken = trim((string)($_POST['github_token'] ?? ''));
            $clearToken = isset($_POST['clear_github_token']);
            if ($githubUser === '') {
                throw new RuntimeException('Indtast den GitHub-bruger/ejer, som forbindelsen hører til.');
            }
            SettingsService::put('github_username', $githubUser);
            if ($clearToken) {
                SettingsService::put('github_token', '');
            } elseif ($githubToken !== '') {
                SettingsService::put('github_token', $githubToken, true);
            }
            $success = 'GitHub-forbindelsen blev gemt. Repository-adgang bruger den gemte GitHub-token.';
            $activeTab = 'github';
        }

        // --- STANDARD MODEL / DEFAULT BOT ---
        elseif ($action === 'save_default_bot') {
            $defaultBotKey = trim((string)($_POST['default_bot'] ?? ''));
            SettingsService::put('default_bot', $defaultBotKey);
            $success = 'Standard AI-model for brugere blev opdateret!';
            $activeTab = 'bots';
        }

        // --- SKABELON-PROMPTS OG TONE OF VOICE HÅNDTERING ---
        elseif ($action === 'create_template') {
            \Models\PromptTemplate::create($_POST);
            $success = 'Skabelon-prompt blev oprettet!';
            $activeTab = 'templates';
        }
        elseif ($action === 'update_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            \Models\PromptTemplate::update($templateId, $_POST);
            $success = 'Skabelon-prompt blev opdateret!';
            $activeTab = 'templates';
        }
        elseif ($action === 'delete_template') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            \Models\PromptTemplate::delete($templateId);
            $success = 'Skabelon-prompt blev slettet!';
            $activeTab = 'templates';
        }
        elseif ($action === 'save_tone_of_voice') {
            $tone = trim((string)($_POST['company_tone_of_voice'] ?? ''));
            SettingsService::put('company_tone_of_voice', $tone);
            $success = 'Virksomhedens Tone of Voice blev opdateret!';
            $activeTab = 'templates';
        }

        // --- BOT HÅNDTERING ---
        elseif ($action === 'save_bot') {
            $botKey = trim((string)($_POST['bot_key'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $provider = trim((string)($_POST['provider'] ?? 'openai'));
            $endpoint = trim((string)($_POST['endpoint'] ?? 'https://api.openai.com/v1/chat/completions'));
            $model = trim((string)($_POST['model'] ?? ''));
            $apiKey = trim((string)($_POST['api_key'] ?? ''));
            $systemPrompt = trim((string)($_POST['system_prompt'] ?? 'En hjælpsom assistent'));
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($botKey === '' || $name === '' || $model === '' || $endpoint === '') {
                throw new RuntimeException('Udfyld venligst alle påkrævede felter.');
            }

            $data = [
                'bot_key' => $botKey,
                'name' => $name,
                'provider' => $provider,
                'endpoint' => $endpoint,
                'model' => $model,
                'system_prompt' => $systemPrompt,
                'enabled' => $enabled
            ];

            if ($provider === 'gpai') {
                $gpaiUser = trim((string)($_POST['gpai_username'] ?? ''));
                $gpaiPass = (string)($_POST['gpai_password'] ?? '');

                if ($gpaiUser !== '' || $gpaiPass !== '') {
                    $configObj = ['username' => $gpaiUser, 'password' => $gpaiPass];
                    $data['config_json'] = json_encode($configObj);
                    if ($gpaiPass !== '') {
                        $data['api_key'] = $gpaiPass;
                    }
                }
            } else {
                if ($apiKey !== '') {
                    $data['api_key'] = $apiKey;
                }
            }

            Bot::createOrUpdate($data);
            $success = "Bot-konfigurationen for '$name' blev gemt succesfuldt!";
            $activeTab = 'bots';
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// Hent brugere & bots
$db = Database::getInstance();
$usersStmt = $db->query('SELECT id, name, username, role, enabled, created_at FROM users ORDER BY id DESC');
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
$bots = Bot::findAll(false);
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Multi-Chat – Admin Panel</title>
<link rel="stylesheet" href="css/style.css">
<style>
html, body {
  height: auto !important;
  min-height: 100% !important;
  margin: 0;
  padding: 0;
  overflow-y: auto !important; /* Tvinger fuld scrollbar i browseren */
  background: #f4f6f8;
  font-family: system-ui, -apple-system, sans-serif;
}
.admin-wrapper {
  min-height: 100vh;
  padding: 30px 15px;
  box-sizing: border-box;
}
.admin-container { 
  max-width: 950px; 
  margin: 0 auto; 
  padding: 25px; 
  background: #fff; 
  border-radius: 8px; 
  box-shadow: 0 2px 10px rgba(0,0,0,0.08); 
}
.admin-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
.nav-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid #ddd; }
.nav-tab { padding: 10px 20px; text-decoration: none; color: #555; font-weight: 600; border-radius: 6px 6px 0 0; background: #f0f0f0; }
.nav-tab.active { background: #0070f3; color: #fff; }
.card { border: 1px solid #e0e0e0; border-radius: 6px; padding: 18px; margin-bottom: 20px; background: #fafafa; }
.preset-badge { background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 0.8rem; border: 1px solid #bae6fd; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.full-width { grid-column: 1 / -1; }
label { display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 4px; color: #333; }
input[type="text"], input[type="password"], textarea, select { width: 100%; padding: 9px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 5px; font-size: 0.95rem; }
button.btn-primary { background: #0070f3; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 0.95rem; }
button.btn-primary:hover { background: #0051a2; }
.alert-ok { background: #e6f4ea; color: #137333; padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #ceead6; }
.alert-err { background: #fce8e6; color: #c5221f; padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #fad2cf; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { text-align: left; padding: 10px; border-bottom: 1px solid #ddd; font-size: 0.9rem; }
th { background: #f4f4f4; }
.badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; }
.badge-admin { background: #e8f0fe; color: #1a73e8; }
.badge-user { background: #f1f3f4; color: #5f6368; }
.badge-active { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
.badge-migrated { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-unreadable { background: #fce8e6; color: #c5221f; border: 1px solid #fad2cf; }
.badge-missing { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
.badge-disabled { background: #fce8e6; color: #c5221f; }
.gpai-box { background: #f0f7ff; border: 1px dashed #0070f3; padding: 12px; border-radius: 6px; margin-top: 10px; }
</style>
<script>
// Foruddefinerede skabeloner for kendte AI udbydere og modeller
const BOT_PRESETS = {
  'gpt-4o': { name: 'OpenAI GPT-4o', provider: 'openai', model: 'gpt-4o', endpoint: 'https://api.openai.com/v1/chat/completions', prompt: 'En hjælpsom og alsidig AI-assistent.' },
  'gpt-4o-mini': { name: 'OpenAI GPT-4o Mini', provider: 'openai', model: 'gpt-4o-mini', endpoint: 'https://api.openai.com/v1/chat/completions', prompt: 'En lynhurtig og effektiv AI-assistent.' },
  'claude-35-sonnet': { name: 'Claude 3.5 Sonnet', provider: 'claude', model: 'claude-3-5-sonnet-20241022', endpoint: 'https://api.anthropic.com/v1/messages', prompt: 'En præcis, analytisk og grundig AI-assistent.' },
  'claude-3-haiku': { name: 'Claude 3 Haiku', provider: 'claude', model: 'claude-3-haiku-20240307', endpoint: 'https://api.anthropic.com/v1/messages', prompt: 'En lynhurtig AI-assistent.' },
  'mistral-large': { name: 'Mistral Large', provider: 'mistral', model: 'mistral-large-latest', endpoint: 'https://api.mistral.ai/v1/chat/completions', prompt: 'En avanceret europæisk AI-assistent fra Mistral AI.' },
  'mistral-small': { name: 'Mistral Small', provider: 'mistral', model: 'mistral-small-latest', endpoint: 'https://api.mistral.ai/v1/chat/completions', prompt: 'En hurtig og effektiv AI-assistent fra Mistral AI.' },
  'gemini-15-pro': { name: 'Google Gemini 1.5 Pro', provider: 'gemini', model: 'gemini-1.5-pro', endpoint: 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', prompt: 'Googles mest kapable AI-model.' },
  'deepseek-chat': { name: 'DeepSeek V3 / Chat', provider: 'deepseek', model: 'deepseek-chat', endpoint: 'https://api.deepseek.com/chat/completions', prompt: 'En stærk og effektiv AI-assistent fra DeepSeek.' },
  'rool-standard': { name: 'Rool Machine AI', provider: 'rool', model: 'rool-standard', endpoint: 'https://api.rool.dev/v1/chat/completions', prompt: 'En hjælpsom Rool AI-assistent.' },
  'gpai-main': { name: 'GPAI Bot', provider: 'gpai', model: 'gpai-v1', endpoint: 'https://api.gpai.dk/v1/chat/completions', prompt: 'Offentlig/dansk GPAI assistent.' },
  'librechat-agent': { name: 'LibreChat Agent', provider: 'librechat', model: 'agent_...', endpoint: 'https://din-librechat-server.dk/api/agents/v1/chat/completions', prompt: 'En LibreChat-agent med adgang til de funktioner og værktøjer, der er konfigureret i LibreChat.' }
};

function applyPreset(presetKey, prefix) {
  if (!presetKey || !BOT_PRESETS[presetKey]) return;
  const p = BOT_PRESETS[presetKey];
  
  document.getElementById(prefix + '_bot_key').value = presetKey;
  document.getElementById(prefix + '_name').value = p.name;
  document.getElementById(prefix + '_provider').value = p.provider;
  document.getElementById(prefix + '_model').value = p.model;
  document.getElementById(prefix + '_endpoint').value = p.endpoint;
  document.getElementById(prefix + '_prompt').value = p.prompt;

  toggleProviderFields(document.getElementById(prefix + '_provider'), prefix);
}

function toggleProviderFields(selectElem, prefix) {
    const provider = selectElem.value;
    const gpaiBox = document.getElementById(prefix + '_gpai_box');
    const apiBox = document.getElementById(prefix + '_api_box');
    
    if (provider === 'gpai') {
        if (gpaiBox) gpaiBox.style.display = 'block';
        if (apiBox) apiBox.style.display = 'none';
    } else {
        if (gpaiBox) gpaiBox.style.display = 'none';
        if (apiBox) apiBox.style.display = 'block';
    }
}
</script>
</head>
<body>
<div class="admin-wrapper">
<div class="admin-container">
  <div class="admin-header">
    <h1>⚙️ Multi-Chat Administration</h1>
    <a href="index.php" style="text-decoration:none; font-weight:bold; color:#0070f3;">← Tilbage til Chat</a>
  </div>

  <?php if ($success): ?><div class="alert-ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="nav-tabs">
    <a href="?tab=bots" class="nav-tab <?= $activeTab === 'bots' ? 'active' : '' ?>">🤖 AI Bots & API Nøgler</a>
    <a href="?tab=templates" class="nav-tab <?= $activeTab === 'templates' ? 'active' : '' ?>">💡 Skabelon-prompts</a>
    <a href="?tab=users" class="nav-tab <?= $activeTab === 'users' ? 'active' : '' ?>">👥 Brugeradministration</a>
    <a href="?tab=github" class="nav-tab <?= $activeTab === 'github' ? 'active' : '' ?>">🐙 GitHub</a>
    <a href="?tab=analytics" class="nav-tab <?= $activeTab === 'analytics' ? 'active' : '' ?>">📊 Forbrug & Cost</a>
    <a href="?tab=rag" class="nav-tab <?= $activeTab === 'rag' ? 'active' : '' ?>">📚 Vidensbase (RAG)</a>
  </div>

  <?php if ($activeTab === 'github'): ?>
    <h2>🐙 GitHub-forbindelse</h2>
    <div class="card">
      <p><strong>Ja — det er den rigtige løsning.</strong> Multi-Chat bør have en central GitHub-forbindelse i Admin, så du ikke skal sende en token med hver chat. Det gør det muligt at læse både offentlige og private repositories, når GitHub-tokenet har den nødvendige adgang.</p>
      <div style="background:#fff7ed;border:1px solid #fed7aa;padding:12px;border-radius:6px;margin-bottom:15px;">
        <strong>🔐 Sikkerhed:</strong> Tokenet krypteres før det gemmes i databasen. Vi gemmer ikke GitHub-adgangskoden. Brug en GitHub <strong>Fine-grained Personal Access Token</strong> med mindst <strong>Contents: Read-only</strong> på de repositories Multi-Chat skal kunne læse.
      </div>
      <?php $githubUsername = SettingsService::get('github_username', ''); $githubConfigured = SettingsService::getSecret('github_token') !== null; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_github">
        <div class="form-grid">
          <div>
            <label>GitHub bruger / ejer</label>
            <input type="text" name="github_username" value="<?= htmlspecialchars($githubUsername) ?>" placeholder="f.eks. smoeberg" required>
          </div>
          <div>
            <label>Forbindelsesstatus</label>
            <div style="padding:9px;background:#fff;border:1px solid #ccc;border-radius:5px;"><?= $githubConfigured ? '✅ Token konfigureret' : '⚠️ Ingen token konfigureret' ?></div>
          </div>
          <div class="full-width">
            <label>GitHub Fine-grained Personal Access Token</label>
            <input type="password" name="github_token" placeholder="<?= $githubConfigured ? '•••••••• (skriv kun hvis token skal ændres)' : 'ghp_... / github_pat_...' ?>" autocomplete="new-password">
          </div>
          <div class="full-width">
            <label><input type="checkbox" name="clear_github_token" value="1"> Fjern gemt GitHub-token</label>
          </div>
          <div class="full-width">
            <button type="submit" class="btn-primary">Gem GitHub-forbindelse</button>
          </div>
        </div>
      </form>
      <hr style="border:0;border-top:1px solid #ddd;margin:20px 0;">
      <p style="margin-bottom:0;color:#555;">I chatten kan du fortsat angive repositoryet som fx <code>https://github.com/ejer/projekt</code>. Multi-Chat bruger automatisk denne forbindelse.</p>
    </div>

  <?php if ($activeTab === 'templates'): ?>
    <div class="card" style="margin-bottom: 24px;">
        <h2>🏢 Central Tone of Voice for Virksomheden</h2>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 16px;">
            Definer virksomhedens officielle retningslinjer for Tone of Voice. Medarbejderne kan vælge at lade AI'en følge disse retningslinjer automatisk, når de bruger skabelon-prompts.
        </p>
        <?php $currentTone = SettingsService::get('company_tone_of_voice', 'Professionel, klar, venlig og løsningsorienteret på dansk.'); ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_tone_of_voice">
            <div style="margin-bottom: 12px;">
                <textarea name="company_tone_of_voice" rows="3" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px; font-family: inherit;" placeholder="f.eks. Formel men imødekommende tone, brug altid 'du' og 'dig', skriv præcist og professionelt på dansk..."><?= htmlspecialchars($currentTone, ENT_NOQUOTES, 'UTF-8') ?></textarea>
            </div>
            <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 13px;">Gem Tone of Voice</button>
        </form>
    </div>

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
                <h2>💡 Skabelon-prompts & Wizard-formularer</h2>
                <p style="color: var(--text-muted); font-size: 14px; margin: 4px 0 0 0;">
                    Opret intelligente skabeloner med dynamiske felter (f.eks. <code>{Stilling}</code> eller <code>{Afdeling}</code>), der automatisk genererer en udfyldningsformular til medarbejderne.
                </p>
            </div>
        </div>

        <!-- Opret ny skabelon -->
        <h3 style="font-size: 15px; margin-bottom: 12px; font-weight: 600;">Opret ny skabelon-prompt</h3>
        <form method="post" style="margin-bottom: 30px; background: var(--bg-main); padding: 16px; border-radius: 8px; border: 1px solid var(--border-color);">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create_template">
            
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Titel på skabelon</label>
                    <input type="text" name="title" placeholder="f.eks. Jobannonce: Salgschef" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Kategori</label>
                    <input type="text" name="category" placeholder="f.eks. HR" value="Generel" required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Målgruppe / Rolle</label>
                    <select name="target_role" style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px;">
                        <option value="all">Alle medarbejdere</option>
                        <option value="admin">Kun administratorer</option>
                        <option value="user">Almindelige brugere</option>
                    </select>
                </div>
            </div>
            
            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Prompt-tekst (Brug <code>{Stilling}</code> eller <code>{Afdeling}</code> til dynamiske felter)</label>
                <textarea name="prompt_text" rows="4" placeholder="Skriv en professionel jobannonce for en {Stilling} i afdelingen {Afdeling} med primære ansvarsområder: {Ansvarsomraader}..." required style="width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 13px; font-family: inherit;"></textarea>
            </div>

            <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 13px;">Opret skabelon-prompt</button>
        </form>

        <!-- Liste over eksisterende skabeloner & Analytics -->
        <h3 style="font-size: 15px; margin-bottom: 12px; font-weight: 600;">Eksisterende skabelon-prompts & Anvendelse (Analytics)</h3>
        
        <?php $templates = \Models\PromptTemplate::findAll(); if (empty($templates)): ?>
            <p style="color: var(--text-muted); font-size: 13px;">Ingen skabelon-prompts oprettet endnu.</p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($templates as $t): ?>
                    <div style="background: var(--bg-main); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <span style="font-size: 12px; background: var(--primary-soft); color: var(--primary-color); padding: 2px 8px; border-radius: 4px; font-weight: 600;"><?= htmlspecialchars($t->category) ?></span>
                            <span style="font-size: 12px; color: var(--text-muted);">📊 Anvendt <strong><?= $t->usageCount ?></strong> gange</span>
                        </div>
                        
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="update_template">
                            <input type="hidden" name="template_id" value="<?= $t->id ?>">

                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; margin-bottom: 8px;">
                                <input type="text" name="title" value="<?= htmlspecialchars($t->title, ENT_QUOTES, 'UTF-8') ?>" required style="padding: 6px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 13px; font-weight: 600;">
                                <input type="text" name="category" value="<?= htmlspecialchars($t->category, ENT_QUOTES, 'UTF-8') ?>" required style="padding: 6px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 13px;">
                                <select name="target_role" style="padding: 6px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 13px;">
                                    <option value="all" <?= $t->targetRole==='all'?'selected':'' ?>>Alle</option>
                                    <option value="admin" <?= $t->targetRole==='admin'?'selected':'' ?>>Kun Admin</option>
                                    <option value="user" <?= $t->targetRole==='user'?'selected':'' ?>>Brugere</option>
                                </select>
                                <button type="submit" class="btn-primary" style="padding: 6px 12px; font-size: 12px;">Gem</button>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <textarea name="prompt_text" rows="3" required style="width: 100%; padding: 6px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 13px; font-family: inherit;"><?= htmlspecialchars($t->promptText, ENT_NOQUOTES, 'UTF-8') ?></textarea>
                            </div>
                        </form>
                        
                        <div style="display: flex; justify-content: flex-end;">
                            <form method="post" onsubmit="return confirm('Er du sikker på, at du vil slette denne skabelon-prompt?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $t->id ?>">
                                <button type="submit" style="background: none; border: none; color: #dc2626; font-size: 12px; cursor: pointer; font-weight: 500;">Slet skabelon</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($activeTab === 'users'): ?>
    <!-- BRUGERADMINISTRATION -->
    <h2>Opret Ny Bruger</h2>
    <div class="card">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="create_user">
        <div class="form-grid">
          <div>
            <label>Brugernavn (til login)</label>
            <input type="text" name="username" required placeholder="f.eks. jens">
          </div>
          <div>
            <label>Fulde Navn</label>
            <input type="text" name="name" required placeholder="f.eks. Jens Jensen">
          </div>
          <div>
            <label>Adgangskode (min. 6 tegn)</label>
            <input type="password" name="password" required minlength="6">
          </div>
          <div>
            <label>Rolle</label>
            <select name="role">
              <option value="user">Almindelig Bruger</option>
              <option value="admin">Administrator</option>
            </select>
          </div>
          <div class="full-width">
            <button type="submit" class="btn-primary">Opret Bruger</button>
          </div>
        </div>
      </form>
    </div>

    <h2>Eksisterende Brugere</h2>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Brugernavn</th>
          <th>Navn</th>
          <th>Rolle</th>
          <th>Status</th>
          <th>Handlinger</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allUsers as $u): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><strong><?= htmlspecialchars($u['username'] ?? '–') ?></strong></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><span class="badge <?= $u['role']==='admin'?'badge-admin':'badge-user' ?>"><?= strtoupper($u['role']) ?></span></td>
            <td><span class="badge <?= $u['enabled']?'badge-active':'badge-disabled' ?>"><?= $u['enabled']?'Aktiv':'Deaktiveret' ?></span></td>
            <td>
              <details>
                <summary style="cursor:pointer; color:#0070f3; font-weight:600;">Redigér</summary>
                <form method="post" style="margin-top:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:5px;">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="action" value="update_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <div class="form-grid">
                    <div>
                      <label>Rolle</label>
                      <select name="role">
                        <option value="user" <?= $u['role']==='user'?'selected':'' ?>>Almindelig Bruger</option>
                        <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Administrator</option>
                      </select>
                    </div>
                    <div>
                      <label>Nulstil adgangskode (valgfrit)</label>
                      <input type="password" name="new_password" placeholder="Skriv ny adgangskode" minlength="6">
                    </div>
                    <div class="full-width">
                      <label><input type="checkbox" name="enabled" value="1" <?= $u['enabled']?'checked':'' ?>> Bruger er aktiv</label>
                    </div>
                    <div class="full-width">
                      <button type="submit" class="btn-primary" style="padding:6px 12px; font-size:0.85rem;">Gem Ændringer</button>
                    </div>
                  </div>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php else: ?>
    <!-- BOT ADMINISTRATION -->
    <h2>⭐ Standard AI-Model for Brugere</h2>
    <div class="card" style="margin-bottom: 24px;">
      <p style="margin-top:0; color:#555;">Vælg hvilken AI-model der automatisk skal være forvalgt for alle brugere, når de åbner chatten eller starter en ny samtale.</p>
      <?php $currentDefaultBot = SettingsService::get('default_bot', 'gpt-4o'); ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_default_bot">
        <div style="display:flex; gap:12px; align-items:flex-end; max-width:600px;">
          <div style="flex:1;">
            <label style="font-weight:600; margin-bottom:6px; display:block;">Vælg Standard Model</label>
            <select name="default_bot" style="width:100%; padding:9px 12px; border-radius:6px; border:1px solid #ccc; font-weight:600;">
              <?php foreach ($bots as $b): ?>
                <option value="<?= htmlspecialchars($b->botKey) ?>" <?= $b->botKey === $currentDefaultBot ? 'selected' : '' ?>>
                  <?= htmlspecialchars($b->name) ?> (<?= htmlspecialchars($b->botKey) ?>) <?= $b->isConfigured() ? '✅' : '⚠️ ikke konfigureret' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn-primary" style="padding:9px 18px;">Gem Standard</button>
        </div>
      </form>
    </div>

    <h2>Tilføj Ny AI Bot</h2>
    <div class="card">
      <div style="margin-bottom: 15px; background:#f0f7ff; padding:12px; border-radius:6px; border:1px solid #bae6fd;">
        <label style="color:#0284c7; font-size:0.9rem;">⚡ Lyn-Udfyld fra Skabelon (Klik for automatisk udfyldelse):</label>
        <select onchange="applyPreset(this.value, 'new_bot')" style="margin-top:5px; font-weight:600;">
          <option value="">-- Vælg en kendt AI Model skabelon --</option>
          <option value="gpt-4o">OpenAI GPT-4o</option>
          <option value="gpt-4o-mini">OpenAI GPT-4o Mini</option>
          <option value="claude-35-sonnet">Anthropic Claude 3.5 Sonnet</option>
          <option value="claude-3-haiku">Anthropic Claude 3 Haiku</option>
          <option value="mistral-large">Mistral Large</option>
          <option value="mistral-small">Mistral Small</option>
          <option value="gemini-15-pro">Google Gemini 1.5 Pro</option>
          <option value="deepseek-chat">DeepSeek V3 / Chat</option>
          <option value="rool-standard">Rool Machine AI</option>
          <option value="gpai-main">GPAI Bot (Brugernavn/Adgangskode)</option>
          <option value="librechat-agent">LibreChat Agent</option>
        </select>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="save_bot">
        <div class="form-grid">
          <div>
            <label>Bot-Nøgle (f.eks. gpt-4o, mistral-large)</label>
            <input type="text" id="new_bot_bot_key" name="bot_key" required placeholder="f.eks. gpt-4o">
          </div>
          <div>
            <label>Vist Navn</label>
            <input type="text" id="new_bot_name" name="name" required placeholder="f.eks. OpenAI GPT-4o">
          </div>
          <div>
            <label>Provider / Type</label>
            <select id="new_bot_provider" name="provider" onchange="toggleProviderFields(this, 'new_bot')">
              <option value="openai">OpenAI / Kompatibel API</option>
              <option value="claude">Anthropic Claude</option>
              <option value="mistral">Mistral AI</option>
              <option value="gemini">Google Gemini</option>
              <option value="deepseek">DeepSeek</option>
              <option value="rool">Rool AI (SDK / Machine)</option>
              <option value="gpai">GPAI (Brugernavn & Adgangskode)</option>
              <option value="librechat">LibreChat Agent (OpenAI-kompatibel)</option>
            </select>
          </div>
          <div>
            <label>Model ID / Agent ID</label>
            <input type="text" id="new_bot_model" name="model" required placeholder="f.eks. gpt-4o eller agent_abc123">
          </div>
          <div class="full-width">
            <label>Endpoint URL</label>
            <input type="text" id="new_bot_endpoint" name="endpoint" required value="https://api.openai.com/v1/chat/completions">
            <small style="display:block;margin-top:5px;color:#666;">LibreChat: brug <code>https://DIN-LIBRECHAT/api/agents/v1/chat/completions</code>. Model ID er LibreChat-agentens ID.</small>
          </div>

          <!-- Normal API Key box -->
          <div class="full-width" id="new_bot_api_box">
            <label>API Key (Krypteres automatisk)</label>
            <input type="password" name="api_key" placeholder="sk-...">
          </div>

          <!-- GPAI Specifik Login box -->
          <div class="full-width gpai-box" id="new_bot_gpai_box" style="display:none;">
            <p style="margin:0 0 8px 0; font-weight:bold; color:#0070f3;">🔐 GPAI Legitimationsoplysninger</p>
            <div class="form-grid">
              <div>
                <label>GPAI Brugernavn</label>
                <input type="text" name="gpai_username" placeholder="bruger@domæne.dk">
              </div>
              <div>
                <label>GPAI Adgangskode</label>
                <input type="password" name="gpai_password" placeholder="Adgangskode">
              </div>
            </div>
          </div>

          <div class="full-width">
            <label>System Prompt</label>
            <textarea id="new_bot_prompt" name="system_prompt" rows="2">En hjælpsom assistent</textarea>
          </div>
          <div>
            <label><input type="checkbox" name="enabled" value="1" checked> Aktiv</label>
          </div>
          <div class="full-width">
            <button type="submit" class="btn-primary">Gem Ny Bot</button>
          </div>
        </div>
      </form>
    </div>

    <h2>Konfigurerede AI Bots</h2>
    <?php foreach ($bots as $b): 
      $cfg = json_decode($b->getDecryptedConfig() ?? '{}', true) ?: [];
      $gpaiUser = $cfg['username'] ?? '';
      $keyStatus = method_exists($b, 'getKeyStatus') ? $b->getKeyStatus() : ($b->isConfigured() ? 'VALID' : 'MISSING');
    ?>
      <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <h3 style="margin:0;"><?= htmlspecialchars($b->name) ?> <small style="font-size:0.8rem; color:#666;">(<?= htmlspecialchars($b->botKey) ?>)</small></h3>
          <span class="preset-badge"><?= strtoupper(htmlspecialchars($b->provider)) ?></span>
        </div>
        <p style="margin: 8px 0 0 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
          <strong>Model:</strong> <code><?= htmlspecialchars($b->model) ?></code> | 
          <strong>Status:</strong>
          <?php switch ($keyStatus): 
              case 'VALID': ?>
                  <span class="badge badge-active" title="Nøglen er aktiv og afkodes med den nuværende primære ENCRYPTION_KEY">
                      ✓ Aktiv
                  </span>
                  <?php break; ?>

              case 'MIGRATED': ?>
                  <span class="badge badge-migrated" title="Nøglen blev afkodet via gammel nøgle og gen-krypteret med primær nøgle">
                      🔄 Migreret
                  </span>
                  <?php break; ?>

              case 'UNREADABLE': ?>
                  <span class="badge badge-unreadable" title="Ugyldig nøgle: Kan hverken afkodes med primær eller gammel .env nøgle!">
                      ⚠️ Uafkodelig (Kræver gen-indtastning)
                  </span>
                  <?php break; ?>

              default: ?>
                  <span class="badge badge-missing" title="Ingen API-nøgle eller login er indtastet">
                      Ikke konfigureret
                  </span>
                  <?php break; ?>
          <?php endswitch; ?>
          | <strong>Aktiv:</strong> <?= $b->enabled ? 'Ja' : 'Nej' ?>
        </p>

        <details style="margin-top:12px;">
          <summary style="cursor:pointer; color:#0070f3; font-weight:600;">Redigér Indstillinger & Adgangsnøgle</summary>
          <form method="post" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="save_bot">
            <input type="hidden" name="bot_key" value="<?= htmlspecialchars($b->botKey) ?>">
            <div class="form-grid">
              <div>
                <label>Vist Navn</label>
                <input type="text" name="name" value="<?= htmlspecialchars($b->name) ?>" required>
              </div>
              <div>
                <label>Provider</label>
                <select name="provider" onchange="toggleProviderFields(this, 'edit_<?= $b->id ?>')">
                  <option value="openai" <?= $b->provider==='openai'?'selected':'' ?>>OpenAI / Kompatibel</option>
                  <option value="claude" <?= $b->provider==='claude'?'selected':'' ?>>Anthropic Claude</option>
                  <option value="mistral" <?= $b->provider==='mistral'?'selected':'' ?>>Mistral AI</option>
                  <option value="gemini" <?= $b->provider==='gemini'?'selected':'' ?>>Google Gemini</option>
                  <option value="deepseek" <?= $b->provider==='deepseek'?'selected':'' ?>>DeepSeek</option>
                  <option value="rool" <?= $b->provider==='rool'?'selected':'' ?>>Rool AI</option>
                  <option value="gpai" <?= $b->provider==='gpai'?'selected':'' ?>>GPAI (Brugernavn & Adgangskode)</option>
                  <option value="librechat" <?= $b->provider==='librechat'?'selected':'' ?>>LibreChat Agent (OpenAI-kompatibel)</option>
                </select>
              </div>
              <div>
                <label>Model ID / Agent ID</label>
                <input type="text" name="model" value="<?= htmlspecialchars($b->model) ?>" required>
              </div>
              <div class="full-width">
                <label>Endpoint URL</label>
                <input type="text" name="endpoint" value="<?= htmlspecialchars($b->endpoint) ?>" required>
              </div>

              <!-- Standard API key -->
              <div class="full-width" id="edit_<?= $b->id ?>_api_box" style="<?= $b->provider==='gpai'?'display:none;':'' ?>">
                <label>API Key / Nøgle (efterlad blank for uændret)</label>
                <input type="password" name="api_key" placeholder="<?= $b->isConfigured() ? '•••••••• (skriv ny for at ændre)' : 'Indtast din API Key' ?>">
              </div>

              <!-- GPAI Auth -->
              <div class="full-width gpai-box" id="edit_<?= $b->id ?>_gpai_box" style="<?= $b->provider==='gpai'?'':'display:none;' ?>">
                <p style="margin:0 0 8px 0; font-weight:bold; color:#0070f3;">🔐 GPAI Legitimationsoplysninger</p>
                <div class="form-grid">
                  <div>
                    <label>GPAI Brugernavn</label>
                    <input type="text" name="gpai_username" value="<?= htmlspecialchars($gpaiUser) ?>" placeholder="bruger@domæne.dk">
                  </div>
                  <div>
                    <label>GPAI Adgangskode / Password</label>
                    <input type="password" name="gpai_password" placeholder="<?= $b->isConfigured() ? '•••••••• (skriv nyt for at ændre)' : 'Skriv adgangskode' ?>">
                  </div>
                </div>
              </div>

              <div class="full-width">
                <label>System Prompt</label>
                <textarea name="system_prompt" rows="2"><?= htmlspecialchars($b->systemPrompt ?? '') ?></textarea>
              </div>
              <div>
                <label><input type="checkbox" name="enabled" value="1" <?= $b->enabled ? 'checked' : '' ?>> Aktiv for brugere</label>
              </div>
              <div class="full-width">
                <button type="submit" class="btn-primary">Gem Ændringer for <?= htmlspecialchars($b->name) ?></button>
              </div>
            </div>
          </form>
        </details>
      </div>
    <?php endforeach; ?>
</div>
</div>
</body>
</html>