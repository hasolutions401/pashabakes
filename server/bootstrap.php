<?php
/*
 * Loaded by every PHP entry point (dist/api/*, dist/admin/*).
 * Everything in /server is outside the public web root.
 */
declare(strict_types=1);

const PB_ROOT = __DIR__;
const PB_TZ = 'America/New_York';

date_default_timezone_set(PB_TZ);
mb_internal_encoding('UTF-8');

// Never show PHP errors to visitors; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once PB_ROOT . '/vendor/autoload.php';
require_once PB_ROOT . '/lib/helpers.php';
require_once PB_ROOT . '/lib/db.php';
require_once PB_ROOT . '/lib/settings.php';
require_once PB_ROOT . '/lib/menu.php';
require_once PB_ROOT . '/lib/orders.php';
require_once PB_ROOT . '/lib/enquiries.php';
require_once PB_ROOT . '/lib/ratelimit.php';
require_once PB_ROOT . '/lib/mailer.php';
require_once PB_ROOT . '/lib/emails.php';
require_once PB_ROOT . '/lib/auth.php';

function config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        // PB_CONFIG lets automated tests use a separate config file.
        $file = getenv('PB_CONFIG') ?: PB_ROOT . '/config.php';
        if (!is_file($file)) {
            fatal_setup_error('server/config.php is missing. Copy server/config.sample.php to server/config.php and fill it in.');
        }
        $config = require $file;
        if (!is_array($config)) {
            fatal_setup_error('server/config.php must return an array.');
        }
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * The public website folder (where index.html, uploads/ and images/ live).
 *  - alwaysdata / git checkout:  <project>/dist      (server/ sits next to dist/)
 *  - Hostinger:                  <domain>/public_html (server/ sits next to public_html/)
 * Can be set explicitly with 'public_dir' in server/config.php.
 */
function public_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $configured = (string) (config('public_dir') ?? '');
        $base = dirname(PB_ROOT);
        $dir = $configured !== '' ? rtrim($configured, '/')
            : (is_dir($base . '/dist') ? $base . '/dist' : $base . '/public_html');
    }
    return $dir;
}

function data_dir(string $sub = ''): string
{
    $dir = PB_ROOT . '/data' . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fatal_setup_error("Cannot create the folder server/data/{$sub}. Please make server/data writable.");
    }
    return $dir;
}

ini_set('error_log', data_dir('logs') . '/php-errors.log');

/** Stops with a clear, safe message. Details go to the error log only. */
function fatal_setup_error(string $message): never
{
    error_log('[pashabakess] ' . $message);
    http_response_code(500);
    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'The ordering system is temporarily unavailable. Please email pashabakess@gmail.com.']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title><body style="font-family:system-ui;padding:40px;max-width:640px">'
            . '<h1>Setup needed</h1><p>' . htmlspecialchars($message) . '</p></body>';
    }
    exit;
}
