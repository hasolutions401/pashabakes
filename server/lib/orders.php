<?php
declare(strict_types=1);

/*
 * Orders: validation, creation and status changes.
 * Every price and rule is re-checked here — the browser is never trusted.
 */

const PB_STATUSES = ['pending', 'paid', 'completed', 'cancelled'];
const PB_MAX_COOKIES = 36;
/** Unpaid orders one email address can have waiting at once (stops fake orders filling pickup days). */
const PB_MAX_UNPAID_PER_EMAIL = 3;

/** Thrown when the pickup day filled up while the order was being placed. */
class DayFullException extends RuntimeException
{
}

/**
 * Validates a checkout submission.
 * Returns [clean data, errors keyed by field]. Errors are customer-friendly.
 */
function order_validate(array $in): array
{
    $errors = [];
    $prices = box_prices();
    $cookies = [];
    foreach (menu_cookies() as $c) {
        $cookies[$c['id']] = $c;
    }

    $data = [
        'client_token' => preg_replace('/[^A-Za-z0-9_-]/', '', clean_text($in['client_token'] ?? '', 100)),
        'customer_name' => clean_line($in['name'] ?? '', 120),
        'email' => mb_strtolower(clean_text($in['email'] ?? '', 200)),
        'phone' => clean_line($in['phone'] ?? '', 40),
        'occasion' => clean_line($in['occasion'] ?? '', 60),
        'notes' => clean_text($in['notes'] ?? '', 1000),
        'box_size' => whole_number($in['box_size'] ?? null) ?? 0,
        'pickup_date' => clean_text($in['pickup_date'] ?? '', 10),
        'pickup_slot' => clean_line($in['pickup_slot'] ?? '', 60),
        'payment_method' => clean_text($in['payment_method'] ?? '', 20),
        'payer_ref' => clean_line($in['payer_ref'] ?? '', 120),
        'items' => [],
    ];

    if (strlen($data['client_token']) < 16 || strlen($data['client_token']) > 64) {
        $errors['form'] = 'Something went wrong with the form. Please refresh the page and try again.';
    }

    // Box & flavors
    if (!isset($prices[$data['box_size']])) {
        $errors['box_size'] = 'Please choose a box size.';
    }
    $count = 0;
    $byId = [];   // the same flavor listed twice counts once, with the quantities added up
    foreach ((array) ($in['items'] ?? []) as $item) {
        $id = whole_number(is_array($item) ? ($item['id'] ?? null) : null) ?? 0;
        $qty = is_array($item) ? whole_number($item['qty'] ?? null) : null;
        if ($qty === null) {
            $errors['items'] = 'Please check your flavor quantities.';
            continue;
        }
        if ($qty <= 0) {
            continue;
        }
        if (!isset($cookies[$id])) {
            $errors['items'] = 'One of the flavors you picked is no longer available. Please review your box.';
            continue;
        }
        $byId[$id] = ($byId[$id] ?? 0) + $qty;
        $count += $qty;
    }
    foreach ($byId as $id => $qty) {
        if ($qty > PB_MAX_COOKIES) {
            $errors['items'] = 'Please check your flavor quantities.';
            continue;
        }
        $data['items'][] = ['cookie_id' => $id, 'cookie_name' => $cookies[$id]['name'], 'quantity' => $qty];
    }
    if (!isset($errors['items']) && !isset($errors['box_size']) && $count !== $data['box_size']) {
        $errors['items'] = $count < $data['box_size']
            ? sprintf('Please choose %d more cookie%s to fill your box of %d.', $data['box_size'] - $count, $data['box_size'] - $count === 1 ? '' : 's', $data['box_size'])
            : sprintf('Please remove %d cookie%s to fit your box of %d.', $count - $data['box_size'], $count - $data['box_size'] === 1 ? '' : 's', $data['box_size']);
    }

    // Contact details
    if (mb_strlen($data['customer_name']) < 2) {
        $errors['name'] = 'Please enter your name.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address, like name@example.com.';
    }
    $digits = preg_replace('/\D/', '', $data['phone']);
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        $errors['phone'] = 'Please enter a phone number with area code.';
    }
    if ($data['occasion'] !== '' && !in_array($data['occasion'], occasions(), true)) {
        $data['occasion'] = '';
    }

    // Pickup
    $date = parse_date($data['pickup_date']);
    if (!$date) {
        $errors['pickup_date'] = 'Please choose a pickup date.';
    } elseif ($date < earliest_pickup_date()) {
        $errors['pickup_date'] = sprintf('Orders need %d days’ notice. The earliest pickup date is %s. For anything sooner, please email pashabakess@gmail.com.',
            lead_days(), earliest_pickup_date()->format('l, F j'));
    } elseif ($date > latest_pickup_date()) {
        $errors['pickup_date'] = 'That date is too far ahead. Please choose a date before ' . latest_pickup_date()->format('F j, Y') . '.';
    } elseif (in_array($data['pickup_date'], unavailable_dates(), true)) {
        $errors['pickup_date'] = 'Pasha isn’t available for pickups on that date. Please choose another day.';
    } elseif (isset($prices[$data['box_size']]) && ($full = capacity_problem($data['pickup_date'], $data['box_size']))) {
        $errors['pickup_date'] = $full;
    }
    if (!in_array($data['pickup_slot'], pickup_slots(), true)) {
        $errors['pickup_slot'] = 'Please choose a pickup time.';
    }

    // Monthly specials (and any flavor with pickup dates set) only for their dates.
    if ($date && !isset($errors['pickup_date']) && !isset($errors['items'])) {
        foreach ($data['items'] as $it) {
            $c = $cookies[$it['cookie_id']];
            if (!cookie_available_on($c, $data['pickup_date'])) {
                $errors['items'] = sprintf('%s is available for %s, so it can’t be picked up on %s. Please choose another flavor or pickup date.',
                    $c['name'], cookie_window_text($c), $date->format('l, F j'));
                break;
            }
        }
    }

    // Payment happens after the order is placed, so the payer name is optional.
    if (!in_array($data['payment_method'], ['venmo', 'cashapp'], true)) {
        $errors['payment_method'] = 'Please choose Venmo or Cash App.';
    }
    if (empty($in['agree'])) {
        $errors['agree'] = 'Please confirm you have read the allergy information and the cancellation and refund policy.';
    }

    // Price check: the total the customer saw must match the current price.
    $data['total_cents'] = $prices[$data['box_size']] ?? 0;
    if (!isset($errors['box_size']) && isset($in['expected_total_cents']) && (int) $in['expected_total_cents'] !== $data['total_cents']) {
        $errors['box_size'] = 'Prices were just updated. The total for your box is now ' . money($data['total_cents']) . '. Please review your order.';
    }

    return [$data, $errors];
}

