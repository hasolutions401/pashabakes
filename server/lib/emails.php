<?php
declare(strict_types=1);

/*
 * The three automatic emails:
 *  - admin_alert   → Pasha, as soon as an order is placed (with an "Open order" link)
 *  - receipt       → customer, as soon as an order is placed: order details + how to pay
 *  - confirmation  → customer, when Pasha clicks "Mark as Paid" (includes pickup address)
 * Enquiries from the website are emailed to Pasha as kind "enquiry".
 */

/** Cancellation and refund policy — keep in step with order.html and faq.html. */
function refund_policy_text(): string
{
    return 'Need to cancel? Email pashabakess@gmail.com at least two days before your pickup date for a full refund '
        . 'to the account you paid from. Cancellations made less than two days before pickup can’t be refunded, and '
        . 'treats can’t be returned or exchanged. If Pasha has to cancel your order, you’ll receive a full refund.';
}

function payer_text(array $order): string
{
    return payment_label($order['payment_method']) . ($order['payer_ref'] !== '' ? ' (paying from ' . $order['payer_ref'] . ')' : '');
}

function email_layout(string $heading, string $inner): string
{
    return '<!doctype html><html><body style="margin:0;padding:0;background:#fcf7ee;font-family:Arial,Helvetica,sans-serif;color:#442c25">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fcf7ee;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #eadccb">'
        . '<tr><td style="background:#442c25;padding:20px 28px;color:#fff4e7;font-family:Georgia,serif;font-size:24px;font-weight:bold">Pashabakess</td></tr>'
        . '<tr><td style="padding:28px">'
        . '<h1 style="margin:0 0 16px;font-family:Georgia,serif;font-size:24px;font-weight:normal;color:#442c25">' . e($heading) . '</h1>'
        . $inner
        . '</td></tr>'
        . '<tr><td style="padding:18px 28px;background:#f6e7df;font-size:13px;color:#6e5c52">Handmade gourmet cookies · ' . e(setting('pickup_area'))
        . ' · <a href="mailto:pashabakess@gmail.com" style="color:#a5425b">pashabakess@gmail.com</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_order_table(array $order): string
{
    $rows = '';
    foreach (order_items((int) $order['id']) as $it) {
        $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #f0e6da">' . e($it['cookie_name']) . '</td>'
            . '<td align="right" style="padding:6px 0;border-bottom:1px solid #f0e6da">× ' . (int) $it['quantity'] . '</td></tr>';
    }
    $line = fn(string $label, string $value) => '<tr><td style="padding:4px 12px 4px 0;color:#6e5c52;white-space:nowrap;vertical-align:top">' . e($label) . '</td><td style="padding:4px 0">' . $value . '</td></tr>';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15px;margin:0 0 18px">' . $rows
        . '<tr><td style="padding:10px 0 0;font-weight:bold">' . (int) $order['box_size'] . ' cookies</td><td align="right" style="padding:10px 0 0;font-weight:bold;font-size:18px">' . money((int) $order['total_cents']) . '</td></tr></table>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px;margin:0 0 8px">'
        . $line('Order', '<strong>' . e($order['code']) . '</strong>')
        . $line('Pickup', e(pretty_date($order['pickup_date'])) . '<br>' . e($order['pickup_slot']) . ' (Eastern)')
        . $line('Payment', e(payer_text($order)))
        . '</table>';
}

function email_order_text(array $order): string
{
    $lines = [];
    foreach (order_items((int) $order['id']) as $it) {
        $lines[] = '  ' . $it['quantity'] . ' × ' . $it['cookie_name'];
    }
    return "Order {$order['code']}\n" . implode("\n", $lines)
        . "\n{$order['box_size']} cookies — " . money((int) $order['total_cents'])
        . "\nPickup: " . pretty_date($order['pickup_date']) . ', ' . $order['pickup_slot'] . ' (Eastern)'
        . "\nPayment: " . payer_text($order);
}

function send_admin_alert(array $order): array
{
    $to = setting('notify_email');
    $link = site_url('admin/order.php?id=' . (int) $order['id']);
    $customer = '<p style="font-size:15px;margin:0 0 18px"><strong>' . e($order['customer_name']) . '</strong><br>'
        . '<a href="mailto:' . e($order['email']) . '" style="color:#a5425b">' . e($order['email']) . '</a> · ' . e($order['phone'])
        . ($order['occasion'] !== '' ? '<br>Occasion: ' . e($order['occasion']) : '')
        . ($order['notes'] !== '' ? '<br>Notes: ' . nl2br(e($order['notes'])) : '') . '</p>';
    $from = $order['payer_ref'] !== '' ? ' The customer said they’ll pay from <strong>' . e($order['payer_ref']) . '</strong>.' : '';
    $html = email_layout('New order ' . $order['code'], '<p style="font-size:15px;margin:0 0 18px">Watch your ' . e(payment_label($order['payment_method']))
        . ' for a payment of <strong>' . money((int) $order['total_cents']) . '</strong> with <strong>' . e($order['code']) . '</strong> in the note.'
        . $from . ' When it arrives, mark the order as paid.</p>'
        . email_order_table($order) . $customer
        . '<p style="margin:22px 0 0"><a href="' . e($link) . '" style="background:#442c25;color:#fff7ed;text-decoration:none;padding:13px 22px;border-radius:8px;font-weight:bold;display:inline-block">Open order in admin</a></p>');
    $text = "New order {$order['code']}\n\n" . email_order_text($order)
        . "\n\nCustomer: {$order['customer_name']} · {$order['email']} · {$order['phone']}"
        . ($order['notes'] !== '' ? "\nNotes: {$order['notes']}" : '')
        . "\n\nOpen in admin: {$link}";
    $subject = sprintf('New order %s · %s · pickup %s', $order['code'], money((int) $order['total_cents']), short_date($order['pickup_date']));
    return send_email($to, $subject, $html, $text, 'admin_alert', (int) $order['id'], $order['email']);
}

/** Sent as soon as the order is saved: the order number and how to pay for it. */
function send_customer_receipt(array $order): array
{
    $first = explode(' ', trim($order['customer_name']))[0];
    $app = payment_label($order['payment_method']);
    $handle = payment_handle($order['payment_method']);
    $url = payment_url($order['payment_method']);
    $amount = money((int) $order['total_cents']);
    $payBox = '<div style="background:#f6e7df;border-radius:10px;padding:16px 18px;margin:0 0 18px;font-size:15px;line-height:1.6">'
        . '<strong>How to pay</strong><br>'
        . '1. Send <strong>' . e($amount) . '</strong> to <strong>' . e($handle) . '</strong> on ' . e($app) . '.<br>'
        . '2. Write <strong>' . e($order['code']) . '</strong> in the payment note.'
        . '<p style="margin:14px 0 0"><a href="' . e($url) . '" style="background:#442c25;color:#fff7ed;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:bold;display:inline-block">Open ' . e($app) . '</a></p>'
        . '</div>';
    $html = email_layout('Thank you, ' . $first . '! Now send your payment.',
        '<p style="font-size:15px;margin:0 0 18px">Your order <strong>' . e($order['code']) . '</strong> is saved. Pasha confirms it — and emails you the pickup address — once your payment arrives.</p>'
        . $payBox
        . '<p style="font-size:15px;margin:0 0 18px">' . e(payment_hold_text()) . '</p>'
        . email_order_table($order)
        . '<p style="font-size:14px;color:#6e5c52;margin:18px 0 0">' . e(refund_policy_text()) . '</p>'
        . '<p style="font-size:14px;color:#6e5c52;margin:12px 0 0">Questions or changes? Just reply to this email.</p>');
    $text = "Thank you, {$first}! Your order {$order['code']} is saved.\n\n"
        . "How to pay:\n1. Send {$amount} to {$handle} on {$app} ({$url}).\n2. Write {$order['code']} in the payment note.\n\n"
        . "Pasha confirms your order, and emails you the pickup address, once your payment arrives.\n" . payment_hold_text() . "\n\n"
        . email_order_text($order)
        . "\n\n" . refund_policy_text() . "\nQuestions? Reply to this email.";
    return send_email($order['email'], "Order {$order['code']} saved — how to pay", $html, $text, 'receipt', (int) $order['id']);
}

function send_customer_confirmation(array $order): array
{
    $first = explode(' ', trim($order['customer_name']))[0];
    $address = trim(setting('pickup_address'));
    $addressHtml = $address !== ''
        ? '<div style="background:#f6e7df;border-radius:10px;padding:16px 18px;margin:0 0 18px;font-size:15px"><strong>Pickup address</strong><br>' . nl2br(e($address)) . '</div>'
        : '';
    $html = email_layout('Your order is confirmed, ' . $first . '!',
        '<p style="font-size:15px;margin:0 0 18px">Your payment has been received and your cookies are scheduled for baking. See you on '
        . '<strong>' . e(pretty_date($order['pickup_date'])) . '</strong>, ' . e($order['pickup_slot']) . ' (Eastern).</p>'
        . $addressHtml
        . email_order_table($order)
        . '<p style="font-size:14px;color:#6e5c52;margin:18px 0 0">' . e(refund_policy_text()) . '</p>');
    $text = "Your order is confirmed, {$first}!\n\nPayment received — your cookies are scheduled for baking.\n\n"
        . ($address !== '' ? "Pickup address:\n{$address}\n\n" : '')
        . email_order_text($order)
        . "\n\n" . refund_policy_text();
    return send_email($order['email'], "Order {$order['code']} confirmed — see you on " . short_date($order['pickup_date']), $html, $text, 'confirmation', (int) $order['id']);
}

function send_test_email(string $to): array
{
    $html = email_layout('Test email', '<p style="font-size:15px;margin:0">Good news — email sending works. New orders and confirmations will be delivered.</p>');
    return send_email($to, 'Pashabakess test email', $html, 'Good news — email sending works.', 'test');
}

/** New website enquiry → Pasha. Replies go straight to the customer. */
function send_enquiry_alert(array $q): array
{
    $to = setting('notify_email');
    $rows = [['Type', $q['type']], ['Name', $q['name']], ['Email', $q['email']]];
    if (($q['order_ref'] ?? '') !== '') {
        $rows[] = ['Order number', $q['order_ref']];
    }
    if (!empty($q['event_date'])) {
        $rows[] = ['Event date', pretty_date($q['event_date'])];
    }
    if ($q['quantity'] !== '') {
        $rows[] = ['Quantity', $q['quantity']];
    }
    $table = '';
    $text = '';
    foreach ($rows as [$label, $value]) {
        $table .= '<tr><td style="padding:4px 12px 4px 0;color:#6e5c52;white-space:nowrap;vertical-align:top">' . e($label) . '</td><td style="padding:4px 0">' . e($value) . '</td></tr>';
        $text .= "{$label}: {$value}\n";
    }
    $html = email_layout('New enquiry: ' . $q['type'],
        '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px;margin:0 0 18px">' . $table . '</table>'
        . '<div style="background:#f6e7df;border-radius:10px;padding:16px 18px;font-size:15px;line-height:1.6">' . nl2br(e($q['message'])) . '</div>'
        . '<p style="font-size:14px;color:#6e5c52;margin:18px 0 0">Reply to this email to answer ' . e($q['name']) . ' directly.</p>');
    return send_email($to, "New enquiry: {$q['type']} · {$q['name']}" . (($q['order_ref'] ?? '') !== '' ? " · {$q['order_ref']}" : ''), $html, $text . "\n" . $q['message'], 'enquiry', null, $q['email']);
}
