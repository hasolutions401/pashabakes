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
    'site_url' => 'http://localhost', 'setup_key' => 'test-key-123456',
    'db' => $db, 'mail' => ['transport' => 'log', 'from_email' => 'test@example.com'],
], true) . ';');
putenv("PB_CONFIG=$config");
require dirname(__DIR__) . '/bootstrap.php';

if ($driver === 'mysql') {
    foreach (['order_items', 'orders', 'cookies', 'settings', 'admin_users', 'email_log', 'rate_hits', 'enquiries'] as $t) {
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

check('seeded 6 cookies', count(menu_cookies()) === 6);
$all = menu_cookies(true);
$mm = array_values(array_filter($all, fn($c) => $c['name'] === 'M&M'));
check('M&M added hidden, ready for later', count($all) === 7 && $mm && $mm[0]['is_available'] === 0
    && !in_array('M&M', array_column(public_menu_cookies(), 'name'), true));
check('seeded flavors use Pasha photos', menu_cookies()[0]['image'] === 'images/chocolate-chunk.jpg');
check('no borrowed photos left in the seed', !array_filter(menu_cookies(true), fn($c) => str_starts_with($c['image'], 'http')));
check('seeded prices', box_prices() === [4 => 1400, 6 => 2000, 12 => 3800, 24 => 7600, 36 => 11400]);
check('9 pickup slots', count(pickup_slots()) === 9);

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

[$ok] = send_customer_confirmation(order_find((int) $order['id']));
check('confirmation email written', $ok && email_statuses((int) $order['id'])['confirmation'] === 'sent');
$mailDir = data_dir('mail');
$before = glob($mailDir . '/*-receipt-*.html') ?: [];
send_customer_receipt(order_find((int) $order['id']));
$newReceipts = array_values(array_diff(glob($mailDir . '/*-receipt-*.html') ?: [], $before));
$receipt = $newReceipts ? (string) file_get_contents($newReceipts[0]) : '';
check('receipt has payment instructions with order number', str_contains($receipt, '$Pashabakess') && str_contains($receipt, $order['code'] . '</strong> in the payment note'));

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
check('no deadline by default: pay right away', str_contains(payment_hold_text(), 'right away'));
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
    migrate($old, 'sqlite');
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
settings_save(['max_cookies_per_day' => '0']);

// Deleting orders and restarting the numbers
$someId = (int) db_value("SELECT id FROM orders WHERE status <> 'cancelled' LIMIT 1");
check('only cancelled orders can be deleted', !order_delete($someId) && order_find($someId) !== null);
check('restart refused while orders exist', !restart_order_numbers());
foreach (db_all('SELECT id FROM orders') as $row) {
    order_set_status((int) $row['id'], 'cancelled');
    order_delete((int) $row['id']);
}
check('cancelled orders deleted with their items and emails', (int) db_value('SELECT COUNT(*) FROM orders') === 0
    && (int) db_value('SELECT COUNT(*) FROM order_items') === 0 && (int) db_value('SELECT COUNT(*) FROM email_log WHERE order_id IS NOT NULL') === 0);
check('restart numbering', restart_order_numbers() && next_order_code() === 'PB1001');
[$fresh] = order_validate($base(['client_token' => bin2hex(random_bytes(12))]));
[$first] = order_create($fresh);
check('next order is PB1001', $first['code'] === 'PB1001' && next_order_code() === 'PB1002');

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
