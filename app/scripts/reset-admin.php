<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Navicat\App;
use Navicat\Auth\AuthService;

$config = App::config();
$email = (string)($argv[1] ?? $config['admin_email']);
$password = (string)($argv[2] ?? $config['admin_password'] ?? '');

if ($email === '') {
    fwrite(STDERR, "Usage: php scripts/reset-admin.php [email] [password]\n");
    exit(1);
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
    echo "Admin user created (did not exist):\n";
} else {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('UPDATE users SET password_hash = ?, role = ? WHERE id = ?')
        ->execute([$hash, 'admin', $user['id']]);
    echo "Admin password reset:\n";
}

echo str_repeat('=', 50) . "\n";
echo "  Email:    {$email}\n";
echo "  Password: {$password}\n";
echo str_repeat('=', 50) . "\n";
if ($generated) {
    echo "Random password generated — update config.php if you want a fixed one.\n";
}
