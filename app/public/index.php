<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/src/bootstrap.php';
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $debug = false;
    $cfg = dirname(__DIR__) . '/config/config.php';
    if (is_file($cfg)) {
        $c = require $cfg;
        $debug = !empty($c['debug']);
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>DB Tool Box — Error</title></head><body style="font-family:sans-serif;padding:2rem">';
    echo '<h1>DB Tool Box no pudo iniciar</h1>';
    if ($debug) {
        echo '<pre style="background:#fee;padding:1rem;border-radius:6px">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<p>Error interno. Abre <code>/check.php</code> para diagnosticar, o activa <code>debug =&gt; true</code> en config.php temporalmente.</p>';
    }
    echo '</body></html>';
    exit;
}

$uri = navicat_request_path();

if (str_starts_with($uri, '/assets/')) {
    $publicDir = __DIR__;
    $path = $uri;
    $file = realpath($publicDir . $path);
    $publicReal = realpath($publicDir) ?: $publicDir;
    if ($file && str_starts_with($file, $publicReal) && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'map' => 'application/json',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
        ];
        if (isset($types[$ext])) {
            header('Access-Control-Allow-Origin: *');
            header('Content-Type: ' . $types[$ext]);
            readfile($file);
            exit;
        }
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

if (str_starts_with($uri, '/api/')) {
    (new Navicat\Http\Router())->dispatch();
    exit;
}

$publicDir = __DIR__;
$path = $uri === '/' ? '/index.html' : $uri;
$file = realpath($publicDir . $path);
$publicReal = realpath($publicDir) ?: $publicDir;

if ($file && str_starts_with($file, $publicReal) && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        require $file;
        exit;
    }
    if ($ext === 'html') {
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }
    $types = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    exit;
}

$pathOnly = $uri === '/' ? '/index.html' : $uri;
if (preg_match('#\.(js|mjs|css|map|woff2?|png|jpe?g|svg|ico|gif|webp)$#i', $pathOnly)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$index = $publicDir . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($index);
    exit;
}

http_response_code(404);
echo 'Not found';
