<?php
declare(strict_types=1);

/*
 * The three automatic emails:
 *  - admin_alert   → Pasha, as soon as an order is placed (with an "Open order" link)
 *  - receipt       → customer, as soon as an order is placed: order details + how to pay
 *  - confirmation  → customer, when Pasha clicks "Mark as Paid" (includes pickup address)
 * Inquiries from the website are emailed to Pasha as kind "enquiry".
 */

/** Cancellation and refund policy — keep in step with order.html and faq.html. */
function refund_policy_text(): string
{
    return 'Need to cancel or reschedule? Email pashabakess@gmail.com at least two calendar days before your pickup date '
        . '(for a Saturday pickup, by Thursday) for a full refund to the account you paid from, sent within 3–5 business days. '
        . 'Cancellations with less notice can’t be refunded, and treats can’t be returned or exchanged. Orders not picked up '
        . 'within two days of the pickup date can’t be refunded. If Pasha has to cancel your order, you’ll receive a full refund.';
}

function payer_text(array $order): string
{
    return payment_label($order['payment_method']) . ($order['payer_ref'] !== '' ? ' (paying from ' . $order['payer_ref'] . ')' : '');
}

/* ——— Email building blocks (inline styles and tables, so they look right in Gmail, Outlook and phones) ——— */

const EM_BROWN = '#442c25';
const EM_ROSE = '#a5425b';
const EM_MUTED = '#6e5c52';
const EM_CREAM = '#fcf7ee';
const EM_BLUSH = '#f6e7df';
const EM_SERIF = "Georgia,'Times New Roman',serif";
const EM_SANS = 'Helvetica,Arial,sans-serif';

/**
 * The shared email frame: logo header, heading, content and footer.
 * $opts: 'eyebrow' (small label above the heading), 'intro' (paragraph under it),
 *        'preheader' (inbox preview text), 'badge_color'.
 */
