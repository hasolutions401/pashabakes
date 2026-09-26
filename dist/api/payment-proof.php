<?php
/* A customer uploads a screenshot of their payment (POST multipart: code, token, proof).
 * Only the browser that placed the order knows its token, so nobody can add pictures to someone else's order. */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}
require_same_origin();
if (!rate_allowed('proof_upload', 12, 3600)) {
    json_response(['ok' => false, 'message' => 'Too many uploads. Please wait a little, or email the screenshot to pashabakess@gmail.com.'], 429);
}
rate_hit('proof_upload');

$order = order_for_customer(clean_line($_POST['code'] ?? '', 20), clean_text($_POST['token'] ?? '', 100));
if (!$order) {
    json_response(['ok' => false, 'message' => 'We couldn’t find that order on this device. Please email the screenshot to pashabakess@gmail.com with your order number.'], 404);
}
if ($order['status'] === 'cancelled') {
    json_response(['ok' => false, 'message' => 'This order was cancelled. Please email pashabakess@gmail.com if that’s a mistake.'], 409);
}
if (empty($_FILES['proof']) || !is_array($_FILES['proof']) || is_array($_FILES['proof']['name'] ?? null)) {
    json_response(['ok' => false, 'message' => 'Please choose a screenshot to upload.'], 422);
}

$first = ($order['payment_proof'] ?? '') === '';
try {
    payment_proof_store($order, $_FILES['proof']);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    log_error('Payment screenshot failed: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Sorry, the screenshot could not be saved. Please try again, or email it to pashabakess@gmail.com.'], 500);
}

$order = order_find((int) $order['id']);
$json = json_encode(['ok' => true, 'state' => order_public_state($order)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$early = can_finish_request_early();
if ($early) {
    echo $json;
    finish_request_early();
}
// Pasha hears about the first screenshot for an order (a replacement doesn't email her again).
if ($first && $order['status'] === 'pending') {
    send_proof_alert($order);
}
if (!$early) {
    echo $json;
}
