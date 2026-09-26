<?php
declare(strict_types=1);

/*
 * Business settings, editable by Pasha in Admin → Settings.
 * Stored as name/value text pairs in the `settings` table.
 */

const PB_BOX_SIZES = [4, 6, 12, 24, 36];
/** Smallest "Other amount" (the largest is PB_MAX_COOKIES; bigger orders are enquiries). */
const PB_CUSTOM_MIN = 4;
/** Occasion choices before the Sept 2026 additions, to recognise a list nobody has edited yet. */
const PB_OLD_OCCASIONS = ['Just because', 'Birthday', 'Holiday', 'Baby shower', 'Bridal shower', 'Eid', 'Aqiqah', 'Graduation', 'Office treat'];
/** Occasions added in Sept 2026 (schema v13). */
const PB_NEW_OCCASIONS = ['Anniversary', 'Housewarming', 'New job', 'Wedding', 'Thank you'];

function default_settings(): array
{
    return [
        'prices'            => json_encode(['4' => 1400, '6' => 2000, '12' => 3800, '24' => 7600, '36' => 11400]),
        'cookie_price'      => '350',  // per cookie for an "Other amount" box ($3.50, Sept 2026); 0 = option off
        'lead_days'         => '7',
        'max_days_ahead'    => '90',
        'max_cookies_per_day' => '60',  // 5 dozen a day (Pasha); 0 = no daily limit
        'payment_hours'     => '24',  // unpaid orders hold their pickup day this long; 0 = no deadline (held until cancelled)
        'pickup_slots'      => implode("\n", [
            '10:00 AM – 11:00 AM', '11:00 AM – 12:00 PM', '12:00 PM – 1:00 PM', '1:00 PM – 2:00 PM',
            '2:00 PM – 3:00 PM', '3:00 PM – 4:00 PM', '4:00 PM – 5:00 PM', '5:00 PM – 6:00 PM', '6:00 PM – 7:00 PM',
        ]),
        'unavailable_dates' => '',
        'pickup_area'       => 'Tyngsboro, MA',
        'pickup_address'    => '',
        'notify_email'      => 'pashabakess@gmail.com',
        'gmail_address'     => 'pashabakess@gmail.com',
        'gmail_app_password' => '',
        'venmo_handle'      => 'Palosha-Rashid',
        'cashapp_handle'    => 'Pashabakess',
        'accepting_orders'  => '1',
        'closed_message'    => 'Online ordering is paused for now. Please email pashabakess@gmail.com and Pasha will help you.',
        'occasions'         => implode("\n", default_occasions()),
    ];
}

function setting(string $name): string
{
    $all = settings_all();
    return $all[$name] ?? (default_settings()[$name] ?? '');
}

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }
    return $cache;
}

function settings_save(array $values): void
{
    db_transaction(function (PDO $pdo) use ($values) {
        $st = $pdo->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)');
        foreach ($values as $name => $value) {
            $st->execute([$name, (string) $value]);
        }
    });
    settings_all(true);
}

/** Box size => price in cents. */
function box_prices(): array
{
    $raw = json_decode(setting('prices'), true);
    $prices = [];
    foreach (PB_BOX_SIZES as $size) {
        $cents = is_array($raw) ? (int) ($raw[(string) $size] ?? 0) : 0;
        if ($cents > 0) {
            $prices[$size] = $cents;
        }
    }
    return $prices;
}

/** Price per cookie for an "Other amount" box, in cents (0 = option off). */
function cookie_price(): int
{
    return max(0, (int) setting('cookie_price'));
}

/**
 * What a box of this many cookies costs, in cents (0 = not offered).
 * The set boxes keep their price; any other amount is charged per cookie.
 */
function box_price(int $count): int
{
    $prices = box_prices();
    if (isset($prices[$count])) {
        return $prices[$count];
    }
    $each = cookie_price();
    return $each > 0 && $count >= PB_CUSTOM_MIN && $count <= PB_MAX_COOKIES ? $count * $each : 0;
}

function default_occasions(): array
{
    return ['Just because', 'Birthday', 'Anniversary', 'Housewarming', 'New job', 'Wedding', 'Graduation', 'Baby shower',
        'Bridal shower', 'Eid', 'Aqiqah', 'Holiday', 'Office treat', 'Thank you'];
}

function pickup_slots(): array
{
    return text_lines(setting('pickup_slots'));
}

function occasions(): array
{
    return text_lines(setting('occasions'));
}

