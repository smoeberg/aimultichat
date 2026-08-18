<?php
declare(strict_types=1);
namespace Controllers;
use Models\User; use Core\Security; use Core\Logger;
final class AuthController {
 public function login(): void { startSecureSession(); $u=trim((string)($_POST['login_username']??''));$p=(string)($_POST['login_password']??''); if($u===''||$p===''){header('Location: index.php?error=login');exit;} $user=User::findByUsername($u); if(!$user||!Security::verifyPassword($p,$user->passwordHash??'')){usleep(250000);header('Location: index.php?error=login');exit;} session_regenerate_id(true);$_SESSION['uid']=$user->id;$_SESSION['csrf_token']=bin2hex(random_bytes(32));header('Location: index.php');exit; }
 public function logout(): void { startSecureSession(); $_SESSION=[]; if(ini_get('session.use_cookies')){ $p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',$p['secure'],$p['httponly']); } session_destroy();header('Location: index.php');exit; }
}
