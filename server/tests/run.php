<?php
/*
 * Backend self-test. Run:  php server/tests/run.php
 * Uses a throwaway database (never your real orders):
 *   - SQLite by default
 *   - MySQL/MariaDB:  PB_TEST_DB=mysql PB_TEST_MYSQL="host:port:db:user:pass" php server/tests/run.php
 */
declare(strict_types=1);
$_SERVER['REQUEST_URI'] = '/cli-test';

$tmp = sys_get_temp_dir() . '/pb-test-' . getmypid();
@mkdir($tmp);
$driver = getenv('PB_TEST_DB') ?: 'sqlite';
$db = ['driver' => 'sqlite', 'sqlite' => "$tmp/test.sqlite"];
if ($driver === 'mysql') {
    [$host, $port, $name, $user, $pass] = array_pad(explode(':', (string) getenv('PB_TEST_MYSQL')), 5, '');
    $db = ['driver' => 'mysql', 'host' => "$host;port=$port", 'name' => $name, 'user' => $user, 'password' => $pass];
}
$config = "$tmp/config.php";
file_put_contents($config, '<?php return ' . var_export([
    'site_url' => 'http://localhost', 'data_dir' => "$tmp/data", 'setup_key' => 'test-key-123456',
    'db' => $db, 'mail' => ['transport' => 'log', 'from_email' => 'test@example.com'],
], true) . ';');
putenv("PB_CONFIG=$config");
require dirname(__DIR__) . '/bootstrap.php';

if ($driver === 'mysql') {
    foreach (['order_items', 'orders', 'archived_orders', 'cookies', 'settings', 'admin_users', 'email_log', 'rate_hits', 'enquiries'] as $t) {
        db()->exec("DROP TABLE IF EXISTS $t");
    }
    // Re-run migrations on the now-empty database.
    migrate(db(), 'mysql');
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
}

echo "Backend tests on " . strtoupper($driver) . PHP_EOL;
check('public folder found (dist next to server)', public_dir() === dirname(PB_ROOT) . '/dist' && is_file(public_dir() . '/index.html'));

check('seeded 6 cookies', count(menu_cookies()) === 6);
$all = menu_cookies(true);
$mm = array_values(array_filter($all, fn($c) => $c['name'] === 'M&M'));
check('M&M added hidden, ready for later', count($all) === 7 && $mm && $mm[0]['is_available'] === 0
    && !in_array('M&M', array_column(public_menu_cookies(), 'name'), true));
check('seeded flavors use Pasha photos', menu_cookies()[0]['image'] === 'images/chocolate-chunk.jpg');
$variants = cookie_image_variants('images/mm.jpg');
check('site photos carry a version so replaced photos show at once', preg_match('#^images/mm-160\.jpg\?v=\d+$#', $variants['sm'])
    && preg_match('#^images/mm\.jpg\?v=\d+$#', $variants['img']) && cookie_image_variants('https://x.test/a.jpg')['sm'] === 'https://x.test/a.jpg');
check('only the two specials still use sample photos', count(array_filter(menu_cookies(true), fn($c) => str_starts_with($c['image'], 'http'))) === 2);
check('seeded prices', box_prices() === [4 => 1400, 6 => 2000, 12 => 3800, 24 => 7600, 36 => 11400]);
check('9 pickup slots', count(pickup_slots()) === 9);
check('unpaid orders hold their day for 24 hours by default', payment_hours() === 24);

$base = fn(array $over = []) => array_merge([
    'client_token' => bin2hex(random_bytes(12)), 'name' => 'Test Person', 'email' => 'Test@Example.com', 'phone' => '(978) 555-0123',
    'occasion' => 'Eid', 'notes' => 'hi', 'box_size' => 6, 'items' => [['id' => 1, 'qty' => 3], ['id' => 6, 'qty' => 3]],
    'pickup_date' => earliest_pickup_date()->format('Y-m-d'), 'pickup_slot' => pickup_slots()[0],
    'payment_method' => 'cashapp', 'payer_ref' => '$tester', 'agree' => true, 'expected_total_cents' => 2000,
], $over);

