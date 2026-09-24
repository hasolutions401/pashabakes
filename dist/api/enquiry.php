<?php
/*
 * Receives an enquiry from the Contact / Celebrations form (POST).
 * JSON from the website's script, or a plain form post when JavaScript is off.
 */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

$isJson = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');

/** Replies as JSON for the script, or as a small page for a plain form post. */
function enquiry_reply(bool $isJson, bool $ok, string $message, array $errors = [], int $status = 200): never
{
    if ($isJson) {
        json_response(['ok' => $ok, 'message' => $message] + ($errors ? ['errors' => $errors] : []), $status);
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $list = $errors ? '<ul>' . implode('', array_map(fn($m) => '<li>' . e($m) . '</li>', $errors)) . '</ul>' : '';
    $back = $ok ? '<a href="../index.html">Back to the website</a>' : '<a href="javascript:history.back()">Go back and check your message</a>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . ($ok ? 'Message sent' : 'Please check your message') . ' | Pashabakess</title>'
        . '<style>body{margin:0;background:#fcf7ee;color:#442c25;font:17px/1.6 system-ui,sans-serif}main{max-width:560px;margin:12vh auto;padding:0 20px}'
        . 'h1{font-family:Georgia,serif;font-weight:600}a{color:#a5425b;font-weight:600}</style></head><body><main>'
        . '<h1>' . ($ok ? 'Thank you!' : 'Please check your message') . '</h1><p>' . e($message) . '</p>' . $list . '<p>' . $back . '</p>'
        . '</main></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    enquiry_reply($isJson, false, 'Please use the enquiry form on the website.', [], 405);
}

// Only accept submissions from this website.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $origin !== 'null') {
    $host = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
    if (parse_url($origin, PHP_URL_HOST) !== $host) {
        enquiry_reply($isJson, false, 'Enquiries can only be sent from the Pashabakess website.', [], 403);
    }
}

$in = $isJson ? json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true) : $_POST;
if (!is_array($in)) {
    enquiry_reply($isJson, false, 'Something went wrong with the form. Please refresh the page and try again.', [], 400);
}

// Hidden field that people never fill in; bots often do.
if (!empty($in['website'])) {
    enquiry_reply($isJson, true, 'Your message is on its way to Pasha.');
}

if (!rate_allowed('enquiry', 20, 3600)) {
    enquiry_reply($isJson, false, 'Too many messages in a short time. Please email pashabakess@gmail.com instead.', [], 429);
}
rate_hit('enquiry');

[$data, $errors] = enquiry_validate($in);
if ($errors) {
    enquiry_reply($isJson, false, 'Please check a few details.', $errors, 422);
}

try {
    [$enquiry, $created] = enquiry_create($data);
} catch (Throwable $e) {
    error_log('[pashabakess] Enquiry save failed: ' . $e->getMessage());
    enquiry_reply($isJson, false, 'Sorry, your message could not be sent. Please try again in a moment or email pashabakess@gmail.com.', [], 500);
}

$thanks = 'Your message is on its way to Pasha. She’ll reply to ' . $data['email'] . '.';
if (!$created) {
    enquiry_reply($isJson, true, $thanks);
}

// Reply to the customer first, then email Pasha (the enquiry is already saved in admin).
if (function_exists('fastcgi_finish_request')) {
    if ($isJson) {
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true, 'message' => $thanks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fastcgi_finish_request();
        send_enquiry_alert($enquiry);
        exit;
    }
}
ignore_user_abort(true);
send_enquiry_alert($enquiry);
enquiry_reply($isJson, true, $thanks, [], 201);
