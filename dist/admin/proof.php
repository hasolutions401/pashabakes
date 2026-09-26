<?php
/* Shows a customer's payment screenshot (admin only; the files are kept outside the website folder). */
require __DIR__ . '/_init.php';
require_admin();

$order = order_find((int) ($_GET['id'] ?? 0));
$path = $order ? payment_proof_path($order) : null;
if (!$path) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No screenshot for this order.');
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . strtolower($order['code']) . '-payment.jpg"');
readfile($path);