[$data, $errors] = order_validate($base());
check('valid order passes', $errors === []);
check('email lower-cased', $data['email'] === 'test@example.com');
check('server total used', $data['total_cents'] === 2000);
[, $e] = order_validate($base(['items' => [['id' => 1, 'qty' => 5]]]));
check('wrong cookie count rejected', isset($e['items']));
[, $e] = order_validate($base(['pickup_date' => today()->format('Y-m-d')]));
check('too-soon date rejected', isset($e['pickup_date']));
[, $e] = order_validate($base(['expected_total_cents' => 100]));
check('price mismatch rejected', isset($e['box_size']));
[, $e] = order_validate($base(['payment_method' => 'paypal', 'payer_ref' => '', 'agree' => false]));
check('payment + agree required', isset($e['payment_method'], $e['agree']));
[, $e] = order_validate($base(['payer_ref' => '']));
check('payer name optional (customer pays after ordering)', $e === []);

// Monthly specials: only for pickups in their month.
$seasonal = array_values(array_filter(menu_cookies(), fn($c) => $c['type'] === 'seasonal'));
$earliest = earliest_pickup_date();
check('seeded specials limited to the next orderable month', count($seasonal) === 2
    && $seasonal[0]['available_from'] === $earliest->format('Y-m-01') && $seasonal[0]['available_until'] === $earliest->format('Y-m-t'));
$nextMonth = $earliest->modify('first day of next month')->format('Y-m-d');
settings_save(['max_days_ahead' => '120']);
[, $e] = order_validate($base(['pickup_date' => $nextMonth]));
check('special rejected for a pickup next month', isset($e['items']) && str_contains($e['items'], $seasonal[1]['name']));
[, $e] = order_validate($base(['pickup_date' => $nextMonth, 'items' => [['id' => 1, 'qty' => 6]]]));
check('signature flavor fine next month', $e === []);
settings_save(['max_days_ahead' => '90']);
cookie_save($seasonal[0]['id'], ['available_from' => '2020-01-01', 'available_until' => '2020-01-31'] + $seasonal[0]);
check('past specials hidden from the public menu', !in_array($seasonal[0]['id'], array_column(public_menu_cookies(), 'id'), true)
    && count(public_menu_cookies()) === 5);
cookie_save($seasonal[0]['id'], $seasonal[0]);
check('window text', cookie_window_text($seasonal[0]) === $earliest->format('F') . ' pickups only');
[, $ce] = cookie_validate(['name' => 'X', 'available_from' => '2026-10-31', 'available_until' => '2026-10-01']);
check('admin: first date must be before last', isset($ce['available']));
[, $e] = order_validate($base(['occasion' => '<script>']));
check('unknown occasion dropped', $e === []);

[$order, $created] = order_create($data);
check('order saved', $created && $order['code'] === 'PB' . (1000 + (int) $order['id']));
[$again, $createdAgain] = order_create($data);
check('duplicate submit returns same order', !$createdAgain && $again['id'] === $order['id']);
check('items saved', count(order_items((int) $order['id'])) === 2);

check('mark paid once', order_mark_paid((int) $order['id']));
check('mark paid twice is ignored', !order_mark_paid((int) $order['id']));
$plan = baking_plan(30);
check('baking plan totals', ($plan[$order['pickup_date']]['cookies'] ?? 0) === 6);

$list = order_list('paid', 'tester', 1);
check('search by payer', $list['total'] === 1);
check('counts', order_counts()['paid'] === 1);
order_set_status((int) $order['id'], 'completed');
check('completed', order_find((int) $order['id'])['status'] === 'completed');

settings_save(['unavailable_dates' => $order['pickup_date'], 'lead_days' => '7']);
[, $e] = order_validate($base());
check('unavailable date rejected', isset($e['pickup_date']));
settings_save(['unavailable_dates' => '']);

$id = cookie_save(null, ['name' => 'Test Cookie', 'description' => 'x', 'type' => 'seasonal', 'image' => '', 'is_available' => 1, 'sort_order' => 99]);
check('cookie added', cookie_find($id)['name'] === 'Test Cookie');
cookie_delete($id);
check('cookie deleted', cookie_find($id) === null);

