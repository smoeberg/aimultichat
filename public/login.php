<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
startSecureSession();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) { header('Location: login.php?error=login'); exit; }
    (new \Controllers\AuthController())->login();
}
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EiraMultiChat – Log ind</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=2">
    <style>
        /* Fallback inlined critical styles for Login */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body.login-page {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            margin: 0;
            background: radial-gradient(circle at 50% 30%, #1e3a32 0%, #0d1915 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }
        .login-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 40px 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            position: relative;
        }
        .login-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .logo-container {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 76px;
            height: 76px;
            background: #f0f7f4;
            border-radius: 18px;
            margin-bottom: 16px;
        }
        .login-logo {
            width: 48px;
            height: 48px;
            max-width: 48px;
            max-height: 48px;
            object-fit: contain;
            display: block;
        }
        .brand-title {
            font-size: 24px;
            font-weight: 700;
            color: #30705f;
            margin-bottom: 6px;
        }
        .brand-subtitle {
            font-size: 14px;
            color: #64748b;
        }
        .login-error {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13.5px;
        }
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            pointer-events: none;
        }
        .input-wrapper input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #1a202c;
            background-color: #f8fafc;
            outline: none;
            transition: all 0.2s ease;
        }
        .input-wrapper input:focus {
            background-color: #fff;
            border-color: #30705f;
            box-shadow: 0 0 0 4px rgba(48, 112, 95, 0.15);
        }
        .btn-submit {
            margin-top: 6px;
            padding: 13px 20px;
            background: linear-gradient(135deg, #30705f 0%, #225346 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #275d4f 0%, #1a3f35 100%);
        }
        .login-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-wrapper">
        <main class="login-card">
            <div class="login-header">
                <div class="logo-container">
                    <img src="images/logoeira.svg" alt="Eira Logo" class="login-logo" width="48" height="48">
                </div>
                <h1 class="brand-title">EiraMultiChat</h1>
                <p class="brand-subtitle">Velkommen tilbage! Log ind for at fortsætte</p>
            </div>

            <?php if(isset($_GET['error'])): ?>
                <div class="login-error" role="alert">
                    <svg class="error-icon" viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <span>Forkert brugernavn eller adgangskode.</span>
                </div>
            <?php endif; ?>

            <form method="post" class="login-form">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8')?>">
                
                <div class="form-group">
                    <label for="login_username">Brugernavn</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                        </svg>
                        <input id="login_username" name="login_username" autocomplete="username" required maxlength="100" placeholder="Indtast brugernavn" autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="login_password">Adgangskode</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor" width="18" height="18">
                            <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                        </svg>
                        <input type="password" id="login_password" name="login_password" autocomplete="current-password" required placeholder="Indtast adgangskode">
                    </div>
                </div>

                <button type="submit" class="btn-submit">Log ind</button>
            </form>

            <footer class="login-footer">
                <p>&copy; <?=date('Y')?> EiraMultiChat. Alle rettigheder forbeholdes.</p>
            </footer>
        </main>
    </div>
</body>
</html>
