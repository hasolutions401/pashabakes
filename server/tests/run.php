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
    foreach (['order_items', 'orders', 'cookies', 'settings', 'admin_users', 'email_log', 'rate_hits'] as $t) {
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
check('payment + agree required', isset($e['payment_method'], $e['payer_ref'], $e['agree']));
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

rate_hit('t'); rate_hit('t');
check('rate limit counts', !rate_allowed('t', 2, 60) && rate_allowed('t', 3, 60));

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