admin_create('Pasha', 'secret-pass-1');
check('admin created (username lower-cased)', db_value('SELECT username FROM admin_users') === 'pasha');
check('password hashed', password_verify('secret-pass-1', (string) db_value('SELECT password_hash FROM admin_users')));
check('after login: admin pages allowed', safe_admin_next('/admin/order.php?id=12') === '/admin/order.php?id=12'
    && safe_admin_next('/admin/') === 'index.php' && safe_admin_next('/sub/admin/index.php?status=paid&q=PB10') === '/sub/admin/index.php?status=paid&q=PB10');
check('after login: other websites refused', array_unique(array_map('safe_admin_next', ['//evil.example/admin/index.php', '/\\evil.example/admin/x.php',
    'https://evil.example/admin/index.php', '///evil.example/admin/index.php', '/admin/x.php?next=//evil', "/admin/index.php\r\nX: y", '/%2F%2Fevil/admin/x.php'])) === ['index.php']);

[$ok] = send_customer_confirmation(order_find((int) $order['id']));
check('confirmation email written', $ok && email_statuses((int) $order['id'])['confirmation'] === 'sent');
check('dashboard: no email problems', recent_email_problems()['failed'] === [] && recent_email_problems()['no_gmail'] === []);
email_log_result('test@example.com', 'confirmation', (int) $order['id'], false, 'SMTP down');
email_log_result('pasha@example.com', 'enquiry', null, false, 'SMTP down');
$problems = recent_email_problems();
check('dashboard: failed emails listed', count($problems['failed']) === 2 && $problems['failed'][0]['code'] === $order['code']);
email_log_result('test@example.com', 'confirmation', (int) $order['id'], true, SPAM_RISK_NOTE . ' (test)');
$problems = recent_email_problems();
check('dashboard: resent email no longer failed, but flagged as sent without Gmail', count($problems['failed']) === 1
    && $problems['failed'][0]['kind'] === 'enquiry' && count($problems['no_gmail']) === 1);
send_customer_confirmation(order_find((int) $order['id']));
db_exec("DELETE FROM email_log WHERE kind = 'enquiry'");
$mailDir = data_dir('mail');
$before = glob($mailDir . '/*-receipt-*.html') ?: [];
send_customer_receipt(order_find((int) $order['id']));
$newReceipts = array_values(array_diff(glob($mailDir . '/*-receipt-*.html') ?: [], $before));
$receipt = $newReceipts ? (string) file_get_contents($newReceipts[0]) : '';
check('receipt has payment instructions with order number', str_contains($receipt, '$Pashabakess') && str_contains($receipt, 'Write this in the payment note')
    && str_contains($receipt, '>' . $order['code'] . '</span>') && str_contains($receipt, 'images/chocolate-chunk-160.jpg'));
check('receipt leaves out the free-text payer name', !str_contains($receipt, '$tester') && !str_contains(email_order_text(order_find((int) $order['id']), true), '$tester'));
check('Pasha’s alert keeps the payer name', str_contains(email_order_text(order_find((int) $order['id'])), '$tester'));

// Enquiries
[$q, $e] = enquiry_validate(['name' => 'Amina', 'email' => 'A@Example.com', 'type' => 'General question', 'date' => '2031-01-01', 'quantity' => '48 cookies', 'message' => 'Do you do nut-free?']);
check('general enquiry drops event fields', $e === [] && $q['event_date'] === '' && $q['quantity'] === '' && $q['email'] === 'a@example.com');
[, $e] = enquiry_validate(['name' => 'A', 'email' => 'nope', 'type' => 'Birthday', 'date' => '2001-01-01', 'message' => '']);
check('enquiry errors', isset($e['name'], $e['email'], $e['date'], $e['message']));
[$q] = enquiry_validate(['name' => 'Amina', 'email' => 'a@example.com', 'type' => 'Birthday', 'date' => $nextMonth, 'quantity' => '48 cookies (4 dozen)', 'message' => 'Party for 40 people']);
[$row, $created] = enquiry_create($q);
[$row2, $created2] = enquiry_create($q);
check('enquiry saved once', $created && !$created2 && $row['id'] === $row2['id'] && enquiry_new_count() === 1);
[$ok] = send_enquiry_alert($row);
check('enquiry emailed to Pasha', $ok);
[$q, $e] = enquiry_validate(['name' => 'Sam', 'email' => 's@example.com', 'type' => 'Payment question', 'order_ref' => ' pb-1005 ', 'date' => $nextMonth, 'message' => 'I paid but got no email']);
check('order question keeps order number, drops event fields', $e === [] && $q['order_ref'] === 'PB1005' && $q['event_date'] === '');
[$q] = enquiry_validate(['name' => 'Sam', 'email' => 's@example.com', 'type' => 'Birthday', 'order_ref' => 'PB1', 'message' => 'Party next month']);
check('event enquiry ignores order number', $q['order_ref'] === '');
settings_save(['payment_hours' => '24']);
check('payment deadline text', str_contains(payment_hold_text(), 'within 24 hours'));
check('overdue after deadline', payment_overdue(['status' => 'pending', 'created_at' => today()->modify('-2 days')->format('Y-m-d H:i:s')])
    && !payment_overdue(['status' => 'paid', 'created_at' => today()->modify('-2 days')->format('Y-m-d H:i:s')]));