/**
 * Saves a validated order. If the same submission arrives twice (double-click,
 * slow network retry), the existing order is returned instead of a duplicate.
 * Returns [order row, bool created].
 */
function order_create(array $data): array
{
    $existing = db_one('SELECT * FROM orders WHERE client_token = ?', [$data['client_token']]);
    if ($existing) {
        return [$existing, false];
    }

    try {
        $order = db_transaction(function (PDO $pdo) use ($data) {
            // Re-check the daily limit inside the transaction, so two customers can't both take the last spot.
            if (max_cookies_per_day() > 0) {
                $lock = db_driver() === 'mysql' ? ' FOR UPDATE' : '';   // SQLite: BEGIN IMMEDIATE already serialises writers
                [$holding, $params] = holding_orders_sql();
                $st = $pdo->prepare("SELECT COALESCE(SUM(box_size), 0) FROM orders WHERE pickup_date = ? AND {$holding}{$lock}");
                $st->execute([$data['pickup_date'], ...$params]);
                if ($problem = capacity_problem($data['pickup_date'], $data['box_size'], (int) $st->fetchColumn())) {
                    throw new DayFullException($problem);
                }
            }
            $pdo->prepare('INSERT INTO orders (client_token, status, customer_name, email, phone, occasion, notes, box_size, total_cents,
                    pickup_date, pickup_slot, payment_method, payer_ref, admin_note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $data['client_token'], 'pending', $data['customer_name'], $data['email'], $data['phone'], $data['occasion'],
                    $data['notes'], $data['box_size'], $data['total_cents'], $data['pickup_date'], $data['pickup_slot'],
                    $data['payment_method'], $data['payer_ref'], '', now_str(),
                ]);
            $id = (int) $pdo->lastInsertId();
            $code = 'PB' . (1000 + $id);
            $pdo->prepare('UPDATE orders SET code = ? WHERE id = ?')->execute([$code, $id]);
            $item = $pdo->prepare('INSERT INTO order_items (order_id, cookie_id, cookie_name, quantity) VALUES (?, ?, ?, ?)');
            foreach ($data['items'] as $it) {
                $item->execute([$id, $it['cookie_id'], $it['cookie_name'], $it['quantity']]);
            }
            return $id;
        });
    } catch (DayFullException $e) {
        throw $e;
    } catch (PDOException $e) {
        // A parallel duplicate submission won the race — return that order.
        $existing = db_one('SELECT * FROM orders WHERE client_token = ?', [$data['client_token']]);
        if ($existing) {
            return [$existing, false];
        }
        throw $e;
    }

    return [order_find($order), true];
}

