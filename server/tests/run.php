<?php
/*
 * Backend self-test. Run:  php server/tests/run.php
 * Uses a throwaway database (never your real orders):
 *   - SQLite by default
 *   - MySQL/MariaDB:  PB_TEST_DB=mysql PB_TEST_MYSQL="host:port:db:user:pass" php server/tests/run.php
 */
declare(strict_types=1);
$_SERVER['REQUEST_URI'] = '/cli-test';

// A fresh folder every run (process ids get reused, and an old test database must never be picked up).
$tmp = sys_get_temp_dir() . '/pb-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($tmp);
$driver = getenv('PB_TEST_DB') ?: 'sqlite';
$db = ['driver' => 'sqlite', 'sqlite' => "$tmp/test.sqlite"];
if ($driver === 'mysql') {
    [$host, $port, $name, $user, $pass] = array_pad(explode(':', (string) getenv('PB_TEST_MYSQL')), 5, '');
    $db = ['driver' => 'mysql', 'host' => "$host;port=$port", 'name' => $name, 'user' => $user, 'password' => $pass];
}
$config = "$tmp/config.php";
file_put_contents($config, '<?php return ' . var_export([
    'site_url' => 'http://localhost', 'data_dir' => "$tmp/data", 'setup_key' => 'test-key-123456',   // gitleaks:allow — throwaway key for the test database only
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
[$d, $e] = order_validate($base(['phone' => 9785550123, 'name' => "Test\r\nPerson  Two"]));
check('phone as a number accepted; line breaks in the name collapsed', $e === [] && $d['phone'] === '9785550123' && $d['customer_name'] === 'Test Person Two');
[, $e] = order_validate($base(['name' => ['x'], 'email' => ['a@b.c'], 'pickup_date' => 20261020, 'payment_method' => ['venmo'], 'client_token' => ['x']]));
check('lists and numbers in text fields give errors, not a crash', isset($e['name'], $e['email'], $e['pickup_date'], $e['payment_method'], $e['form']));
[, $e] = order_validate($base(['box_size' => '6abc']));
check('box size must be a whole number', isset($e['box_size']));

// "Other amount": 4–36 cookies at the per-cookie price; the set boxes keep their (cheaper) price.
check('other amount priced per cookie', cookie_price() === 350 && box_price(30) === 10500 && box_price(5) === 1750 && box_price(12) === 3800);
check('other amount stays within 4–36', box_price(3) === 0 && box_price(37) === 0 && box_price(0) === 0);
[$d, $e] = order_validate($base(['box_size' => 30, 'items' => [['id' => 1, 'qty' => 30]], 'expected_total_cents' => 10500]));
check('other amount order passes with the per-cookie total', $e === [] && $d['total_cents'] === 10500 && $d['box_size'] === 30);
[, $e] = order_validate($base(['box_size' => 37, 'items' => [['id' => 1, 'qty' => 36], ['id' => 2, 'qty' => 1]], 'expected_total_cents' => 12950]));
check('more than 36 cookies rejected', isset($e['box_size']));
[, $e] = order_validate($base(['box_size' => 30, 'items' => [['id' => 1, 'qty' => 30]], 'expected_total_cents' => 9000]));
check('other amount: a wrong total is caught', isset($e['box_size']));
settings_save(['cookie_price' => '0']);
[, $e] = order_validate($base(['box_size' => 30, 'items' => [['id' => 1, 'qty' => 30]], 'expected_total_cents' => 10500]));
check('other amount can be switched off in Settings', isset($e['box_size']) && box_price(12) === 3800);
settings_save(['cookie_price' => '350']);
check('new occasions offered', array_intersect(PB_NEW_OCCASIONS, occasions()) === PB_NEW_OCCASIONS);
[$d, $e] = order_validate($base(['occasion' => 'Housewarming']));
check('new occasion kept on the order', $e === [] && $d['occasion'] === 'Housewarming');
[, $e] = order_validate($base(['items' => [['id' => 1, 'qty' => 2.9], ['id' => 6, 'qty' => 3.1]]]));
check('flavor quantities must be whole numbers', isset($e['items']));
[$d, $e] = order_validate($base(['items' => [['id' => 1, 'qty' => '2'], ['id' => 1, 'qty' => 1], ['id' => 6, 'qty' => 3]]]));
check('same flavor twice is merged', $e === [] && count($d['items']) === 2 && $d['items'][0]['quantity'] === 3);
[, $e] = order_validate($base(['items' => ['junk', 7, [['nested']]]]));
check('junk items rejected', isset($e['items']));

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
$adminId = (int) db_value('SELECT id FROM admin_users');
admin_set_password($adminId, 'secret-pass-2');
check('password change logs out other devices (session version bumped)', (int) db_value('SELECT session_version FROM admin_users WHERE id = ?', [$adminId]) === 2
    && password_verify('secret-pass-2', (string) db_value('SELECT password_hash FROM admin_users WHERE id = ?', [$adminId])));
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
[$q] = enquiry_validate(['name' => 'Sam', 'email' => 's@example.com', 'type' => '<b>Free text</b>', 'message' => 'Hello there']);
check('unknown inquiry topic filed as a general question', $q['type'] === 'General question');
foreach (['Large order', 'Urgent order', 'Aqiqah', 'Change or cancel an order'] as $t) {
    [$q] = enquiry_validate(['name' => 'Sam', 'email' => 's@example.com', 'type' => $t, 'message' => 'Hello there']);
    if ($q['type'] !== $t) { check("inquiry topic kept: $t", false); }
}
$formTopics = [];
foreach (['contact.html', 'celebrations.html'] as $pageFile) {
    preg_match('#<select name="type" id="e-type">(.*?)</select>#s', (string) file_get_contents(public_dir() . '/' . $pageFile), $sel);
    preg_match_all('#<option[^>]*>([^<]+)</option>#', $sel[1] ?? '', $opts);
    $formTopics = array_merge($formTopics, $opts[1]);
}
check('every topic in the inquiry forms is accepted', count($formTopics) >= 20 && array_diff($formTopics, enquiry_types()) === []);
[, $e] = enquiry_validate(['name' => ['x'], 'email' => 's@example.com', 'message' => ['y']]);
check('inquiry: lists in text fields give errors, not a crash', isset($e['name'], $e['message']));
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
        $v8->exec("CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)");
        $v8->exec("INSERT INTO admin_users (username, password_hash, created_at) VALUES ('pasha', 'x', 'x')");
        migrate($v8, 'sqlite');
        check("v8 upgrade ($was): existing admin keeps working (session version 1)", (int) $v8->query("SELECT session_version FROM admin_users WHERE username = 'pasha'")->fetchColumn() === 1);
        check("v8 upgrade: payment hours $was → $expect", $v8->query("SELECT value FROM settings WHERE name = 'payment_hours'")->fetchColumn() === $expect
            && (int) $v8->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn() === PB_SCHEMA_VERSION);
        $v8 = null;
    }

    // Version 12 → 13: new occasions. An untouched list gets the new default; Pasha's own list is kept and extended.
    $old12 = implode("\n", PB_OLD_OCCASIONS);
    foreach ([$old12 => default_occasions(), "Birthday\nCorporate" => ['Birthday', 'Corporate', ...PB_NEW_OCCASIONS]] as $was => $expect) {
        $v12 = new PDO("sqlite:$tmp/v12-" . md5($was) . '.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $v12->exec("CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $v12->prepare("INSERT INTO settings VALUES ('schema_version', '12'), ('occasions', ?)")->execute([$was]);
        migrate($v12, 'sqlite');
        check('v12 upgrade: occasions ' . ($was === $old12 ? 'updated' : 'kept and extended'),
            text_lines((string) $v12->query("SELECT value FROM settings WHERE name = 'occasions'")->fetchColumn()) === $expect
            && $v12->query("SELECT value FROM settings WHERE name = 'cookie_price'")->fetchColumn() === '350');
        $v12 = null;
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
$capPhone = (string) db_value('SELECT phone FROM orders WHERE email = ? LIMIT 1', [$mail]);
check('unpaid orders also found by phone (any format, other email)', unpaid_orders_for_customer('new-address@example.com', '+1 ' . preg_replace('/\D/', '', $capPhone)) >= 2
    && unpaid_orders_for_customer('new-address@example.com', '(111) 222-3333') === 0);
$paidId = (int) db_value("SELECT id FROM orders WHERE email = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$mail]);
order_mark_paid($paidId);
check('a paid order doesn’t block ordering again', unpaid_orders_for_email($mail) === 1);
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

// Customer data requests (privacy notice: delete on request)
$who = 'test@example.com';
$r = customer_records('Test@Example.com ');
check('data request: finds current and archived orders', count($r['orders']) === 1 && count($r['archived']) > 0);
$done = forget_customer($who);
check('data request: order still waiting for payment is left alone', $done['skipped'] === 1 && $done['orders'] === 0
    && order_find((int) $first['id'])['email'] === $who);
check('data request: archived orders lose the details', $done['archived'] > 0
    && (int) db_value('SELECT COUNT(*) FROM archived_orders WHERE email = ?', [$who]) === 0
    && (int) db_value("SELECT COUNT(*) FROM archived_orders WHERE customer_name = ? AND phone = '' AND total_cents > 0", [PB_FORGOTTEN_NAME]) === $done['archived']);
send_customer_receipt(order_find((int) $first['id']));
order_set_status((int) $first['id'], 'completed');
$done = forget_customer($who);
$f = order_find((int) $first['id']);
check('data request: finished order keeps cookies and total, loses details', $done['orders'] === 1 && $f['email'] === '' && $f['phone'] === ''
    && $f['notes'] === '' && $f['payer_ref'] === '' && $f['customer_name'] === PB_FORGOTTEN_NAME && (int) $f['total_cents'] === 2000
    && count(order_items((int) $f['id'])) === 2 && (int) db_value('SELECT COUNT(*) FROM email_log WHERE order_id = ? OR recipient = ?', [$f['id'], $who]) === 0);
$done = forget_customer('A@Example.com');
check('data request: inquiries deleted', $done['enquiries'] === 1 && customer_records('a@example.com')['enquiries'] === []);

// Structured data (JSON-LD) must say exactly what the pages say.
require_once PB_ROOT . '/tools/structured-data.php';
$sdPrices = sd_default_prices();
$sdStale = array_keys(array_filter(sd_build(public_dir(), $sdPrices), fn($html, $file) => $html !== sd_read($file), ARRAY_FILTER_USE_BOTH));
check('structured data up to date (else run: php server/tools/structured-data.php)', $sdStale === []);
$faqHtml = (string) file_get_contents(public_dir() . '/faq.html');
preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $faqHtml, $sdFaq);
$sdFaqData = json_decode($sdFaq[1] ?? '', true);
check('FAQ structured data: every visible question, same answers', is_array($sdFaqData) && count($sdFaqData['mainEntity']) === count(sd_faq_items($faqHtml))
    && count($sdFaqData['mainEntity']) >= 16 && in_array('Do you deliver or ship?', array_column($sdFaqData['mainEntity'], 'name'), true));
preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) file_get_contents(public_dir() . '/index.html'), $sdBiz);
$sdBizData = json_decode($sdBiz[1] ?? '', true);
$sdOffers = array_column($sdBizData['@graph'][1]['hasOfferCatalog']['itemListElement'] ?? [], 'price');
check('bakery structured data: box prices = the prices customers pay', $sdOffers === array_map(fn($c) => number_format($c / 100, 2, '.', ''), array_values(box_prices()))
    && $sdPrices === box_prices() && ($sdBizData['@graph'][1]['priceRange'] ?? '') === '$14–$114');
check('bakery structured data: no invented facts (no hours, street address, ratings)', !isset($sdBizData['@graph'][1]['openingHours'], $sdBizData['@graph'][1]['aggregateRating'],
    $sdBizData['@graph'][1]['address']['streetAddress'], $sdBizData['@graph'][1]['telephone']));
check('visible FAQ prices match the box prices', str_contains($faqHtml, 'A dozen (12 cookies) is ' . money(box_prices()[12]) . '.'));

// Meta ads measurement: switched off unless configured; when on, only for customers who allowed it.
check('ads measurement is off by default', !meta_enabled() && !meta_capi_enabled());
[$offData] = order_validate($base(['client_token' => bin2hex(random_bytes(12)), 'ad_consent' => true, 'fbp' => 'fb.1.1712345678901.AbC123']));
check('while off, consent and browser ids are never stored', $offData['ad_consent'] === 0 && $offData['fbp'] === '' && $offData['fbc'] === '');
check('while off, nothing is sent', meta_send_order_event('Lead', ['ad_consent' => 1] + order_find((int) $first['id']), 'X') === false);
$metaConfig = "$tmp/config-meta.php";
file_put_contents($metaConfig, '<?php return ' . var_export([
    'site_url' => 'https://example.test', 'setup_key' => 'meta-test-key-000', 'data_dir' => "$tmp/meta-data",   // gitleaks:allow — test only
    'db' => ['driver' => 'sqlite', 'sqlite' => "$tmp/meta.sqlite"], 'mail' => ['transport' => 'log', 'from_email' => 'test@example.com'],
    'meta' => ['pixel_id' => '1234567890', 'transport' => 'log'],
], true) . ';');
file_put_contents("$tmp/meta-child.php", '<?php
$_SERVER["REQUEST_URI"] = "/cli-meta-test"; $_SERVER["REMOTE_ADDR"] = "203.0.113.5"; $_SERVER["HTTP_USER_AGENT"] = "TestBrowser/1.0";
require ' . var_export(PB_ROOT . '/bootstrap.php', true) . ';
$in = fn(array $o) => array_merge(["name" => "Meta Tester", "email" => " Meta.Tester@Example.com ", "phone" => "(978) 555-0100", "occasion" => "", "notes" => "SECRET NOTE",
    "box_size" => 6, "items" => [["id" => 1, "qty" => 6]], "pickup_date" => earliest_pickup_date()->modify("+1 day")->format("Y-m-d"),
    "pickup_slot" => pickup_slots()[0], "payment_method" => "venmo", "payer_ref" => "@secretpayer", "agree" => true], $o);
[$yes, $e1] = order_validate($in(["client_token" => bin2hex(random_bytes(12)), "ad_consent" => true, "fbp" => "fb.1.1712345678901.AbC123", "fbc" => "javascript:alert(1)"]));
[$no, $e2] = order_validate($in(["client_token" => bin2hex(random_bytes(12)), "ad_consent" => false, "fbp" => "fb.1.1712345678901.XyZ"]));
[$a] = order_create($yes); [$b] = order_create($no);
meta_send_order_event("Lead", $a, $a["code"], true); meta_send_order_event("Lead", $b, $b["code"], true);
order_mark_paid((int) $a["id"]); meta_send_order_event("Purchase", order_find((int) $a["id"]), $a["code"] . "-paid");
$events = array_map(fn($l) => json_decode($l, true)["data"][0], array_filter(explode("\n", (string) @file_get_contents(data_dir("meta") . "/events.jsonl"))));
order_set_status((int) $a["id"], "completed"); forget_customer("meta.tester@example.com");
echo json_encode(["enabled" => meta_enabled(), "errors" => $e1 + $e2, "stored" => [$a["ad_consent"], $a["fbp"], $a["fbc"], $b["ad_consent"], $b["fbp"]],
    "codes" => [$a["code"], $b["code"]], "events" => $events, "raw" => (string) @file_get_contents(data_dir("meta") . "/events.jsonl"),
    "afterForget" => db_one("SELECT fbp, ad_consent FROM orders WHERE id = ?", [(int) $a["id"]])]);
');
putenv("PB_CONFIG=$metaConfig");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tmp/meta-child.php"), $metaOut, $metaRc);
putenv("PB_CONFIG=$config");
$m = json_decode(implode("\n", $metaOut), true) ?: [];
check('ads on: consenting order keeps its Meta ids (bad ones dropped)', ($m['enabled'] ?? false) && ($m['errors'] ?? ['x']) === []
    && ($m['stored'] ?? []) === [1, 'fb.1.1712345678901.AbC123', '', 0, '']);
$ev = $m['events'] ?? [];
check('ads on: Lead + Purchase sent only for the consenting customer, with matching ids', count($ev) === 2
    && $ev[0]['event_name'] === 'Lead' && $ev[0]['event_id'] === $m['codes'][0] && $ev[1]['event_name'] === 'Purchase' && $ev[1]['event_id'] === $m['codes'][0] . '-paid');
check('ads on: email/phone hashed as Meta requires; value and currency set', ($ev[0]['user_data']['em'][0] ?? '') === hash('sha256', 'meta.tester@example.com')
    && ($ev[0]['user_data']['ph'][0] ?? '') === hash('sha256', '19785550100') && ($ev[0]['custom_data']['value'] ?? 0) == 20 && ($ev[0]['custom_data']['currency'] ?? '') === 'USD'
    && ($ev[0]['user_data']['client_ip_address'] ?? '') === '203.0.113.5' && !isset($ev[1]['user_data']['client_ip_address']));
check('ads on: no name, email, notes or payer text ever leaves the site', ($m['raw'] ?? '') !== '' && !preg_match('/Meta Tester|example\.com|SECRET NOTE|secretpayer|Tester/i', $m['raw']));
check('deleting a customer also clears their Meta ids', ($m['afterForget'] ?? null) === ['fbp' => '', 'ad_consent' => 0]);

$logFile = "$tmp/masked.log";
$previousLog = ini_get('error_log');
ini_set('error_log', $logFile);
log_error('Email to some.one+tag@example.com failed: SMTP said <x@y.z>');
ini_set('error_log', (string) $previousLog);
$logged = (string) @file_get_contents($logFile);
check('error log masks email addresses', str_contains($logged, '[email]') && !str_contains($logged, 'example.com') && !str_contains($logged, 'x@y.z'));

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
