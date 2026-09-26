<?php
declare(strict_types=1);

/*
 * The day before pickup: a reminder to each customer with a paid order, and at the same time
 * one email to Pasha listing tomorrow's pickups.
 *
 * Runs from the hourly scheduled task (server/tools/send-reminders.php) and, as a backup, at most
 * every 30 minutes when someone visits the website or Pasha opens the admin. Every reminder is
 * sent once: orders are marked when their reminder goes out.
 */

/** Reminders go out from this hour (Eastern) on the day before pickup. */
const PB_REMINDER_HOUR = 9;

/** Paid orders for tomorrow whose reminder hasn't gone out yet. */
function reminders_due(): array
{
    $tomorrow = today()->modify('+1 day')->format('Y-m-d');
    return db_all("SELECT * FROM orders WHERE status = 'paid' AND pickup_date = ? AND reminder_sent_at IS NULL ORDER BY pickup_slot, id", [$tomorrow]);
}

/**
 * Sends the due reminders (only from PB_REMINDER_HOUR, unless $anyTime) and Pasha's list of tomorrow's pickups.
 * Returns ['sent' => n, 'failed' => n, 'admin' => bool, 'skipped' => reason or ''].
 */
function send_pickup_reminders(bool $anyTime = false): array
{
    $result = ['sent' => 0, 'failed' => 0, 'admin' => false, 'skipped' => ''];
    $now = new DateTimeImmutable('now', new DateTimeZone(PB_TZ));
    if (!$anyTime && (int) $now->format('G') < PB_REMINDER_HOUR) {
        $result['skipped'] = 'before ' . PB_REMINDER_HOUR . ' AM Eastern';
        return $result;
    }
    // One run at a time (the scheduled task and a page visit could start together).
    $lock = @fopen(data_dir('logs') . '/reminders.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        $result['skipped'] = 'already running';
        return $result;
    }
    try {
        foreach (reminders_due() as $order) {
            if ($order['email'] === '') {
                db_exec('UPDATE orders SET reminder_sent_at = ? WHERE id = ?', [now_str(), (int) $order['id']]);
                continue;
            }
            [$ok] = send_customer_reminder($order);
            if ($ok) {
                // Marked only when it went out, so a failed one is tried again next time (until the pickup day).
                db_exec('UPDATE orders SET reminder_sent_at = ? WHERE id = ? AND reminder_sent_at IS NULL', [now_str(), (int) $order['id']]);
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }
        $tomorrow = today()->modify('+1 day')->format('Y-m-d');
        if (setting('reminder_admin_date') !== $tomorrow) {
            $orders = db_all("SELECT * FROM orders WHERE status = 'paid' AND pickup_date = ? ORDER BY pickup_slot, id", [$tomorrow]);
            if ($orders) {
                [$ok] = send_admin_pickups_tomorrow($orders, $tomorrow);
                $result['admin'] = $ok;
                if ($ok) {
                    settings_save(['reminder_admin_date' => $tomorrow]);
                }
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $result;
}

/** Backup for the scheduled task: runs the reminders at most every 30 minutes. */
function maybe_send_pickup_reminders(): void
{
    $last = (int) setting('reminders_checked_at');
    if (time() - $last < 1800) {
        return;
    }
    settings_save(['reminders_checked_at' => (string) time()]);
    try {
        send_pickup_reminders();
    } catch (Throwable $e) {
        log_error('Pickup reminders failed: ' . $e->getMessage());
    }
}

/** "See you tomorrow": pickup time, address and the cookies, sent the day before pickup. */
function send_customer_reminder(array $order): array
{
    $first = email_first_name($order);
    $address = trim(setting('pickup_address'));
    $calendar = email_calendar_url($order, $address);
    $maps = $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(str_replace("\n", ', ', $address)) : '';

    $pickup = email_panel(
        '<p style="margin:0 0 4px;font:bold 11px ' . EM_SANS . ';letter-spacing:.16em;color:#2f6b3f">TOMORROW</p>'
        . '<p style="margin:0;font:bold 24px/1.3 ' . EM_SERIF . ';color:' . EM_BROWN . '">' . e(pretty_date($order['pickup_date'])) . '</p>'
        . '<p style="margin:2px 0 14px;font:16px ' . EM_SANS . '">' . e($order['pickup_slot']) . ' (Eastern)</p>'
        . ($address !== ''
            ? '<p style="margin:0 0 16px"><strong>Address</strong><br>' . nl2br(e($address)) . '</p>'
            : '<p style="margin:0 0 16px">The pickup address is in your confirmation email.</p>')
        . ($calendar !== '' ? email_button($calendar, 'Add to calendar') : '')
        . ($maps !== '' ? email_button($maps, 'Get directions', EM_ROSE) : ''),
        '#eef5ec', '#cfe0c9');

    $inner = $pickup
        . email_section('Your cookies') . email_order_table($order)
        . email_section('Order') . email_facts([
            ['Order', '<strong>' . e($order['code']) . '</strong>'],
            ['Paid with', e(payment_label($order['payment_method'])) . ' · ' . money((int) $order['total_cents'])],
        ])
        . '<p style="margin:24px 0 0;font:13px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">Running late or can’t make it? Just reply to this email and let Pasha know.</p>';

    $html = email_layout("See you tomorrow, {$first}!", $inner, [
        'eyebrow' => 'PICKUP REMINDER · ' . $order['code'], 'badge_color' => '#e3f3e6',
        'intro' => 'Your cookies are being baked for tomorrow. Here’s everything you need for pickup.',
        'preheader' => 'Pickup tomorrow, ' . $order['pickup_slot'] . ($address !== '' ? ' · address inside' : ''),
    ]);
    $text = "See you tomorrow, {$first}!\n\nYour order {$order['code']} is ready for pickup tomorrow.\n\n"
        . ($address !== '' ? "Pickup address:\n{$address}\n\n" : '')
        . email_order_text($order, true)
        . "\n\nRunning late or can't make it? Reply to this email.";
    return send_email($order['email'], "Reminder: your cookies are ready tomorrow ({$order['code']})", $html, $text, 'reminder', (int) $order['id']);
}

/** To Pasha, at the same time as the customer reminders: tomorrow's pickups with their cookies. */
function send_admin_pickups_tomorrow(array $orders, string $ymd): array
{
    $cookies = array_sum(array_map(fn($o) => (int) $o['box_size'], $orders));
    $rows = '';
    foreach ($orders as $o) {
        $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #ecd5c9;vertical-align:top"><strong>' . e($o['pickup_slot']) . '</strong><br>'
            . '<a href="' . e(site_url('admin/order.php?id=' . (int) $o['id'])) . '" style="color:' . EM_ROSE . '">' . e($o['code']) . '</a> · ' . e($o['customer_name'])
            . ($o['phone'] !== '' ? ' · ' . e($o['phone']) : '') . '<br><span style="color:' . EM_MUTED . '">' . e(order_items_text((int) $o['id'])) . '</span></td></tr>';
    }
    $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font:15px/1.5 ' . EM_SANS . ';color:' . EM_BROWN . '">' . $rows . '</table>'
        . '<p style="margin:24px 0 0">' . email_button(site_url('admin/index.php'), 'Open admin →') . '</p>'
        . '<p style="margin:14px 0 0;font:13px/1.6 ' . EM_SANS . ';color:' . EM_MUTED . '">Each of these customers got a pickup reminder email at the same time.</p>';
    $n = count($orders);
    $html = email_layout('Tomorrow’s pickups', $inner, [
        'eyebrow' => strtoupper(short_date($ymd)) . " · {$n} ORDER" . ($n === 1 ? '' : 'S'), 'badge_color' => '#fff4d9',
        'intro' => "{$n} order" . ($n === 1 ? '' : 's') . " ({$cookies} cookies) to hand over on " . e(pretty_date($ymd)) . '.',
        'preheader' => "{$n} pickup" . ($n === 1 ? '' : 's') . ' tomorrow · ' . $cookies . ' cookies',
    ]);
    $text = "Tomorrow's pickups (" . pretty_date($ymd) . "): {$n} orders, {$cookies} cookies\n\n"
        . implode("\n", array_map(fn($o) => "{$o['pickup_slot']} · {$o['code']} · {$o['customer_name']} · " . order_items_text((int) $o['id']), $orders))
        . "\n\nAdmin: " . site_url('admin/index.php');
    return send_email(setting('notify_email'), "Tomorrow’s pickups: {$n} order" . ($n === 1 ? '' : 's') . ' · ' . short_date($ymd), $html, $text, 'admin_reminder');
}
