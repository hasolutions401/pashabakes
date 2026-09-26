<?php
declare(strict_types=1);

/*
 * Paying for an order: the pay-now links, the payment deadline shown as a countdown,
 * the payment screenshot a customer can upload, and who placed an order
 * (a customer reference and a hashed network address — never the raw IP).
 */

/** Payment screenshots: largest upload accepted, and the longest side they are stored at. */
const PB_PROOF_MAX_BYTES = 10 * 1024 * 1024;
const PB_PROOF_MAX_SIDE = 1800;

/** Same email address = same reference, e.g. "C-3F9A21". Lets Pasha see a customer's orders together. */
function customer_ref(string $email): string
{
    $email = mb_strtolower(trim($email));
    return $email === '' ? '' : 'C-' . strtoupper(substr(hash_hmac('sha256', 'customer|' . $email, (string) config('setup_key')), 0, 6));
}

/** The visitor's network address, one-way hashed with the site's secret key (the address itself is never stored). */
function order_ip_hash(): string
{
    return substr(hash_hmac('sha256', 'order-ip|' . client_ip(), (string) config('setup_key')), 0, 16);
}

/** Other orders placed from the same network address (newest first), to spot repeated fake orders. */
function orders_from_same_network(array $order, int $limit = 10): array
{
    if (($order['ip_hash'] ?? '') === '') {
        return [];
    }
    return db_all("SELECT id, code, status, customer_name, created_at FROM orders WHERE ip_hash = ? AND id <> ? ORDER BY id DESC LIMIT {$limit}",
        [$order['ip_hash'], (int) $order['id']]);
}

/**
 * Pay-now link with the amount (and on Venmo the order number) filled in. On a phone these open the
 * Venmo / Cash App app when it is installed, and their website when it isn't.
 */
function payment_link(string $method, int $cents, string $code): string
{
    $amount = number_format($cents / 100, 2, '.', '');
    if ($method === 'cashapp') {
        return cashapp_url() . '/' . rtrim(rtrim($amount, '0'), '.');
    }
    return 'https://venmo.com/' . rawurlencode(setting('venmo_handle')) . '?' . http_build_query(
        ['txn' => 'pay', 'audience' => 'private', 'amount' => $amount, 'note' => "Pashabakess order {$code}"], '', '&', PHP_QUERY_RFC3986);
}

/** When the payment time for this order runs out, or null when there's no deadline. */
function payment_deadline(array $order): ?DateTimeImmutable
{
    $h = payment_hours();
    $placed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $order['created_at'], new DateTimeZone(PB_TZ));
    return $h > 0 && $placed ? $placed->modify("+{$h} hours") : null;
}

/** What the customer's payment screen shows (also after they come back from the payment app). */
function order_public_state(array $order): array
{
    $deadline = payment_deadline($order);
    return [
        'code' => $order['code'],
        'status' => $order['status'],
        'paid' => in_array($order['status'], ['paid', 'completed'], true),
        'cancelled' => $order['status'] === 'cancelled',
        'proofUploaded' => ($order['payment_proof'] ?? '') !== '',
        // Absolute time (ISO 8601 with offset): the countdown never restarts when the customer switches apps.
        'payBy' => $deadline && $order['status'] === 'pending' ? $deadline->format(DATE_ATOM) : null,
        'serverNow' => (new DateTimeImmutable('now', new DateTimeZone(PB_TZ)))->format(DATE_ATOM),
    ];
}

/** The order for this order number, but only for the browser that placed it (it knows the order's private token). */
function order_for_customer(string $code, string $token): ?array
{
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    if ($code === '' || strlen($token) < 16) {
        return null;
    }
    $order = db_one('SELECT * FROM orders WHERE code = ?', [$code]);
    return $order && hash_equals((string) $order['client_token'], $token) ? $order : null;
}

function payment_proof_dir(): string
{
    return data_dir('payment-proofs');
}

