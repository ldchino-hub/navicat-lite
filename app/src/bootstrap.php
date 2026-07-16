<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'Navicat\\')) return;
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) require_once $file;
    });
}

$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.example.php';
}
/** @var array<string,mixed> $config */
$config = require $configPath;

Navicat\App::init($config);

$requestUri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (str_contains($requestUri, '/api/')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

if (!function_exists('navicat_request_path')) {
    function navicat_request_path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Only strip subdirectory base when the front controller is index.php (Apache/Nginx).
        // PHP built-in server sets SCRIPT_NAME to the requested asset path — do not strip then.
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_ends_with($script, '/index.php') || $script === '/index.php' || $script === 'index.php') {
            $basePath = rtrim(dirname($script), '/');
            if ($basePath !== '' && $basePath !== '/' && str_starts_with($uri, $basePath)) {
                $uri = substr($uri, strlen($basePath)) ?: '/';
            }
        }

        if ($uri !== '/' && !str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }

        return rtrim($uri, '/') ?: '/';
    }
}

if (!function_exists('navicat_authorization_header')) {
    function navicat_authorization_header(): string
    {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string)$name, 'Authorization') === 0) {
                        return (string)$value;
                    }
                }
            }
        }
        return '';
    }
}
