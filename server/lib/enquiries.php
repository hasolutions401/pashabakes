<?php
declare(strict_types=1);

/*
 * Enquiries from the Contact and Celebrations pages. Each one is saved (so nothing
 * is lost if an email fails), emailed to Pasha, and listed in Admin → Enquiries.
 */

const PB_GENERAL_ENQUIRY = 'General question';
/** Topics about an order already placed: they ask for the order number instead of event details. */
const PB_ORDER_ENQUIRIES = ['Existing order', 'Payment question', 'Change or cancel an order', 'Pickup question'];

/** Topics offered in the Contact and Celebrations forms (keep in step with their "type" menus). */
function enquiry_types(): array
{
    return [PB_GENERAL_ENQUIRY, ...PB_ORDER_ENQUIRIES, 'Urgent order', 'Large order', ...occasions()];
}

/** Returns [clean data, errors keyed by field]. */
function enquiry_validate(array $in): array
{
    $type = clean_line($in['type'] ?? '', 60);
    $data = [
        'name' => clean_line($in['name'] ?? '', 120),
        'email' => mb_strtolower(clean_text($in['email'] ?? '', 200)),
        // Only the topics offered in the form; anything else is filed as a general question.
        'type' => in_array($type, enquiry_types(), true) ? $type : PB_GENERAL_ENQUIRY,
        'event_date' => clean_text($in['date'] ?? '', 10),
        'quantity' => clean_line($in['quantity'] ?? '', 80),
        'message' => clean_text($in['message'] ?? '', 1800),
        'order_ref' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', clean_text($in['order_ref'] ?? '', 40))),
    ];
    $data['order_ref'] = substr($data['order_ref'], 0, 20);
    $errors = [];

    if (mb_strlen($data['name']) < 2) {
        $errors['name'] = 'Please enter your name.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address, like name@example.com.';
    }
    if (mb_strlen($data['message']) < 5) {
        $errors['message'] = 'Please tell Pasha a little about what you have in mind.';
    }

    // Event date and quantity only matter for events, urgent and large orders;
    // the order number only for questions about an existing order.
    $orderTopic = in_array($data['type'], PB_ORDER_ENQUIRIES, true);
    if ($data['type'] === PB_GENERAL_ENQUIRY || $orderTopic) {
        $data['event_date'] = '';
        $data['quantity'] = '';
    }
    if (!$orderTopic) {
        $data['order_ref'] = '';
    }
    if ($data['event_date'] !== '') {
        $date = parse_date($data['event_date']);
        if (!$date) {
            $errors['date'] = 'Please enter the event date, or leave it empty.';
        } elseif ($date < today()) {
            $errors['date'] = 'That date has already passed. Please check the event date.';
        }
    }
    return [$data, $errors];
}

/**
 * Saves an enquiry. The same message sent twice within 10 minutes (double-click,
 * retry) is only saved once. Returns [row, bool created].
 */
function enquiry_create(array $data): array
{
    $since = (new DateTimeImmutable('now', new DateTimeZone(PB_TZ)))->modify('-10 minutes')->format('Y-m-d H:i:s');
    $existing = db_one('SELECT * FROM enquiries WHERE email = ? AND message = ? AND created_at >= ?', [$data['email'], $data['message'], $since]);
    if ($existing) {
        return [$existing, false];
    }
    db_exec('INSERT INTO enquiries (status, name, email, type, event_date, quantity, message, order_ref, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'new', $data['name'], $data['email'], $data['type'], $data['event_date'] !== '' ? $data['event_date'] : null,
        $data['quantity'], $data['message'], $data['order_ref'], now_str(),
    ]);
    return [db_one('SELECT * FROM enquiries WHERE id = ?', [(int) db()->lastInsertId()]), true];
}

function enquiry_new_count(): int
{
    return (int) db_value("SELECT COUNT(*) FROM enquiries WHERE status = 'new'");
}

/** Newest first; "new" or "done" filters by status, anything else shows all. */
function enquiry_list(string $status, int $page, int $perPage = 25): array
{
    $where = in_array($status, ['new', 'done'], true) ? 'WHERE status = ?' : '';
    $params = $where ? [$status] : [];
    $total = (int) db_value("SELECT COUNT(*) FROM enquiries {$where}", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $rows = db_all("SELECT * FROM enquiries {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", $params);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function enquiry_set_status(int $id, string $status): void
{
    db_exec('UPDATE enquiries SET status = ? WHERE id = ?', [$status === 'done' ? 'done' : 'new', $id]);
}