function unavailable_dates(): array
{
    return array_values(array_filter(text_lines(setting('unavailable_dates')), fn($d) => parse_date($d) !== null));
}

function lead_days(): int
{
    return max(0, min(60, (int) setting('lead_days')));
}

function earliest_pickup_date(): DateTimeImmutable
{
    return today()->modify('+' . lead_days() . ' days');
}

function latest_pickup_date(): DateTimeImmutable
{
    return today()->modify('+' . max(lead_days() + 7, (int) setting('max_days_ahead')) . ' days');
}

function accepting_orders(): bool
{
    return setting('accepting_orders') === '1';
}

function venmo_url(): string
{
    return 'https://venmo.com/u/' . rawurlencode(setting('venmo_handle'));
}

function cashapp_url(): string
{
    return 'https://cash.app/$' . rawurlencode(setting('cashapp_handle'));
}

/** The handle customers pay, e.g. "@Palosha-Rashid" or "$Pashabakess". */
function payment_handle(string $method): string
{
    return $method === 'cashapp' ? '$' . setting('cashapp_handle') : '@' . setting('venmo_handle');
}

function payment_url(string $method): string
{
    return $method === 'cashapp' ? cashapp_url() : venmo_url();
}

/** Most cookies Pasha takes for one pickup day (0 = no limit). */
function max_cookies_per_day(): int
{
    return max(0, (int) setting('max_cookies_per_day'));
}

/**
 * SQL condition (and its parameters) for orders that take up room on their pickup day:
 * not cancelled, and — when a payment deadline is set — not unpaid past that deadline.
 */
function holding_orders_sql(): array
{
    $h = payment_hours();
    if ($h === 0) {
        return ["status <> 'cancelled'", []];
    }
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone(PB_TZ)))->modify("-{$h} hours")->format('Y-m-d H:i:s');
    return ["status <> 'cancelled' AND NOT (status = 'pending' AND created_at < ?)", [$cutoff]];
}

/** Cookies already ordered for a pickup day. Cancelled orders don't count; unpaid ones hold their place until the payment deadline. */
function booked_cookies(string $ymd): int
{
    [$holding, $params] = holding_orders_sql();
    return (int) db_value("SELECT COALESCE(SUM(box_size), 0) FROM orders WHERE pickup_date = ? AND {$holding}", [$ymd, ...$params]);
}

/** Cookies still available per pickup day, for days that already have orders (empty when there's no limit). */
function days_remaining(): array
{
    $max = max_cookies_per_day();
    if ($max === 0) {
        return [];
    }
    [$holding, $params] = holding_orders_sql();
    $rows = db_all("SELECT pickup_date, SUM(box_size) AS n FROM orders WHERE {$holding} AND pickup_date >= ? GROUP BY pickup_date",
        [...$params, earliest_pickup_date()->format('Y-m-d')]);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['pickup_date']] = max(0, $max - (int) $r['n']);
    }
    return $out;
}

/** Customer-facing message when a box doesn't fit the day's limit, or null if it fits. */
function capacity_problem(string $ymd, int $boxSize, ?int $booked = null): ?string
{
    $max = max_cookies_per_day();
    if ($max === 0) {
        return null;
    }
    $left = $max - ($booked ?? booked_cookies($ymd));
    if ($boxSize <= $left) {
        return null;
    }
    $day = pretty_date($ymd);
    return $left < min(PB_BOX_SIZES)
        ? "Sorry, {$day} is fully booked. Please choose another pickup date."
        : "Pasha can only take {$left} more cookies for {$day}. Please choose a smaller box or another pickup date.";
}

/** Hours customers are asked to pay within after ordering (0 = no deadline stated). */
function payment_hours(): int
{
    return max(0, min(168, (int) setting('payment_hours')));
}

/** What customers are told about paying: the deadline (if set) and that their date is held meanwhile. */
function payment_hold_text(): string
{
    $h = payment_hours();
    return $h > 0
        ? "Please send your payment within {$h} hours of ordering. Your pickup date is held for you until then; unpaid orders may be cancelled after that."
        : 'Please send your payment right away — your order is confirmed once it arrives, and your pickup date is held for you in the meantime.';
}

/** True if a pending order has passed the payment deadline. */
function payment_overdue(array $order): bool
{
    $h = payment_hours();
    if ($h === 0 || $order['status'] !== 'pending') {
        return false;
    }
    $placed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $order['created_at'], new DateTimeZone(PB_TZ));
    return $placed && $placed->modify("+{$h} hours") < new DateTimeImmutable('now', new DateTimeZone(PB_TZ));
}
