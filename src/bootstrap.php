<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_DIR', APP_ROOT . '/storage');
define('APP_VERSION', trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: 'dev');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NixVm\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

/**
 * @return array<string, mixed>
 */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config.php';
        $local = APP_ROOT . '/config.local.php';
        if (is_file($local)) {
            $config = array_replace_recursive($config, require $local);
        }
    }
    return $config;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_dir(string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer le dossier $dir");
    }
    return $dir;
}
