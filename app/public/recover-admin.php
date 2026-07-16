<?php
/**
 * One-time admin password recovery (FTP-only hosts).
 *
 * 1. Upload empty file: storage/.recover-admin (via FTP)
 * 2. Open this URL once in the browser
 * 3. Delete storage/.recover-admin and this file immediately
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$flag = $root . '/storage/.recover-admin';

if (!is_file($flag)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem">';
    echo '<h1>Recovery disabled</h1>';
    echo '<p>Create <code>storage/.recover-admin</code> on the server (empty file), then reload this page.</p>';
    echo '</body></html>';
    exit;
}

try {
    require_once $root . '/src/bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Bootstrap failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

use Navicat\App;
use Navicat\Auth\AuthService;

$config = App::config();
$email = (string)($config['admin_email'] ?? '');
$password = (string)($config['admin_password'] ?? '');

if ($email === '') {
    http_response_code(500);
    echo 'config.php: admin_email is empty';
    exit;
}

if ($password === '') {
    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    $generated = true;
} else {
    $generated = false;
}

$db = App::db();
$st = $db->prepare('SELECT id, role FROM users WHERE email = ?');
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
    AuthService::createUser($email, $password, 'admin');
    $action = 'created';
} else {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('UPDATE users SET password_hash = ?, role = ? WHERE id = ?')
        ->execute([$hash, 'admin', $user['id']]);
    $action = 'reset';
}

@unlink($flag);

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Admin recovery</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 2rem auto; padding: 0 1rem; }
    code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; }
    .box { background: #f6f8fa; border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin: 1rem 0; }
    .warn { color: #b45309; font-size: 0.9rem; }
  </style>
</head>
<body>
  <h1>Admin <?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></h1>
  <div class="box">
    <p><b>Email:</b> <code><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></code></p>
    <p><b>Password:</b> <code><?= htmlspecialchars($password, ENT_QUOTES, 'UTF-8') ?></code></p>
    <?php if ($generated): ?>
      <p class="warn">Generated randomly — set <code>admin_password</code> in config.php if you want a fixed password, then run recovery again.</p>
    <?php endif; ?>
  </div>
  <p class="warn"><b>Delete now:</b> <code>public/recover-admin.php</code> and confirm <code>storage/.recover-admin</code> is gone.</p>
  <p><a href="./">Go to login</a></p>
</body>
</html>