settings_save(['payment_hours' => '0']);
check('no deadline when set to 0: pay right away', str_contains(payment_hold_text(), 'right away'));
check('Gmail password: none by default', gmail_password_source() === '' && gmail_app_password() === '');
settings_save(['gmail_app_password' => 'testtesttesttest']);
check('Gmail password: saved in admin', gmail_password_source() === 'admin' && gmail_app_password() === 'testtesttesttest');
putenv('PB_GMAIL_APP_PASSWORD=envx envx envx envx');
check('Gmail password: server setting wins over the database', gmail_password_source() === 'env' && gmail_app_password() === 'envxenvxenvxenvx');
putenv('PB_GMAIL_APP_PASSWORD');
settings_save(['gmail_app_password' => '']);
enquiry_set_status((int) $row['id'], 'done');
check('enquiry marked answered', enquiry_new_count() === 0 && enquiry_list('done', 1)['total'] === 1);

// Upgrading a version-1 database adds the new columns and dates the existing specials.
if ($driver === 'sqlite') {
    $old = new PDO("sqlite:$tmp/v1.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $old->exec("CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)");
    $old->exec("INSERT INTO settings VALUES ('schema_version', '1'), ('lead_days', '7')");
    $old->exec("CREATE TABLE cookies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT NOT NULL, type TEXT NOT NULL,
        image TEXT NOT NULL, is_available INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $old->exec("INSERT INTO cookies (name, description, type, image, created_at, updated_at) VALUES ('Old sig', '', 'signature', '', 'x', 'x'), ('Old special', '', 'seasonal', '', 'x', 'x')");
    $ins = $old->prepare("INSERT INTO cookies (name, description, type, image, created_at, updated_at) VALUES (?, '', 'signature', ?, 'x', 'x')");
    $ins->execute(['Chocolate Chunk', array_key_first(own_photo_swaps())]);
    $ins->execute(['Red Velvet', 'uploads/cookie-abc123.jpg']);   // Pasha's own upload: must be kept
    $backupsBefore = glob(data_dir('backups') . '/*.sqlite') ?: [];
    migrate($old, 'sqlite');
    $newBackups = array_values(array_diff(glob(data_dir('backups') . '/*.sqlite') ?: [], $backupsBefore));
    $copy = $newBackups ? new PDO('sqlite:' . $newBackups[0]) : null;
    check('upgrade: database copied first (old version, same data)', count($newBackups) === 1 && str_contains($newBackups[0], 'v1-before-v')
        && (int) $copy->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() === 1
        && (int) $copy->query('SELECT COUNT(*) FROM cookies')->fetchColumn() === 4);
    $copy = null;
    $rows = $old->query('SELECT type, available_from, available_until FROM cookies ORDER BY id')->fetchAll();
    check('v1 upgrade: specials dated, signatures untouched', $rows[0]['available_from'] === null
        && $rows[1]['available_from'] === $earliest->format('Y-m-01') && $rows[1]['available_until'] === $earliest->format('Y-m-t')
        && (int) $old->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() === PB_SCHEMA_VERSION
        && $old->query("SELECT COUNT(*) FROM enquiries")->fetchColumn() !== false);
    $img = fn($n) => $old->query("SELECT image FROM cookies WHERE name = " . $old->quote($n))->fetchColumn();
    check('upgrade: sample photo swapped, own upload kept', $img('Chocolate Chunk') === 'images/chocolate-chunk.jpg' && $img('Red Velvet') === 'uploads/cookie-abc123.jpg');
    check('upgrade: M&M added hidden', (int) $old->query("SELECT is_available FROM cookies WHERE name = 'M&M'")->fetchColumn() === 0);
    migrate($old, 'sqlite');
    check('upgrade runs once (no second M&M)', (int) $old->query("SELECT COUNT(*) FROM cookies WHERE name = 'M&M'")->fetchColumn() === 1);
    check('v1 upgrade: 24-hour hold', $old->query("SELECT value FROM settings WHERE name = 'payment_hours'")->fetchColumn() === '24');

    // Version 8 → 9: a "no deadline" setting becomes 24 hours; a deadline Pasha chose herself is kept.
    foreach (['0' => '24', '48' => '48'] as $was => $expect) {
        $v8 = new PDO("sqlite:$tmp/v8-$was.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $v8->exec("CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $v8->exec("INSERT INTO settings VALUES ('schema_version', '8'), ('lead_days', '7'), ('payment_hours', '$was')");
        migrate($v8, 'sqlite');
        check("v8 upgrade: payment hours $was → $expect", $v8->query("SELECT value FROM settings WHERE name = 'payment_hours'")->fetchColumn() === $expect
            && (int) $v8->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() === PB_SCHEMA_VERSION);
        $v8 = null;
    }
}

// Daily cookie limit
$day = earliest_pickup_date()->modify('+3 days')->format('Y-m-d');
$dayOrder = fn(int $size, array $items) => $base(['client_token' => bin2hex(random_bytes(12)), 'pickup_date' => $day, 'box_size' => $size,
    'items' => $items, 'expected_total_cents' => box_prices()[$size]]);
[, $e] = order_validate($dayOrder(12, [['id' => 1, 'qty' => 12]]));
check('default daily limit is 5 dozen', $e === [] && max_cookies_per_day() === 60);
settings_save(['max_cookies_per_day' => '16']);
[$d1, $e] = order_validate($dayOrder(12, [['id' => 1, 'qty' => 12]]));
[, $created] = order_create($d1);
check('order within the limit', $e === [] && $created);
[, $e] = order_validate($dayOrder(6, [['id' => 1, 'qty' => 6]]));
check('box too big for what is left', isset($e['pickup_date']) && str_contains($e['pickup_date'], 'only take 4 more'));
[$d2, $e] = order_validate($dayOrder(4, [['id' => 1, 'qty' => 4]]));
[, $created] = order_create($d2);
check('smaller box still fits', $e === [] && $created);
[$d3, $e] = order_validate($dayOrder(4, [['id' => 1, 'qty' => 4]]));
check('day now fully booked', isset($e['pickup_date']) && str_contains($e['pickup_date'], 'fully booked'));
$threw = false;
try { order_create($d3); } catch (DayFullException) { $threw = true; }
check('limit re-checked when saving', $threw);
check('menu shows 0 left that day', days_remaining()[$day] === 0);
order_set_status((int) db_value('SELECT id FROM orders WHERE client_token = ?', [$d2['client_token']]), 'cancelled');
[, $e] = order_validate($dayOrder(4, [['id' => 1, 'qty' => 4]]));
check('cancelling frees the space', $e === [] && days_remaining()[$day] === 4);
// Unpaid orders hold their day only until the payment deadline.
settings_save(['payment_hours' => '24']);
$d1Id = (int) db_value('SELECT id FROM orders WHERE client_token = ?', [$d1['client_token']]);
check('unpaid order within 24 hours holds its day', booked_cookies($day) === 12);
$hoursAgo = fn(int $h) => (new DateTimeImmutable('now', new DateTimeZone(PB_TZ)))->modify("-{$h} hours")->format('Y-m-d H:i:s');
db_exec('UPDATE orders SET created_at = ? WHERE id = ?', [$hoursAgo(25), $d1Id]);
[$d4, $e] = order_validate($dayOrder(12, [['id' => 1, 'qty' => 12]]));
check('unpaid order past 24 hours frees its day', booked_cookies($day) === 0 && !isset(days_remaining()[$day]) && $e === []);
[, $created] = order_create($d4);
check('someone else can book the freed day', $created && booked_cookies($day) === 12);
check('overdue order can still be marked paid', order_mark_paid($d1Id) && booked_cookies($day) === 24);
check('paid orders always hold their day', payment_overdue(order_find($d1Id)) === false);
$mail = 'cap-test@example.com';
foreach ([1, 2, 3] as $i) {
    order_create(['client_token' => bin2hex(random_bytes(12)), 'email' => $mail, 'pickup_date' => earliest_pickup_date()->modify("+{$i} weeks")->format('Y-m-d')] + $d4);
}
check('unpaid orders counted per email', unpaid_orders_for_email($mail) === 3);
db_exec('UPDATE orders SET created_at = ? WHERE email = ? AND id = (SELECT MIN(id) FROM orders WHERE email = ?)', [$hoursAgo(30), $mail, $mail]);
check('overdue orders no longer count toward the per-email cap', unpaid_orders_for_email($mail) === 2);
settings_save(['max_cookies_per_day' => '0']);

// Deleting orders and restarting the numbers
$someId = (int) db_value("SELECT id FROM orders WHERE status <> 'cancelled' LIMIT 1");
check('only cancelled orders can be deleted', !order_delete($someId) && order_find($someId) !== null);
check('restart refused while orders wait for payment or pickup', open_order_count() > 0 && restart_order_numbers() !== null);
$firstId = (int) db_value('SELECT MIN(id) FROM orders');
order_set_status($firstId, 'cancelled');
check('cancelled order deleted with its items and emails', order_delete($firstId) && order_find($firstId) === null
    && (int) db_value('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$firstId]) === 0
    && (int) db_value('SELECT COUNT(*) FROM email_log WHERE order_id = ?', [$firstId]) === 0);
foreach (db_all("SELECT id FROM orders WHERE status IN ('pending', 'paid')") as $row) {
    order_set_status((int) $row['id'], 'completed');
}
$finished = (int) db_value('SELECT COUNT(*) FROM orders');
$someCode = (string) db_value('SELECT code FROM orders ORDER BY id DESC LIMIT 1');
check('restart numbering', restart_order_numbers() === null && next_order_code() === 'PB1001');
check('finished orders kept in the archive, not deleted', (int) db_value('SELECT COUNT(*) FROM archived_orders') === $finished && $finished > 0
    && (int) db_value('SELECT COUNT(*) FROM orders') === 0 && (int) db_value('SELECT COUNT(*) FROM order_items') === 0
    && (int) db_value('SELECT COUNT(*) FROM email_log WHERE order_id IS NOT NULL') === 0);
$found = archived_order_list($someCode, 1);
check('archive is searchable and keeps the cookies', $found['total'] === 1 && str_contains($found['rows'][0]['items_text'], '×'));
[$fresh] = order_validate($base(['client_token' => bin2hex(random_bytes(12))]));
[$first] = order_create($fresh);
check('next order is PB1001', $first['code'] === 'PB1001' && next_order_code() === 'PB1002');
$csv = orders_csv_rows('archive');
check('CSV download: header + one row per archived order', count($csv) === $finished + 1 && $csv[0][0] === 'Order' && end($csv[0]) === 'Archived');
check('CSV download: current orders', count(orders_csv_rows('orders')) === 2 && orders_csv_rows('orders')[1][0] === 'PB1001');
check('CSV download: spreadsheet formulas neutralised', csv_cell('=HYPERLINK("http://x")') === "'=HYPERLINK(\"http://x\")"
    && csv_cell('+1 978 555 0100') === "'+1 978 555 0100" && csv_cell('@SUM(A1)') === "'@SUM(A1)" && csv_cell('Amina') === 'Amina');

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
check('client IP: direct visitor', client_ip() === '203.0.113.9');
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.7, 10.0.0.2';
check('client IP: behind the host proxy', client_ip() === '198.51.100.7');
unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);

rate_hit('t'); rate_hit('t');
check('rate limit counts', !rate_allowed('t', 2, 60) && rate_allowed('t', 3, 60));

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
