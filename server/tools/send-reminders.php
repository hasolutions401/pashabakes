<?php
/*
 * Pickup reminders: emails each customer with a paid order for tomorrow, and sends Pasha
 * the list of tomorrow's pickups. Safe to run as often as you like — every email goes out once.
 *
 * Scheduled task (alwaysdata → Advanced → Scheduled tasks), every hour:
 *   php ~/pashabakess/server/tools/send-reminders.php
 * Nothing is sent before 9 AM Eastern. Add --now to send right away (for a test).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['REQUEST_URI'] = '/cli-reminders';
require dirname(__DIR__) . '/bootstrap.php';

$r = send_pickup_reminders(in_array('--now', $argv, true));
settings_save(['reminders_checked_at' => (string) time()]);
echo date('Y-m-d H:i') . ' reminders: ' . ($r['skipped'] !== '' ? "skipped ({$r['skipped']})"
    : "{$r['sent']} sent, {$r['failed']} failed, list to Pasha " . ($r['admin'] ? 'sent' : 'not needed')) . PHP_EOL;
exit($r['failed'] > 0 ? 1 : 0);