function email_layout(string $heading, string $inner, array $opts = []): string
{
    $logo = site_url('logo-192.jpg');
    $eyebrow = isset($opts['eyebrow'])
        ? '<p style="margin:0 0 12px"><span style="display:inline-block;padding:5px 12px;border-radius:999px;background:' . ($opts['badge_color'] ?? EM_BLUSH)
          . ';color:' . EM_BROWN . ';font:bold 11px ' . EM_SANS . ';letter-spacing:.14em">' . e($opts['eyebrow']) . '</span></p>'
        : '';
    $intro = isset($opts['intro']) ? '<p style="margin:10px 0 0;font:15px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">' . $opts['intro'] . '</p>' : '';
    $pre = isset($opts['preheader']) ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . e($opts['preheader']) . '</div>' : '';
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . e($heading) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f3e9de;color:' . EM_BROWN . '">' . $pre
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3e9de"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #eadccb">'
        // Header
        . '<tr><td align="center" style="background:' . EM_CREAM . ';padding:28px 24px 20px;border-bottom:1px solid #f0e2d4">'
        . '<img src="' . e($logo) . '" width="76" height="76" alt="Pashabakess" style="display:block;border:0;border-radius:50%;margin:0 auto 10px">'
        . '<div style="font:bold 26px ' . EM_SERIF . ';color:' . EM_BROWN . '">Pashabakess</div>'
        . '<div style="font:bold 10px ' . EM_SANS . ';letter-spacing:.2em;color:' . EM_ROSE . ';margin-top:4px">HANDMADE • HOME BAKED • HEARTFELT</div>'
        . '</td></tr>'
        // Heading
        . '<tr><td style="padding:30px 32px 6px">' . $eyebrow
        . '<h1 style="margin:0;font:normal 28px/1.25 ' . EM_SERIF . ';color:' . EM_BROWN . '">' . e($heading) . '</h1>' . $intro . '</td></tr>'
        // Content
        . '<tr><td style="padding:18px 32px 30px;font:15px/1.6 ' . EM_SANS . ';color:' . EM_BROWN . '">' . $inner . '</td></tr>'
        // Footer
        . '<tr><td align="center" style="background:' . EM_BROWN . ';padding:24px 28px;font:13px/1.7 ' . EM_SANS . ';color:#f1dfd2">'
        . '<div style="font:italic 18px ' . EM_SERIF . ';color:#f3aec0;margin-bottom:6px">A little cookie. A lot of happy.</div>'
        . 'Handmade gourmet cookies · Pickup in ' . e(setting('pickup_area')) . '<br>'
        . '<a href="' . e(site_url()) . '" style="color:#fff4e7">Website</a> &nbsp;·&nbsp; '
        . '<a href="https://www.instagram.com/pashabakess/" style="color:#fff4e7">Instagram</a> &nbsp;·&nbsp; '
        . '<a href="mailto:pashabakess@gmail.com" style="color:#fff4e7">pashabakess@gmail.com</a>'
        . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** A rounded button. $color: brown (default) or rose. */
function email_button(string $url, string $label, string $color = EM_BROWN): string
{
    return '<a href="' . e($url) . '" style="display:inline-block;background:' . $color . ';color:#fff7ed;text-decoration:none;'
        . 'padding:13px 22px;border-radius:999px;font:bold 14px ' . EM_SANS . ';margin:4px 6px 4px 0">' . e($label) . '</a>';
}

/** Section title inside an email. */
function email_section(string $title): string
{
    return '<p style="margin:26px 0 10px;font:bold 11px ' . EM_SANS . ';letter-spacing:.16em;color:' . EM_ROSE . '">' . e(mb_strtoupper($title)) . '</p>';
}

/** Ordered → Paid → Pickup tracker. $done = how many steps are complete (1 or 2). */
function email_steps(int $done): string
{
    $steps = ['Order placed', 'Payment received', 'Ready for pickup'];
    $cells = '';
    foreach ($steps as $i => $label) {
        $state = $i < $done ? 'done' : ($i === $done ? 'now' : 'todo');
        $bg = ['done' => EM_BROWN, 'now' => EM_ROSE, 'todo' => '#efe3d8'][$state];
        $fg = $state === 'todo' ? EM_MUTED : '#ffffff';
        $mark = $state === 'done' ? '&#10003;' : (string) ($i + 1);
        $cells .= '<td align="center" width="33%" style="padding:0 4px;vertical-align:top">'
            . '<div style="width:34px;height:34px;line-height:34px;border-radius:50%;background:' . $bg . ';color:' . $fg . ';font:bold 15px ' . EM_SANS . ';margin:0 auto 6px">' . $mark . '</div>'
            . '<div style="font:' . ($state === 'todo' ? 'normal' : 'bold') . ' 12px ' . EM_SANS . ';color:' . ($state === 'todo' ? EM_MUTED : EM_BROWN) . '">' . $label . '</div></td>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 8px;background:' . EM_CREAM . ';border-radius:14px"><tr><td style="padding:16px 8px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table></td></tr></table>';
}

/** Absolute URL of a small photo for a flavor (the 160px copy for Pasha's photos). */
function email_image(?string $image): string
{
    $image = (string) $image;
    if ($image === '') {
        return site_url('logo-192.jpg');
    }
    return cookie_image_is_local($image) ? site_url(cookie_image_variants($image)['sm']) : $image;
}

/** The cookies in an order, each with a small photo, then the box size and total. */
function email_order_table(array $order): string
{
    $items = db_all('SELECT i.cookie_name, i.quantity, c.image, c.type FROM order_items i LEFT JOIN cookies c ON c.id = i.cookie_id
        WHERE i.order_id = ? ORDER BY i.id', [(int) $order['id']]);
    $rows = '';
    foreach ($items as $it) {
        $kind = ($it['type'] ?? '') === 'seasonal' ? 'Monthly special' : 'Signature';
        $rows .= '<tr>'
            . '<td width="64" style="padding:10px 0;border-bottom:1px solid #f0e6da"><img src="' . e(email_image($it['image'] ?? '')) . '" width="56" height="56" alt=""'
            . ' style="display:block;width:56px;height:56px;border-radius:12px;object-fit:cover;border:0;background:' . EM_BLUSH . '"></td>'
            . '<td style="padding:10px 0 10px 12px;border-bottom:1px solid #f0e6da"><div style="font:bold 15px ' . EM_SANS . ';color:' . EM_BROWN . '">' . e($it['cookie_name']) . '</div>'
            . '<div style="font:12px ' . EM_SANS . ';color:' . EM_MUTED . '">' . $kind . '</div></td>'
            . '<td align="right" style="padding:10px 0;border-bottom:1px solid #f0e6da;font:bold 15px ' . EM_SANS . ';white-space:nowrap">&times; ' . (int) $it['quantity'] . '</td>'
            . '</tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows
        . '<tr><td colspan="2" style="padding:14px 0 0;font:15px ' . EM_SANS . ';color:' . EM_MUTED . '">' . (int) $order['box_size'] . '-cookie box · mix &amp; match</td>'
        . '<td align="right" style="padding:14px 0 0;font:bold 26px ' . EM_SERIF . ';color:' . EM_BROWN . '">' . money((int) $order['total_cents']) . '</td></tr></table>';
}

/** Two-column list of order facts. $rows = [[label, html], ...] */
function email_facts(array $rows): string
{
    $out = '';
    foreach ($rows as [$label, $html]) {
        $out .= '<tr><td style="padding:7px 12px 7px 0;font:13px ' . EM_SANS . ';color:' . EM_MUTED . ';white-space:nowrap;vertical-align:top;width:110px">' . e($label) . '</td>'
            . '<td style="padding:7px 0;font:15px ' . EM_SANS . ';color:' . EM_BROWN . '">' . $html . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #f0e6da">' . $out . '</table>';
}

/** A soft coloured panel. */
function email_panel(string $inner, string $bg = EM_BLUSH, string $border = '#ecd5c9'): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 0;background:' . $bg . ';border:1px solid ' . $border
        . ';border-radius:16px"><tr><td style="padding:20px 22px;font:15px/1.6 ' . EM_SANS . ';color:' . EM_BROWN . '">' . $inner . '</td></tr></table>';
}

/** Google Calendar link for the pickup (Eastern time), e.g. slot "2:00 PM – 3:00 PM". */
function email_calendar_url(array $order, string $address): string
{
    $date = parse_date($order['pickup_date']);
    if (!$date || !preg_match('/(\d{1,2}:\d{2}\s*[AP]M)\s*[–-]\s*(\d{1,2}:\d{2}\s*[AP]M)/iu', $order['pickup_slot'], $m)) {
        return '';
    }
    $at = fn(string $t) => $date->format('Ymd') . 'T' . date('His', strtotime($t));
    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => 'Pick up Pashabakess cookies (' . $order['code'] . ')',
        'dates' => $at($m[1]) . '/' . $at($m[2]),
        'ctz' => PB_TZ,
        'details' => 'Order ' . $order['code'] . ' · ' . $order['box_size'] . ' cookies',
        'location' => $address !== '' ? $address : setting('pickup_area'),
    ]);
}

/**
 * Plain-text order summary. Customer emails leave out the free-text "paying from" name:
 * anyone can type anything there, and it must not be mailed out under Pasha's name.
 */
function email_order_text(array $order, bool $forCustomer = false): string
{
    $lines = [];
    foreach (order_items((int) $order['id']) as $it) {
        $lines[] = '  ' . $it['quantity'] . ' × ' . $it['cookie_name'];
    }
    return "Order {$order['code']}\n" . implode("\n", $lines)
        . "\n{$order['box_size']} cookies — " . money((int) $order['total_cents'])
        . "\nPickup: " . pretty_date($order['pickup_date']) . ', ' . $order['pickup_slot'] . ' (Eastern)'
        . "\nPayment: " . ($forCustomer ? payment_label($order['payment_method']) : payer_text($order));
}

/** First name for a greeting (short, so a long "name" can't turn the email heading into a message). */
function email_first_name(array $order): string
{
    return mb_substr(explode(' ', trim($order['customer_name']))[0], 0, 30);
}

function send_admin_alert(array $order): array
{
    $to = setting('notify_email');
    $link = site_url('admin/order.php?id=' . (int) $order['id']);
    $amount = money((int) $order['total_cents']);
    $from = $order['payer_ref'] !== '' ? ' The customer said they’ll pay from <strong>' . e($order['payer_ref']) . '</strong>.' : '';
    $inner = email_panel('Watch your <strong>' . e(payment_label($order['payment_method'])) . '</strong> for <strong>' . e($amount) . '</strong> with <strong>'
            . e($order['code']) . '</strong> in the note.' . $from . ' When it arrives, open the order and tap <strong>Mark as Paid</strong>.', '#fff4d9', '#efd9a3')
        . email_section('Cookies') . email_order_table($order)
        . email_section('Details') . email_facts([
            ['Pickup', e(pretty_date($order['pickup_date'])) . '<br>' . e($order['pickup_slot']) . ' (Eastern)'],
            ['Customer', '<strong>' . e($order['customer_name']) . '</strong><br><a href="mailto:' . e($order['email']) . '" style="color:' . EM_ROSE . '">' . e($order['email']) . '</a> · ' . e($order['phone'])],
            ['Payment', e(payer_text($order))],
        ] + ($order['occasion'] !== '' ? [3 => ['Occasion', e($order['occasion'])]] : []) + ($order['notes'] !== '' ? [4 => ['Notes', nl2br(e($order['notes']))]] : []))
        . '<p style="margin:24px 0 0">' . email_button($link, 'Open order in admin →') . '</p>';
    $html = email_layout('New order ' . $order['code'], $inner, [
        'eyebrow' => 'NEW ORDER · ' . $amount, 'badge_color' => '#fff4d9',
        'intro' => e($order['customer_name']) . ' ordered ' . (int) $order['box_size'] . ' cookies for ' . e(short_date($order['pickup_date'])) . '.',
        'preheader' => "{$order['customer_name']} · {$amount} · pickup " . short_date($order['pickup_date']),
    ]);
    $text = "New order {$order['code']}\n\n" . email_order_text($order)
        . "\n\nCustomer: {$order['customer_name']} · {$order['email']} · {$order['phone']}"
        . ($order['notes'] !== '' ? "\nNotes: {$order['notes']}" : '')
        . "\n\nOpen in admin: {$link}";
    $subject = sprintf('New order %s · %s · pickup %s', $order['code'], $amount, short_date($order['pickup_date']));
    return send_email($to, $subject, $html, $text, 'admin_alert', (int) $order['id'], $order['email']);
}

/** Sent as soon as the order is saved: the order number and how to pay for it. */
function send_customer_receipt(array $order): array
{
    $first = email_first_name($order);
    $app = payment_label($order['payment_method']);
    $handle = payment_handle($order['payment_method']);
    $url = payment_url($order['payment_method']);
    $amount = money((int) $order['total_cents']);

    $pay = email_panel(
        '<p style="margin:0 0 4px;font:bold 11px ' . EM_SANS . ';letter-spacing:.16em;color:' . EM_ROSE . '">AMOUNT TO PAY</p>'
        . '<p style="margin:0 0 12px;font:bold 38px ' . EM_SERIF . ';color:' . EM_BROWN . '">' . e($amount) . '</p>'
        . '<p style="margin:0 0 6px"><strong>1.</strong> Send it to <strong>' . e($handle) . '</strong> on ' . e($app) . '.</p>'
        . '<p style="margin:0 0 14px"><strong>2.</strong> Write this in the payment note:</p>'
        . '<p style="margin:0 0 16px"><span style="display:inline-block;padding:10px 18px;border:2px dashed ' . EM_ROSE . ';border-radius:12px;background:#ffffff;'
        . 'font:bold 22px ' . EM_SANS . ';letter-spacing:.08em;color:' . EM_BROWN . '">' . e($order['code']) . '</span></p>'
        . email_button($url, 'Open ' . $app . ' →', EM_ROSE),
        '#fbeff1', '#e9bcc6');

    $inner = email_steps(1) . $pay
        . '<p style="margin:14px 0 0;font:14px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">' . e(payment_hold_text()) . '</p>'
        . email_section('Your cookies') . email_order_table($order)
        . email_section('Pickup') . email_facts([
            ['Date', '<strong>' . e(pretty_date($order['pickup_date'])) . '</strong>'],
            ['Time', e($order['pickup_slot']) . ' (Eastern)'],
            ['Where', 'Pickup only in ' . e(setting('pickup_area')) . '. The exact address comes in your confirmation email.'],
            ['Order', '<strong>' . e($order['code']) . '</strong> · ' . e(payment_label($order['payment_method']))],
        ])
        . '<p style="margin:24px 0 0;font:13px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">' . e(refund_policy_text()) . '</p>'
        . '<p style="margin:10px 0 0;font:13px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">Questions or changes? Just reply to this email.</p>';

    $html = email_layout("Thank you, {$first}!", $inner, [
        'eyebrow' => 'ORDER ' . $order['code'] . ' · PAYMENT NEEDED', 'badge_color' => '#fbeff1',
        'intro' => 'Your cookie order is saved. Send your payment now and Pasha will confirm your order — with the pickup address — as soon as it arrives.',
        'preheader' => "Send {$amount} to {$handle} on {$app} with {$order['code']} in the note.",
    ]);
    $text = "Thank you, {$first}! Your order {$order['code']} is saved.\n\n"
        . "How to pay:\n1. Send {$amount} to {$handle} on {$app} ({$url}).\n2. Write {$order['code']} in the payment note.\n\n"
        . "Pasha confirms your order, and emails you the pickup address, once your payment arrives.\n" . payment_hold_text() . "\n\n"
        . email_order_text($order, true)
        . "\n\n" . refund_policy_text() . "\nQuestions? Reply to this email.";
    return send_email($order['email'], "Order {$order['code']} saved — how to pay", $html, $text, 'receipt', (int) $order['id']);
}

function send_customer_confirmation(array $order): array
{
    $first = email_first_name($order);
    $address = trim(setting('pickup_address'));
    $calendar = email_calendar_url($order, $address);
    $maps = $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(str_replace("\n", ', ', $address)) : '';

    $pickup = email_panel(
        '<p style="margin:0 0 4px;font:bold 11px ' . EM_SANS . ';letter-spacing:.16em;color:#2f6b3f">YOUR PICKUP</p>'
        . '<p style="margin:0;font:bold 24px/1.3 ' . EM_SERIF . ';color:' . EM_BROWN . '">' . e(pretty_date($order['pickup_date'])) . '</p>'
        . '<p style="margin:2px 0 14px;font:16px ' . EM_SANS . '">' . e($order['pickup_slot']) . ' (Eastern)</p>'
        . ($address !== ''
            ? '<p style="margin:0 0 16px"><strong>Address</strong><br>' . nl2br(e($address)) . '</p>'
            : '<p style="margin:0 0 16px">Pasha will send you the pickup address.</p>')
        . ($calendar !== '' ? email_button($calendar, 'Add to calendar') : '')
        . ($maps !== '' ? email_button($maps, 'Get directions', EM_ROSE) : ''),
        '#eef5ec', '#cfe0c9');

    $inner = email_steps(2) . $pickup
        . email_section('Your cookies') . email_order_table($order)
        . email_section('Order') . email_facts([
            ['Order', '<strong>' . e($order['code']) . '</strong>'],
            ['Paid with', e(payment_label($order['payment_method'])) . ' · ' . money((int) $order['total_cents'])],
            ['Good to know', 'Enjoy them within a few days, and keep any extras in a Ziploc bag or airtight container.'],
        ])
        . '<p style="margin:24px 0 0;font:13px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">' . e(refund_policy_text()) . '</p>';

    $html = email_layout("It’s confirmed, {$first}!", $inner, [
        'eyebrow' => '✓ PAYMENT RECEIVED', 'badge_color' => '#e3f3e6',
        'intro' => 'Thank you! Your cookies are on Pasha’s baking list. Here are your pickup details — see you soon.',
        'preheader' => 'Pickup ' . pretty_date($order['pickup_date']) . ', ' . $order['pickup_slot'] . ($address !== '' ? ' · address inside' : ''),
    ]);
    $text = "Your order is confirmed, {$first}!\n\nPayment received — your cookies are scheduled for baking.\n\n"
        . ($address !== '' ? "Pickup address:\n{$address}\n\n" : '')
        . email_order_text($order, true)
        . "\n\n" . refund_policy_text();
    return send_email($order['email'], "Order {$order['code']} confirmed — see you on " . short_date($order['pickup_date']), $html, $text, 'confirmation', (int) $order['id']);
}

function send_test_email(string $to): array
{
    $html = email_layout('Test email', '<p style="font-size:15px;margin:0">Good news — email sending works. New orders and confirmations will be delivered.</p>');
    return send_email($to, 'Pashabakess test email', $html, 'Good news — email sending works.', 'test');
}

/** New website inquiry → Pasha. Replies go straight to the customer. */
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
    $html = email_layout('New inquiry: ' . $q['type'],
        '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px;margin:0 0 18px">' . $table . '</table>'
        . '<div style="background:#f6e7df;border-radius:10px;padding:16px 18px;font-size:15px;line-height:1.6">' . nl2br(e($q['message'])) . '</div>'
        . '<p style="font-size:14px;color:#6e5c52;margin:18px 0 0">Reply to this email to answer ' . e($q['name']) . ' directly.</p>');
    return send_email($to, "New inquiry: {$q['type']} · {$q['name']}" . (($q['order_ref'] ?? '') !== '' ? " · {$q['order_ref']}" : ''), $html, $text . "\n" . $q['message'], 'enquiry', null, $q['email']);
}
