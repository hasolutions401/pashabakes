<?php
declare(strict_types=1);

/*
 * The three automatic emails:
 *  - admin_alert   → Pasha, as soon as an order is placed (with an "Open order" link)
 *  - receipt       → customer, as soon as an order is placed
 *  - confirmation  → customer, when Pasha clicks "Mark as Paid" (includes pickup address)
 */

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
        . $line('Payment', e(payment_label($order['payment_method'])) . ' from ' . e($order['payer_ref']))
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
        . "\nPayment: " . payment_label($order['payment_method']) . ' from ' . $order['payer_ref'];
}

function send_admin_alert(array $order): array
{
    $to = setting('notify_email');
    $link = site_url('admin/order.php?id=' . (int) $order['id']);
    $customer = '<p style="font-size:15px;margin:0 0 18px"><strong>' . e($order['customer_name']) . '</strong><br>'
        . '<a href="mailto:' . e($order['email']) . '" style="color:#a5425b">' . e($order['email']) . '</a> · ' . e($order['phone'])
        . ($order['occasion'] !== '' ? '<br>Occasion: ' . e($order['occasion']) : '')
        . ($order['notes'] !== '' ? '<br>Notes: ' . nl2br(e($order['notes'])) : '') . '</p>';
    $html = email_layout('New order ' . $order['code'], '<p style="font-size:15px;margin:0 0 18px">Check your ' . e(payment_label($order['payment_method']))
        . ' for a payment of <strong>' . money((int) $order['total_cents']) . '</strong> from <strong>' . e($order['payer_ref']) . '</strong>, then mark the order as paid.</p>'
        . email_order_table($order) . $customer
        . '<p style="margin:22px 0 0"><a href="' . e($link) . '" style="background:#442c25;color:#fff7ed;text-decoration:none;padding:13px 22px;border-radius:8px;font-weight:bold;display:inline-block">Open order in admin</a></p>');
    $text = "New order {$order['code']}\n\n" . email_order_text($order)
        . "\n\nCustomer: {$order['customer_name']} · {$order['email']} · {$order['phone']}"
        . ($order['notes'] !== '' ? "\nNotes: {$order['notes']}" : '')
        . "\n\nOpen in admin: {$link}";
    $subject = sprintf('New order %s · %s · pickup %s', $order['code'], money((int) $order['total_cents']), short_date($order['pickup_date']));
    return send_email($to, $subject, $html, $text, 'admin_alert', (int) $order['id'], $order['email']);
}

function send_customer_receipt(array $order): array
{
    $first = explode(' ', trim($order['customer_name']))[0];
    $html = email_layout('Thank you, ' . $first . '! Your order is in.',
        '<p style="font-size:15px;margin:0 0 18px">We’ve received order <strong>' . e($order['code']) . '</strong>. Pasha will check your '
        . e(payment_label($order['payment_method'])) . ' payment and email you a confirmation with the pickup address.</p>'
        . email_order_table($order)
        . '<p style="font-size:14px;color:#6e5c52;margin:18px 0 0">Questions or changes? Just reply to this email.</p>');
    $text = "Thank you, {$first}! Your order is in.\n\n" . email_order_text($order)
        . "\n\nPasha will check your payment and email you a confirmation with the pickup address.\nQuestions? Reply to this email.";
    return send_email($order['email'], "We’ve received your order {$order['code']}", $html, $text, 'receipt', (int) $order['id']);
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
        . '<p style="font-size:14px;color:#6e5c52;margin:18px 0 0">Need to cancel? Please email at least 2 days before pickup. Treats cannot be returned or exchanged.</p>');
    $text = "Your order is confirmed, {$first}!\n\nPayment received — your cookies are scheduled for baking.\n\n"
        . ($address !== '' ? "Pickup address:\n{$address}\n\n" : '')
        . email_order_text($order)
        . "\n\nNeed to cancel? Please email at least 2 days before pickup. Treats cannot be returned or exchanged.";
    return send_email($order['email'], "Order {$order['code']} confirmed — see you on " . short_date($order['pickup_date']), $html, $text, 'confirmation', (int) $order['id']);
}

function send_test_email(string $to): array
{
    $html = email_layout('Test email', '<p style="font-size:15px;margin:0">Good news — email sending works. New orders and confirmations will be delivered.</p>');
    return send_email($to, 'Pashabakess test email', $html, 'Good news — email sending works.', 'test');
}
