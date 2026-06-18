<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Navicat\App;
use Navicat\Auth\AuthService;

$config = App::config();
$email = (string)$config['admin_email'];
$password = (string)($config['admin_password'] ?? '');

if ($password === '') {
    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
}

$db = App::db();
$st = $db->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([$email]);
if ($st->fetch()) {
    echo "Admin already exists: {$email}\n";
    exit(0);
}

AuthService::createUser($email, $password, 'admin');
echo str_repeat('=', 50) . "\n";
echo "Admin user created:\n";
echo "  Email:    {$email}\n";
echo "  Password: {$password}\n";
echo str_repeat('=', 50) . "\n";
echo "Save this password — it will not be shown again.\n";
