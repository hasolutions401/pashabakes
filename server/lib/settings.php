<?php
declare(strict_types=1);

/*
 * Business settings, editable by Pasha in Admin → Settings.
 * Stored as name/value text pairs in the `settings` table.
 */

const PB_BOX_SIZES = [4, 6, 12, 24, 36];

function default_settings(): array
{
    return [
        'prices'            => json_encode(['4' => 1400, '6' => 2000, '12' => 3800, '24' => 7600, '36' => 11400]),
        'lead_days'         => '7',
        'max_days_ahead'    => '90',
        'pickup_slots'      => implode("\n", [
            '10:00 AM – 11:00 AM', '11:00 AM – 12:00 PM', '12:00 PM – 1:00 PM', '1:00 PM – 2:00 PM',
            '2:00 PM – 3:00 PM', '3:00 PM – 4:00 PM', '4:00 PM – 5:00 PM', '5:00 PM – 6:00 PM', '6:00 PM – 7:00 PM',
        ]),
        'unavailable_dates' => '',
        'pickup_area'       => 'Tyngsboro, MA',
        'pickup_address'    => '',
        'notify_email'      => 'pashabakess@gmail.com',
        'venmo_handle'      => 'Palosha-Rashid',
        'cashapp_handle'    => 'Pashabakess',
        'accepting_orders'  => '1',
        'closed_message'    => 'Online ordering is paused for now. Please email pashabakess@gmail.com and Pasha will help you.',
        'occasions'         => implode("\n", ['Just because', 'Birthday', 'Holiday', 'Baby shower', 'Bridal shower', 'Eid', 'Aqiqah', 'Graduation', 'Office treat']),
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
