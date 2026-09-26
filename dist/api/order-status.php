<?php
/* The payment screen asks this when it opens again (e.g. after the customer comes back from Venmo):
 * payment deadline, whether it's paid, whether a screenshot was uploaded. POST {code, token}. */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}
require_same_origin();
if (!rate_allowed('order_status', 240, 3600)) {
    json_response(['ok' => false, 'message' => 'Please wait a moment and try again.'], 429);
}
rate_hit('order_status');

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 2000), true);
$order = is_array($in) ? order_for_customer(clean_line($in['code'] ?? '', 20), clean_text($in['token'] ?? '', 100)) : null;
if (!$order) {
    json_response(['ok' => false, 'message' => 'Order not found.'], 404);
}
json_response(['ok' => true, 'state' => order_public_state($order)]);
