<?php
declare(strict_types=1);

/*
 * Meta (Facebook/Instagram) ads measurement: Meta Pixel in the browser + Conversions API from here.
 * SWITCHED OFF unless server/config.php has meta.pixel_id — see META-ADS.md before turning it on
 * (the privacy notice and the consent banner text must be approved first).
 *
 * Nothing is ever sent for a customer who didn't click "Allow" in the cookie banner. Only hashed
 * email and phone, Meta's own browser ids (fbp/fbc) and the order value are sent — never names,
 * notes, addresses or anything the customer typed freely.
 */

const PB_META_GRAPH_VERSION = 'v23.0';   // Graph API version; Meta supports each for about two years

function meta_pixel_id(): string
{
    return preg_replace('/\D/', '', (string) (config('meta.pixel_id') ?? '')) ?? '';
}

/** Browser pixel + cookie banner on. */
function meta_enabled(): bool
{
    return meta_pixel_id() !== '';
}

/** Server-side events on (also needs the Conversions API access token). */
function meta_capi_enabled(): bool
{
    return meta_enabled() && ((string) (config('meta.capi_token') ?? '') !== '' || config('meta.transport') === 'log');
}

/** Meta's browser ids look like "fb.1.1712345678901.AbC-123"; anything else is dropped. */
function meta_clean_browser_id(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    return preg_match('/^fb\.[0-9]\.[0-9]{10,13}\.[A-Za-z0-9_.\-]{1,200}$/', $value) ? $value : '';
}

/** Normalised + SHA-256 hashed, as Meta requires. US numbers get the country code. */
function meta_hash_email(string $email): string
{
    $email = mb_strtolower(trim($email));
    return $email === '' ? '' : hash('sha256', $email);
}

function meta_hash_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($digits) === 10) {
        $digits = '1' . $digits;
    }
    return $digits === '' ? '' : hash('sha256', $digits);
}

/**
 * One Conversions API event for an order. $request: add the visitor's IP address and browser from the
 * current request (only when the customer is the one making the request, i.e. when the order is placed).
 */
function meta_order_event(string $name, array $order, string $eventId, bool $request): array
{
    $user = array_filter([
        'em' => array_filter([meta_hash_email((string) $order['email'])]),
        'ph' => array_filter([meta_hash_phone((string) $order['phone'])]),
        'fbp' => (string) ($order['fbp'] ?? ''),
        'fbc' => (string) ($order['fbc'] ?? ''),
        'client_ip_address' => $request ? client_ip() : '',
        'client_user_agent' => $request ? mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400) : '',
    ]);
    return [
        'event_name' => $name,
        'event_time' => time(),
        'event_id' => $eventId,
        'action_source' => 'website',
        'event_source_url' => site_url('order.html'),
        'user_data' => $user,
        'custom_data' => [
            'currency' => 'USD',
            'value' => round((int) $order['total_cents'] / 100, 2),
            'content_type' => 'product',
            'contents' => [['id' => 'box-' . (int) $order['box_size'], 'quantity' => 1]],
            'num_items' => (int) $order['box_size'],
            'order_id' => (string) $order['code'],
        ],
    ];
}

/**
 * Sends an order event if ads measurement is on and this customer allowed it. Never throws;
 * failures go to the error log (without customer details).
 */
function meta_send_order_event(string $name, array $order, string $eventId, bool $request = false): bool
{
    if (!meta_capi_enabled() || (int) ($order['ad_consent'] ?? 0) !== 1) {
        return false;
    }
    return meta_capi_send([meta_order_event($name, $order, $eventId, $request)]);
}

function meta_capi_send(array $events): bool
{
    $payload = ['data' => $events];
    $test = (string) (config('meta.test_event_code') ?? '');
    if ($test !== '') {
        $payload['test_event_code'] = $test;
    }
    // Tests and local development: write the events to server/data/meta/ instead of sending them.
    if (config('meta.transport') === 'log') {
        return file_put_contents(data_dir('meta') . '/events.jsonl', json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND) !== false;
    }
    if (!function_exists('curl_init')) {
        log_error('Meta Conversions API: the PHP curl extension is not available on this server.');
        return false;
    }
    $url = 'https://graph.facebook.com/' . PB_META_GRAPH_VERSION . '/' . meta_pixel_id() . '/events';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['data' => json_encode($payload['data']), 'access_token' => (string) config('meta.capi_token')]
            + ($test !== '' ? ['test_event_code' => $test] : [])),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status !== 200) {
        log_error('Meta Conversions API ' . ($events[0]['event_name'] ?? '?') . " failed (HTTP {$status}): " . mb_substr($error !== '' ? $error : (string) $body, 0, 300));
        return false;
    }
    return true;
}
