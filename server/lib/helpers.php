<?php
declare(strict_types=1);

/** Escape for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Writes to the error log with email addresses masked (the log is not the place for customer details). */
function log_error(string $message): void
{
    error_log('[pashabakess] ' . preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[email]', $message));
}

function now_str(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(PB_TZ)))->format('Y-m-d H:i:s');
}

function today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', new DateTimeZone(PB_TZ));
}

/** Parses a strict YYYY-MM-DD date, or returns null. */
function parse_date(string $value): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(PB_TZ));
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

function pretty_date(string $ymd): string
{
    $d = parse_date($ymd);
    return $d ? $d->format('l, F j, Y') : $ymd;
}

function short_date(string $ymd): string
{
    $d = parse_date($ymd);
    return $d ? $d->format('D, M j') : $ymd;
}

function pretty_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone(PB_TZ));
    return $d ? $d->format('M j, Y · g:i A') : $value;
}

function money(int $cents): string
{
    return '$' . ($cents % 100 === 0 ? number_format($cents / 100) : number_format($cents / 100, 2));
}

/** Text from a form or JSON field. Anything that isn't plain text or a number (e.g. a list) counts as empty. */
function clean_text(mixed $value, int $max): string
{
    $value = is_scalar($value) ? (string) $value : '';
    // Strip control characters except newlines/tabs, normalise whitespace at the ends.
    $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';
    $value = trim($value);
    return mb_substr($value, 0, $max);
}

/** Like clean_text(), for one-line fields (name, phone…): line breaks and runs of spaces become one space. */
function clean_line(mixed $value, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', clean_text($value, $max * 2)) ?? ''), 0, $max);
}

/** A whole number from a form or JSON field (5 or "5"), or null for anything else ("5abc", 2.5, lists…). */
function whole_number(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    return is_string($value) && preg_match('/^\s*-?\d{1,6}\s*$/', $value) ? (int) $value : null;
}

/**
 * The visitor's IP address, used only (hashed) for spam limits. When the request
 * reaches PHP through the host's own proxy (a private address), the real visitor is
 * the last public address that proxy added to X-Forwarded-For — otherwise every
 * customer would share one limit.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $public = fn(string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    if ($public($remote)) {
        return $remote;
    }
    $forwarded = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($forwarded as $ip) {
        if ($public($ip)) {
            return $ip;
        }
    }
    return $remote;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function site_url(string $path = ''): string
{
    $base = rtrim((string) config('site_url'), '/');
    return $base . '/' . ltrim($path, '/');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * True if the server can send the reply now and keep working afterwards (PHP-FPM on alwaysdata:
 * fastcgi_finish_request; LiteSpeed on Hostinger: litespeed_finish_request).
 */
function can_finish_request_early(): bool
{
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
}

/** Sends everything echoed so far to the visitor and closes the connection; the script keeps running. */
function finish_request_early(): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Lines of a textarea → trimmed, non-empty strings. */
function text_lines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
}

function payment_label(string $method): string
{
    return $method === 'cashapp' ? 'Cash App' : 'Venmo';
}

function status_label(string $status): string
{
    return [
        'pending'   => 'Payment pending',
        'paid'      => 'Paid · to bake',
        'completed' => 'Picked up',
        'cancelled' => 'Cancelled',
    ][$status] ?? ucfirst($status);
}
