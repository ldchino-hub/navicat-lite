<?php
/**
 * Deployment diagnostic — open in browser: https://your-domain.com/check.php
 * Delete this file after fixing issues (production).
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$root = dirname(__DIR__);
$public = __DIR__;
$ok = true;

function row(string $label, bool $pass, string $detail = ''): string
{
    global $ok;
    if (!$pass) $ok = false;
    $icon = $pass ? '✓' : '✗';
    $cls = $pass ? 'ok' : 'fail';
    $extra = $detail !== '' ? ' — ' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') : '';
    return "<tr class=\"$cls\"><td>$icon</td><td><b>$label</b>$extra</td></tr>";
}

$requiredExts = ['pdo', 'pdo_sqlite', 'pdo_mysql', 'openssl', 'json', 'mbstring'];
$optionalExts = ['pdo_pgsql'];
$extRows = '';
foreach ($requiredExts as $ext) {
    $extRows .= row("ext-$ext", extension_loaded($ext));
}
foreach ($optionalExts as $ext) {
    $loaded = extension_loaded($ext);
    $extRows .= row(
        "ext-$ext (opcional, para conexiones PostgreSQL)",
        true,
        $loaded ? 'instalada' : 'no instalada — MySQL/SQLite meta siguen funcionando'
    );
}

$configPath = $root . '/config/config.php';
$configExists = is_file($configPath);
$bootstrapOk = false;
$bootstrapError = '';
$sqliteOk = false;

if (is_file($root . '/src/bootstrap.php')) {
    try {
        require_once $root . '/src/bootstrap.php';
        Navicat\App::db()->query('SELECT 1');
        $bootstrapOk = true;
        $sqliteOk = true;
    } catch (Throwable $e) {
        $bootstrapError = $e->getMessage();
    }
}

$storageDir = $root . '/storage';
$storageWritable = is_dir($storageDir) && is_writable($storageDir);
if (!$storageWritable && !is_dir($storageDir)) {
    $storageWritable = @mkdir($storageDir, 0755, true) && is_writable($storageDir);
}

$assetsDir = $public . '/assets';
$indexHtml = $public . '/index.html';
$assetFiles = is_dir($assetsDir) ? glob($assetsDir . '/*.{js,css}', GLOB_BRACE) : [];

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>DB Tool Box — Deploy Check</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
    h1 { font-size: 1.25rem; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    td { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
    td:first-child { width: 28px; text-align: center; }
    .ok { color: #0a7; }
    .fail { color: #c00; }
    .box { background: #f6f8fa; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin: 1rem 0; font-size: 13px; }
    code { background: #eee; padding: 1px 4px; border-radius: 3px; }
  </style>
</head>
<body>
  <h1>DB Tool Box Lite — diagnóstico de deploy</h1>

  <?php if ($ok && $bootstrapOk): ?>
    <p class="ok"><b>Todo OK.</b> Si la app sigue en blanco, revisa la consola del navegador (F12) y prueba <code>/api/health</code>.</p>
  <?php else: ?>
    <p class="fail"><b>Hay problemas.</b> Corrige los ítems marcados con ✗.</p>
  <?php endif; ?>

  <table>
    <?= row('PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION) ?>
    <?= $extRows ?>
    <?= row('Document root apunta a public/', str_ends_with(str_replace('\\', '/', $public), '/public') || basename($public) === 'public' || str_contains($docRoot, 'public'), "DOCUMENT_ROOT=$docRoot") ?>
    <?= row('config/config.php', $configExists, $configExists ? $configPath : 'Falta — copia config.example.php') ?>
    <?= row('src/bootstrap.php', is_file($root . '/src/bootstrap.php'), $root . '/src') ?>
    <?= row('storage/ escribible', $storageWritable, $storageDir) ?>
    <?= row('public/index.html (frontend build)', is_file($indexHtml)) ?>
    <?= row('public/assets/ (JS/CSS)', is_dir($assetsDir) && count($assetFiles) > 0, count($assetFiles) . ' archivos') ?>
    <?= row('Bootstrap + SQLite', $bootstrapOk, $bootstrapError) ?>
  </table>

  <div class="box">
    <b>Estructura requerida en el servidor</b><br>
    El <code>DocumentRoot</code> del dominio debe ser la carpeta <code>public/</code>, no la raíz del proyecto.<br><br>
    <code>
      tu-proyecto/<br>
      &nbsp;&nbsp;config/config.php<br>
      &nbsp;&nbsp;src/<br>
      &nbsp;&nbsp;storage/ (writable)<br>
      &nbsp;&nbsp;migrations/<br>
      &nbsp;&nbsp;public/ ← DocumentRoot<br>
      &nbsp;&nbsp;&nbsp;&nbsp;index.php<br>
      &nbsp;&nbsp;&nbsp;&nbsp;index.html<br>
      &nbsp;&nbsp;&nbsp;&nbsp;assets/<br>
      &nbsp;&nbsp;&nbsp;&nbsp;.htaccess
    </code>
  </div>

  <div class="box">
    <b>URLs de prueba</b><br>
    API health: <a href="api/health"><?= htmlspecialchars(dirname($scriptName) . '/api/health') ?></a><br>
    SPA: <a href="./"><?= htmlspecialchars(dirname($scriptName) ?: '/') ?></a>
  </div>

  <div class="box">
    <b>Después del deploy</b><br>
    1. <code>chmod 775 storage storage/backups</code><br>
    2. <code>php scripts/seed-admin.php</code> (por SSH, una vez)<br>
    3. Borra <code>check.php</code> en producción
  </div>

  <p style="font-size:12px;color:#666">SCRIPT_NAME: <?= htmlspecialchars($scriptName) ?> · REQUEST_URI: <?= htmlspecialchars($requestUri) ?></p>
</body>
</html>
