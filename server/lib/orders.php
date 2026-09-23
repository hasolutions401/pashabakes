<?php
declare(strict_types=1);

/*
 * Orders: validation, creation and status changes.
 * Every price and rule is re-checked here — the browser is never trusted.
 */

const PB_STATUSES = ['pending', 'paid', 'completed', 'cancelled'];
const PB_MAX_COOKIES = 36;

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
        'client_token' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['client_token'] ?? '')),
        'customer_name' => clean_text($in['name'] ?? '', 120),
        'email' => mb_strtolower(clean_text($in['email'] ?? '', 200)),
        'phone' => clean_text($in['phone'] ?? '', 40),
        'occasion' => clean_text($in['occasion'] ?? '', 60),
        'notes' => clean_text($in['notes'] ?? '', 1000),
        'box_size' => (int) ($in['box_size'] ?? 0),
        'pickup_date' => clean_text($in['pickup_date'] ?? '', 10),
        'pickup_slot' => clean_text($in['pickup_slot'] ?? '', 60),
        'payment_method' => (string) ($in['payment_method'] ?? ''),
        'payer_ref' => clean_text($in['payer_ref'] ?? '', 120),
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
    foreach ((array) ($in['items'] ?? []) as $item) {
        $id = (int) ($item['id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if (!isset($cookies[$id])) {
            $errors['items'] = 'One of the flavors you picked is no longer available. Please review your box.';
            continue;
        }
        if ($qty > PB_MAX_COOKIES) {
            $errors['items'] = 'Please check your flavor quantities.';
            continue;
        }
        $data['items'][] = ['cookie_id' => $id, 'cookie_name' => $cookies[$id]['name'], 'quantity' => $qty];
        $count += $qty;
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
    }
    if (!in_array($data['pickup_slot'], pickup_slots(), true)) {
        $errors['pickup_slot'] = 'Please choose a pickup time.';
    }

    // Payment
    if (!in_array($data['payment_method'], ['venmo', 'cashapp'], true)) {
        $errors['payment_method'] = 'Please choose Venmo or Cash App.';
    }
    if (mb_strlen($data['payer_ref']) < 2) {
        $errors['payer_ref'] = 'Please enter the Venmo/Cash App username (or transaction ID) you paid from.';
    }
    if (empty($in['agree'])) {
        $errors['agree'] = 'Please confirm you have read the allergy and cancellation information.';
    }

    // Price check: the total the customer saw must match the current price.
    $data['total_cents'] = $prices[$data['box_size']] ?? 0;
    if (!isset($errors['box_size']) && isset($in['expected_total_cents']) && (int) $in['expected_total_cents'] !== $data['total_cents']) {
        $errors['box_size'] = 'Prices were just updated. The total for your box is now ' . money($data['total_cents']) . '. Please review before paying.';
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
