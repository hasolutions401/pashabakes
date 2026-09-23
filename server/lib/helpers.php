<?php
declare(strict_types=1);

/** Escape for HTML output. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function clean_text(?string $value, int $max): string
{
    $value = (string) $value;
    // Strip control characters except newlines/tabs, normalise whitespace at the ends.
    $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';
    $value = trim($value);
    return mb_substr($value, 0, $max);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