function order_find(int $id): ?array
{
    return db_one('SELECT * FROM orders WHERE id = ?', [$id]);
}

function order_token_exists(string $token): bool
{
    return (int) db_value('SELECT COUNT(*) FROM orders WHERE client_token = ?', [$token]) > 0;
}

/** Unpaid orders from this email address that still hold their pickup day. */
function unpaid_orders_for_email(string $email): int
{
    [$holding, $params] = holding_orders_sql();
    return (int) db_value("SELECT COUNT(*) FROM orders WHERE email = ? AND status = 'pending' AND {$holding}", [$email, ...$params]);
}

function order_items(int $orderId): array
{
    return db_all('SELECT cookie_id, cookie_name, quantity FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

function order_items_text(int $orderId): string
{
    return implode(', ', array_map(fn($i) => $i['quantity'] . ' × ' . $i['cookie_name'], order_items($orderId)));
}

/** Changes an order's status and stamps the matching time. */
function order_set_status(int $id, string $status): void
{
    if (!in_array($status, PB_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown status');
    }
    $now = now_str();
    $stamps = ['paid' => 'paid_at', 'completed' => 'completed_at', 'cancelled' => 'cancelled_at'];
    if ($status === 'pending') {
        db_exec('UPDATE orders SET status = ?, paid_at = NULL, completed_at = NULL, cancelled_at = NULL WHERE id = ?', [$status, $id]);
    } else {
        $col = $stamps[$status];
        db_exec("UPDATE orders SET status = ?, {$col} = COALESCE({$col}, ?) WHERE id = ?", [$status, $now, $id]);
    }
}

/**
 * Pending → paid, exactly once. Returns false if the order was not pending
 * (e.g. the button was pressed twice), so the email is only sent once.
 */
function order_mark_paid(int $id): bool
{
    return db_exec("UPDATE orders SET status = 'paid', paid_at = ? WHERE id = ? AND status = 'pending'", [now_str(), $id]) === 1;
}

function order_counts(): array
{
    $counts = array_fill_keys(PB_STATUSES, 0);
    foreach (db_all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

/** Filtered, paginated order list for the dashboard. */
function order_list(string $status, string $search, int $page, int $perPage = 25): array
{
    $where = [];
    $params = [];
    if (in_array($status, PB_STATUSES, true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(code LIKE ? OR customer_name LIKE ? OR email LIKE ? OR phone LIKE ? OR payer_ref LIKE ?)';
        // "_" still works as a wildcard here, which only ever widens a search slightly.
        $like = '%' . str_replace('%', '', $search) . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    // Orders still to bake are sorted by pickup date; everything else newest first.
    $order = $status === 'paid' ? 'pickup_date ASC, id ASC' : 'id DESC';

    $total = (int) db_value("SELECT COUNT(*) FROM orders {$sqlWhere}", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $rows = db_all("SELECT * FROM orders {$sqlWhere} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}", $params);
    foreach ($rows as &$row) {
        $row['items_text'] = order_items_text((int) $row['id']);
    }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Paid orders for the next N days, grouped by pickup date, with flavor totals (the "baking list"). */
function baking_plan(int $days = 14): array
{
    $from = today()->format('Y-m-d');
    $to = today()->modify("+{$days} days")->format('Y-m-d');
    $rows = db_all("SELECT o.pickup_date, i.cookie_name, SUM(i.quantity) AS qty
        FROM orders o JOIN order_items i ON i.order_id = o.id
        WHERE o.status = 'paid' AND o.pickup_date BETWEEN ? AND ?
        GROUP BY o.pickup_date, i.cookie_name
        ORDER BY o.pickup_date, i.cookie_name", [$from, $to]);
    $orders = db_all("SELECT pickup_date, COUNT(*) AS n FROM orders WHERE status = 'paid' AND pickup_date BETWEEN ? AND ? GROUP BY pickup_date", [$from, $to]);
    $plan = [];
    foreach ($orders as $o) {
        $plan[$o['pickup_date']] = ['orders' => (int) $o['n'], 'cookies' => 0, 'flavors' => []];
    }
    foreach ($rows as $r) {
        $plan[$r['pickup_date']]['flavors'][$r['cookie_name']] = (int) $r['qty'];
        $plan[$r['pickup_date']]['cookies'] += (int) $r['qty'];
    }
    ksort($plan);
    return $plan;
}

/** Permanently deletes a cancelled order with its items and email history. Returns false for any other status. */
function order_delete(int $id): bool
{
    $order = order_find($id);
    if (!$order || $order['status'] !== 'cancelled') {
        return false;
    }
    db_transaction(function (PDO $pdo) use ($id) {
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM email_log WHERE order_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    });
    return true;
}

/** The number the next order will get, e.g. "PB1016". */
function next_order_code(): string
{
    if (db_driver() === 'mysql') {
        $next = (int) db_value("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
    } else {
        $next = (int) db_value("SELECT seq FROM sqlite_sequence WHERE name = 'orders'") + 1;
    }
    return 'PB' . (1000 + max(1, $next));
}

/* ——— Customer data requests ("please delete my details", see privacy.html) ——— */

const PB_FORGOTTEN_NAME = 'Deleted customer';

/** Everything stored for one email address: current orders, archived orders and inquiries. */
function customer_records(string $email): array
{
    $email = mb_strtolower(trim($email));
    return [
        'orders' => db_all('SELECT id, code, status, pickup_date, total_cents FROM orders WHERE email = ? ORDER BY id', [$email]),
        'archived' => db_all('SELECT id, code, status, pickup_date, total_cents FROM archived_orders WHERE email = ? ORDER BY id', [$email]),
        'enquiries' => db_all('SELECT id, type, created_at FROM enquiries WHERE email = ? ORDER BY id', [$email]),
    ];
}

/**
 * Removes a customer's personal details: finished orders (picked up or cancelled) and archived
 * orders keep only the cookies, amounts and dates for Pasha's records; inquiries and the email
 * history are deleted. Orders still waiting for payment or pickup are left alone.
 * Returns counts: ['orders' => n, 'archived' => n, 'enquiries' => n, 'skipped' => n].
 */
function forget_customer(string $email): array
{
    $email = mb_strtolower(trim($email));
    if ($email === '') {
        return ['orders' => 0, 'archived' => 0, 'enquiries' => 0, 'skipped' => 0];
    }
    return db_transaction(function (PDO $pdo) use ($email) {
        $blank = "customer_name = ?, email = '', phone = '', notes = '', payer_ref = '', admin_note = ''";
        $ids = array_map('intval', array_column(db_all("SELECT id FROM orders WHERE email = ? AND status IN ('completed', 'cancelled')", [$email]), 'id'));
        $skipped = (int) db_value("SELECT COUNT(*) FROM orders WHERE email = ? AND status IN ('pending', 'paid')", [$email]);
        foreach ($ids as $id) {
            $pdo->prepare("UPDATE orders SET {$blank} WHERE id = ?")->execute([PB_FORGOTTEN_NAME, $id]);
            $pdo->prepare('DELETE FROM email_log WHERE order_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM email_log WHERE recipient = ? AND (order_id IS NULL OR order_id NOT IN (SELECT id FROM orders WHERE status IN (\'pending\', \'paid\')))')
            ->execute([$email]);
        $archived = $pdo->prepare("UPDATE archived_orders SET {$blank} WHERE email = ?");
        $archived->execute([PB_FORGOTTEN_NAME, $email]);
        $enquiries = $pdo->prepare('DELETE FROM enquiries WHERE email = ?');
        $enquiries->execute([$email]);
        return ['orders' => count($ids), 'archived' => $archived->rowCount(), 'enquiries' => $enquiries->rowCount(), 'skipped' => $skipped];
    });
}

/** Orders still waiting for payment or pickup (they block restarting the numbers). */
function open_order_count(): int
{
    return (int) db_value("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'paid')");
}

/**
 * Starts order numbers again at PB1001. Every finished order (picked up or cancelled) is
 * first moved to the archive, where it stays viewable and downloadable. Not allowed while
 * any order still waits for payment or pickup, because its number could then be given
 * out again. Returns an error message, or null on success.
 */
function restart_order_numbers(): ?string
{
    $open = open_order_count();
    if ($open > 0) {
        return "{$open} order" . ($open === 1 ? ' is' : 's are') . ' still waiting for payment or pickup. Restart the numbers once every order is picked up or cancelled.';
    }
    db_transaction(function (PDO $pdo) {
        $archive = $pdo->prepare('INSERT INTO archived_orders (code, status, customer_name, email, phone, occasion, notes, box_size, total_cents,
                pickup_date, pickup_slot, payment_method, payer_ref, admin_note, items_text, created_at, paid_at, completed_at, cancelled_at, archived_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $now = now_str();
        foreach ($pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll() as $o) {
            $archive->execute([$o['code'], $o['status'], $o['customer_name'], $o['email'], $o['phone'], $o['occasion'], $o['notes'],
                $o['box_size'], $o['total_cents'], $o['pickup_date'], $o['pickup_slot'], $o['payment_method'], $o['payer_ref'],
                $o['admin_note'], order_items_text((int) $o['id']), $o['created_at'], $o['paid_at'], $o['completed_at'], $o['cancelled_at'], $now]);
        }
        $pdo->exec('DELETE FROM order_items');
        $pdo->exec('DELETE FROM email_log WHERE order_id IS NOT NULL');
        $pdo->exec('DELETE FROM orders');
        if (db_driver() === 'sqlite') {
            $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('orders', 'order_items')");
        }
    });
    if (db_driver() === 'mysql') {
        // ALTER TABLE ends a MySQL transaction, so it runs after the move.
        db()->exec('ALTER TABLE orders AUTO_INCREMENT = 1');
        db()->exec('ALTER TABLE order_items AUTO_INCREMENT = 1');
    }
    return null;
}

/** Archived orders (from earlier order-number runs), newest first, with an optional search. */
function archived_order_list(string $search, int $page, int $perPage = 25): array
{
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = 'WHERE (code LIKE ? OR customer_name LIKE ? OR email LIKE ? OR phone LIKE ? OR payer_ref LIKE ?)';
        $like = '%' . str_replace('%', '', $search) . '%';
        $params = [$like, $like, $like, $like, $like];
    }
    $total = (int) db_value("SELECT COUNT(*) FROM archived_orders {$where}", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $rows = db_all("SELECT * FROM archived_orders {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", $params);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** One CSV cell. Text that a spreadsheet would run as a formula (= + - @) is prefixed with '. */
function csv_cell($value): string
{
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

/** Rows for the CSV download of current orders ('orders') or archived orders ('archive'). */
function orders_csv_rows(string $which): array
{
    $head = ['Order', 'Status', 'Placed', 'Name', 'Email', 'Phone', 'Box', 'Total', 'Pickup date', 'Pickup time', 'Payment app',
        'Paying from', 'Occasion', 'Cookies', 'Customer notes', 'Private note', 'Paid', 'Picked up', 'Cancelled'];
    if ($which === 'archive') {
        $head[] = 'Archived';
        $rows = db_all('SELECT * FROM archived_orders ORDER BY id');
    } else {
        $rows = db_all('SELECT * FROM orders ORDER BY id');
    }
    $out = [$head];
    foreach ($rows as $o) {
        $line = [$o['code'], status_label($o['status']), $o['created_at'], $o['customer_name'], $o['email'], $o['phone'], $o['box_size'],
            number_format($o['total_cents'] / 100, 2, '.', ''), $o['pickup_date'], $o['pickup_slot'], payment_label($o['payment_method']),
            $o['payer_ref'], $o['occasion'], $which === 'archive' ? $o['items_text'] : order_items_text((int) $o['id']), $o['notes'],
            $o['admin_note'], $o['paid_at'], $o['completed_at'], $o['cancelled_at']];
        if ($which === 'archive') {
            $line[] = $o['archived_at'];
        }
        $out[] = array_map('csv_cell', $line);
    }
    return $out;
}
