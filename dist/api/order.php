<?php
/* Places an order (POST, JSON). */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

// Only accept submissions from this website.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $host = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
    if ($originHost !== $host) {
        json_response(['ok' => false, 'message' => 'Orders can only be placed from the Pashabakess website.'], 403);
    }
}

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode((string) $raw, true);
if (!is_array($in)) {
    json_response(['ok' => false, 'message' => 'Something went wrong with the form. Please refresh the page and try again.'], 400);
}

// Hidden field that people never fill in; bots often do.
if (!empty($in['website'])) {
    json_response(['ok' => true, 'order' => ['code' => 'PB0000']]);
}

if (!accepting_orders()) {
    json_response(['ok' => false, 'message' => setting('closed_message')], 409);
}

// Generous limits: many phones can share one IP address on mobile networks.
if (!rate_allowed('order_attempt', 150, 3600)) {
    json_response(['ok' => false, 'message' => 'Too many attempts. Please wait a little and try again, or email pashabakess@gmail.com.'], 429);
}
rate_hit('order_attempt');

[$data, $errors] = order_validate($in);
if ($errors) {
    json_response(['ok' => false, 'message' => 'Please check a few details.', 'errors' => $errors], 422);
}

if (!rate_allowed('order_created', 30, 3600)) {
    json_response(['ok' => false, 'message' => 'You’ve placed several orders in a short time. Please email pashabakess@gmail.com if you need more.'], 429);
}

try {
    [$order, $created] = order_create($data);
} catch (Throwable $e) {
    error_log('[pashabakess] Order save failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Sorry, we could not save your order. Nothing was placed. Please try again in a moment or email pashabakess@gmail.com.'], 500);
}

$response = [
    'ok' => true,
    'order' => [
        'code' => $order['code'],
        'total' => money((int) $order['total_cents']),
        'boxSize' => (int) $order['box_size'],
        'items' => array_map(fn($i) => ['name' => $i['cookie_name'], 'qty' => (int) $i['quantity']], order_items((int) $order['id'])),
        'pickupDate' => pretty_date($order['pickup_date']),
        'pickupSlot' => $order['pickup_slot'],
        'payment' => payment_label($order['payment_method']),
        'email' => $order['email'],
    ],
];

if (!$created) {
    json_response($response);
}

rate_hit('order_created');

// Reply to the customer right away, then send the emails.
$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
http_response_code(201);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (function_exists('fastcgi_finish_request')) {
    echo $json;
    fastcgi_finish_request();
    send_admin_alert($order);
    send_customer_receipt($order);
} else {
    ignore_user_abort(true);
    send_admin_alert($order);
    send_customer_receipt($order);
    echo $json;
}