/**
 * Saves a payment screenshot for an order (replacing an earlier one). Like menu photos, the picture is
 * re-drawn as a new JPEG, which drops anything hidden in the file (e.g. location data).
 */
function payment_proof_store(array $order, array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'That picture is too large. Please use one under 10 MB.'
            : 'The screenshot could not be uploaded. Please try again.');
    }
    if (($file['size'] ?? 0) > PB_PROOF_MAX_BYTES) {
        throw new RuntimeException('That picture is too large. Please use one under 10 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Please upload a screenshot or photo (JPG, PNG or WebP).');
    }
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('Screenshots can’t be uploaded right now. Please email it to pashabakess@gmail.com instead.');
    }
    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        throw new RuntimeException('That picture could not be read. Please try another screenshot.');
    }
    $src = cookie_photo_orient($src, $file['tmp_name']);
    // Long phone screenshots: limit the longest side, keep them readable.
    $w = imagesx($src);
    $h = imagesy($src);
    $maxWidth = $h > $w ? (int) floor(PB_PROOF_MAX_SIDE * $w / max($h, 1)) : PB_PROOF_MAX_SIDE;
    $name = strtolower($order['code']) . '-' . bin2hex(random_bytes(8)) . '.jpg';
    if (!cookie_photo_save($src, payment_proof_dir() . '/' . $name, max(200, $maxWidth))) {
        throw new RuntimeException('The screenshot could not be saved. Please try again.');
    }
    $old = (string) ($order['payment_proof'] ?? '');
    db_exec('UPDATE orders SET payment_proof = ?, payment_proof_at = ? WHERE id = ?', [$name, now_str(), (int) $order['id']]);
    payment_proof_remove_file($old);
}

/** Full path of an order's screenshot, or null. */
function payment_proof_path(array $order): ?string
{
    $name = (string) ($order['payment_proof'] ?? '');
    if (!preg_match('/^[a-z0-9]+-[a-f0-9]{16}\.jpg$/', $name)) {
        return null;
    }
    $path = payment_proof_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}

function payment_proof_remove_file(string $name): void
{
    if (preg_match('/^[a-z0-9]+-[a-f0-9]{16}\.jpg$/', $name)) {
        @unlink(payment_proof_dir() . '/' . $name);
    }
}

/** Stops other websites from calling the customer endpoints (browsers send Origin on cross-site requests). */
function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $host = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
    if (parse_url($origin, PHP_URL_HOST) !== $host) {
        json_response(['ok' => false, 'message' => 'This can only be used from the Pashabakess website.'], 403);
    }
}

/** Tells Pasha a customer uploaded a payment screenshot, with a link straight to the order. */
function send_proof_alert(array $order): array
{
    $link = site_url('admin/order.php?id=' . (int) $order['id']);
    $amount = money((int) $order['total_cents']);
    $inner = email_panel(e($order['customer_name']) . ' uploaded a screenshot of their <strong>' . e(payment_label($order['payment_method']))
            . '</strong> payment for <strong>' . e($order['code']) . '</strong> (' . e($amount) . '). Check it matches what arrived, then tap <strong>Confirm payment</strong>.',
            '#fff4d9', '#efd9a3')
        . '<p style="margin:24px 0 0">' . email_button($link, 'See the screenshot →') . '</p>';
    $html = email_layout('Payment screenshot for ' . $order['code'], $inner, [
        'eyebrow' => 'PAYMENT SCREENSHOT · ' . $amount, 'badge_color' => '#fff4d9',
        'preheader' => "{$order['customer_name']} · {$amount} · {$order['code']}",
    ]);
    $text = "{$order['customer_name']} uploaded a payment screenshot for {$order['code']} ({$amount}).\n\nOpen the order: {$link}";
    return send_email(setting('notify_email'), "Payment screenshot · {$order['code']} · {$amount}", $html, $text, 'proof_alert', (int) $order['id']);
}
