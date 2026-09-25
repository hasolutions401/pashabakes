<?php
declare(strict_types=1);

/*
 * Database access (PDO). Works with SQLite (default, zero setup) or MySQL/MariaDB.
 * Tables are created and seeded automatically on first use.
 */

// 8 was the ingredients list, since removed (live databases may keep an unused cookies.ingredients column).
// 9: unpaid orders hold their pickup day for 24 hours. 10: archived_orders (kept when numbers restart).
// 11: admin_users.session_version (changing the password logs out other devices).
// 12: orders.ad_consent / fbp / fbc (Meta ads measurement, only with the customer's consent).
const PB_SCHEMA_VERSION = 12;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = (string) (config('db.driver') ?: 'sqlite');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        if ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', config('db.host'), config('db.name'));
            $pdo = new PDO($dsn, (string) config('db.user'), (string) config('db.password'), $options);
            $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES'");
        } else {
            if (!extension_loaded('pdo_sqlite')) {
                fatal_setup_error('The PHP extension pdo_sqlite is not enabled. Enable it, or switch config.php to MySQL.');
            }
            $file = (string) (config('db.sqlite') ?: PB_ROOT . '/data/pashabakess.sqlite');
            data_dir();
            $pdo = new PDO('sqlite:' . $file, null, null, $options);
            // Rollback journal (not WAL) is the safest choice on shared hosting file systems.
            $pdo->exec('PRAGMA busy_timeout = 8000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = DELETE');
            $pdo->exec('PRAGMA synchronous = FULL');
        }
    } catch (PDOException $e) {
        error_log('[pashabakess] DB connection failed: ' . $e->getMessage());
        fatal_setup_error('Could not connect to the database. Check the "db" section of server/config.php.');
    }

    migrate($pdo, $driver);
    return $pdo;
}

function db_driver(): string
{
    return config('db.driver') === 'mysql' ? 'mysql' : 'sqlite';
}

/** Runs a callback inside a transaction (retrying briefly if the database is busy). */
function db_transaction(callable $fn)
{
    $pdo = db();
    $attempts = 0;
    while (true) {
        try {
            if (db_driver() === 'sqlite') {
                $pdo->exec('BEGIN IMMEDIATE');
            } else {
                $pdo->beginTransaction();
            }
            $result = $fn($pdo);
            if (db_driver() === 'sqlite') {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            try {
                if (db_driver() === 'sqlite') {
                    $pdo->exec('ROLLBACK');
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // nothing to roll back
            }
            $busy = $e instanceof PDOException && preg_match('/database is locked|deadlock|lock wait/i', $e->getMessage());
            if ($busy && ++$attempts < 4) {
                usleep(150000 * $attempts);
                continue;
            }
            throw $e;
        }
    }
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_value(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function schema_version(PDO $pdo): int
{
    try {
        return (int) $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn();
    } catch (PDOException) {
        return 0;   // settings table does not exist yet
    }
}

function migrate(PDO $pdo, string $driver): void
{
    // Fast path: already migrated.
    if (schema_version($pdo) >= PB_SCHEMA_VERSION) {
        return;
    }
    // One request migrates at a time; the others wait, then see the new version.
    $lock = @fopen(data_dir() . '/migrate.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        $version = schema_version($pdo);
        if ($version >= PB_SCHEMA_VERSION) {
            return;
        }
        if ($version >= 1 && $driver === 'sqlite') {
            backup_sqlite($pdo, "v{$version}-before-v" . PB_SCHEMA_VERSION);
        }
        migrate_steps($pdo, $driver, $version);
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Consistent copy of the SQLite database in server/data/backups/ (never web-accessible),
 * taken before the schema changes. Keeps the newest $keep copies. Returns the file, or null.
 */
function backup_sqlite(PDO $pdo, string $label, int $keep = 5): ?string
{
    $dir = data_dir('backups');
    $file = $dir . '/pashabakess-' . preg_replace('/[^a-z0-9-]/i', '', $label) . '-' . date('Ymd-His') . '.sqlite';
    try {
        $pdo->exec('VACUUM INTO ' . $pdo->quote($file));
    } catch (PDOException $e) {
        // Older SQLite without VACUUM INTO: plain file copy (the migration lock is held).
        $main = null;
        foreach ($pdo->query('PRAGMA database_list')->fetchAll() as $db) {
            if ($db['name'] === 'main') {
                $main = $db['file'];
            }
        }
        if (!$main || !@copy($main, $file)) {
            error_log('[pashabakess] Backup before database upgrade failed: ' . $e->getMessage());
            return null;
        }
    }
    $copies = glob($dir . '/pashabakess-*.sqlite') ?: [];
    usort($copies, fn($a, $b) => [filemtime($a), $a] <=> [filemtime($b), $b]);
    foreach (array_slice($copies, 0, max(0, count($copies) - $keep)) as $old) {
        @unlink($old);
    }
    return $file;
}

function migrate_steps(PDO $pdo, string $driver, int $version): void
{
    $mysql = $driver === 'mysql';
    $id = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $mysql ? 'TEXT' : 'TEXT';
    $str = fn(int $n) => $mysql ? "VARCHAR($n)" : 'TEXT';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $statements = [
        "CREATE TABLE IF NOT EXISTS settings (
            name {$str(64)} PRIMARY KEY,
            value {$text} NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS admin_users (
            id {$id},
            username {$str(64)} NOT NULL UNIQUE,
            password_hash {$str(255)} NOT NULL,
            created_at {$str(19)} NOT NULL,
            session_version INTEGER NOT NULL DEFAULT 1
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS cookies (
            id {$id},
            name {$str(120)} NOT NULL,
            description {$text} NOT NULL,
            type {$str(20)} NOT NULL,
            image {$str(500)} NOT NULL,
            is_available INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            available_from {$str(10)} NULL,
            available_until {$str(10)} NULL,
            created_at {$str(19)} NOT NULL,
            updated_at {$str(19)} NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS orders (
            id {$id},
            code {$str(20)} UNIQUE,
            client_token {$str(64)} NOT NULL UNIQUE,
            status {$str(20)} NOT NULL DEFAULT 'pending',
            customer_name {$str(120)} NOT NULL,
            email {$str(200)} NOT NULL,
            phone {$str(40)} NOT NULL,
            occasion {$str(60)} NOT NULL,
            notes {$text} NOT NULL,
            box_size INTEGER NOT NULL,
            total_cents INTEGER NOT NULL,
            pickup_date {$str(10)} NOT NULL,
            pickup_slot {$str(60)} NOT NULL,
            payment_method {$str(20)} NOT NULL,
            payer_ref {$str(120)} NOT NULL,
            admin_note {$text} NOT NULL,
            created_at {$str(19)} NOT NULL,
            paid_at {$str(19)} NULL,
            completed_at {$str(19)} NULL,
            cancelled_at {$str(19)} NULL,
            ad_consent INTEGER NOT NULL DEFAULT 0,
            fbp {$str(255)} NOT NULL DEFAULT '',
            fbc {$str(255)} NOT NULL DEFAULT ''
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS order_items (
            id {$id},
            order_id INTEGER NOT NULL,
            cookie_id INTEGER NULL,
            cookie_name {$str(120)} NOT NULL,
            quantity INTEGER NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS email_log (
            id {$id},
            order_id INTEGER NULL,
            kind {$str(40)} NOT NULL,
            recipient {$str(200)} NOT NULL,
            status {$str(20)} NOT NULL,
            error {$text} NOT NULL,
            created_at {$str(19)} NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS rate_hits (
            id {$id},
            bucket {$str(120)} NOT NULL,
            created_at INTEGER NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS enquiries (
            id {$id},
            status {$str(20)} NOT NULL DEFAULT 'new',
            name {$str(120)} NOT NULL,
            email {$str(200)} NOT NULL,
            type {$str(60)} NOT NULL,
            event_date {$str(10)} NULL,
            quantity {$str(80)} NOT NULL,
            message {$text} NOT NULL,
            order_ref {$str(20)} NOT NULL DEFAULT '',
            created_at {$str(19)} NOT NULL
        ){$engine}",
        // Version 10: finished orders move here when Pasha restarts the order numbers.
        "CREATE TABLE IF NOT EXISTS archived_orders (
            id {$id},
            code {$str(20)} NOT NULL,
            status {$str(20)} NOT NULL,
            customer_name {$str(120)} NOT NULL,
            email {$str(200)} NOT NULL,
            phone {$str(40)} NOT NULL,
            occasion {$str(60)} NOT NULL,
            notes {$text} NOT NULL,
            box_size INTEGER NOT NULL,
            total_cents INTEGER NOT NULL,
            pickup_date {$str(10)} NOT NULL,
            pickup_slot {$str(60)} NOT NULL,
            payment_method {$str(20)} NOT NULL,
            payer_ref {$str(120)} NOT NULL,
            admin_note {$text} NOT NULL,
            items_text {$text} NOT NULL,
            created_at {$str(19)} NOT NULL,
            paid_at {$str(19)} NULL,
            completed_at {$str(19)} NULL,
            cancelled_at {$str(19)} NULL,
            archived_at {$str(19)} NOT NULL
        ){$engine}",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    // Version 2: flavors can be limited to a range of pickup dates (e.g. a monthly special).
    foreach (['available_from', 'available_until'] as $col) {
        try {
            $pdo->exec("ALTER TABLE cookies ADD COLUMN {$col} {$str(10)} NULL");
        } catch (PDOException) {
            // column already exists (fresh install)
        }
    }
    // Version 3: enquiries can mention an existing order number.
    try {
        $pdo->exec("ALTER TABLE enquiries ADD COLUMN order_ref {$str(20)} NOT NULL DEFAULT ''");
    } catch (PDOException) {
        // column already exists
    }
    // Version 12: ads-measurement consent and Meta browser ids per order (only filled when the customer allowed it).
    foreach (['ad_consent INTEGER NOT NULL DEFAULT 0', "fbp {$str(255)} NOT NULL DEFAULT ''", "fbc {$str(255)} NOT NULL DEFAULT ''"] as $col) {
        try {
            $pdo->exec("ALTER TABLE orders ADD COLUMN {$col}");
        } catch (PDOException) {
            // column already exists
        }
    }
    // Version 11: a password change logs out the admin's other devices.
    try {
        $pdo->exec('ALTER TABLE admin_users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');
    } catch (PDOException) {
        // column already exists
    }
    if ($version === 1) {
        // Existing monthly specials become available for the month customers can next order for.
        [$from, $until] = seasonal_window_default($pdo);
        $pdo->prepare("UPDATE cookies SET available_from = ?, available_until = ? WHERE type = 'seasonal' AND available_from IS NULL AND available_until IS NULL")
            ->execute([$from, $until]);
    }

    $indexes = [
        ['idx_orders_status', 'orders', 'status'],
        ['idx_orders_pickup', 'orders', 'pickup_date'],
        ['idx_orders_created', 'orders', 'created_at'],
        ['idx_items_order', 'order_items', 'order_id'],
        ['idx_email_order', 'email_log', 'order_id'],
        ['idx_rate_bucket', 'rate_hits', 'bucket, created_at'],
        ['idx_enquiries_created', 'enquiries', 'created_at'],
        ['idx_archived_code', 'archived_orders', 'code'],
    ];
    foreach ($indexes as [$name, $table, $cols]) {
        try {
            $pdo->exec($mysql ? "CREATE INDEX {$name} ON {$table} ({$cols})" : "CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
        } catch (PDOException) {
            // MySQL: index already exists
        }
    }

    seed_defaults($pdo);

    // Version 3: Pasha's own photos replace the sample photos (only where a flavor still has
    // the original sample), and M&M is added as a hidden flavor, ready for a future rotation.
    // Version 4: the last two borrowed photos become a "photo coming soon" placeholder.
    if ($version < 4) {
        $swap = $pdo->prepare('UPDATE cookies SET image = ? WHERE image = ?');
        foreach (own_photo_swaps() as $old => $new) {
            $swap->execute([$new, $old]);
        }
    }
    // Version 6: the two flavors still on "photo coming soon" get drawn illustrations.
    if ($version >= 1 && $version < 6) {
        $draw = $pdo->prepare("UPDATE cookies SET image = ? WHERE name = ? AND image = 'images/coming-soon.jpg'");
        $draw->execute(['images/pumpkin-chocolate-chip-illustration.jpg', 'Pumpkin Chocolate Chip']);
        $draw->execute(['images/maple-pecan-illustration.jpg', 'Maple Pecan']);
    }
    // Version 7: back to the earlier sample photos for these two until Pasha sends her own
    // (shown on the menu as illustrative, with credits).
    if ($version >= 1 && $version < 7) {
        $back = $pdo->prepare("UPDATE cookies SET image = ? WHERE name = ? AND image IN ('images/coming-soon.jpg', ?)");
        $back->execute(['https://sallysbakingaddiction.com/wp-content/uploads/2013/09/chewy-pumpkin-chocolate-chip-cookies-3.jpg', 'Pumpkin Chocolate Chip', 'images/pumpkin-chocolate-chip-illustration.jpg']);
        $back->execute(['https://confessionsofabakingqueen.com/wp-content/uploads/2020/11/plate-of-maple-pecan-cookies-1-of-1-1024x1536-1.jpg', 'Maple Pecan', 'images/maple-pecan-illustration.jpg']);
    }
    // Version 5: Pasha's daily limit is 5 dozen (60 cookies), unless a limit was already set in Settings.
    if ($version >= 1 && $version < 5) {
        $pdo->prepare("UPDATE settings SET value = '60' WHERE name = 'max_cookies_per_day' AND value IN ('', '0')")->execute();
    }
    // Version 9: unpaid orders hold their pickup day for 24 hours (agreed Sept 2026), unless a deadline was already set.
    if ($version >= 1 && $version < 9) {
        $pdo->prepare("UPDATE settings SET value = '24' WHERE name = 'payment_hours' AND value IN ('', '0')")->execute();
    }
    if ($version < 3) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM cookies WHERE name LIKE 'M&M%' OR name LIKE 'M & M%'")->fetchColumn() === 0) {
            $now = now_str();
            $pdo->prepare('INSERT INTO cookies (name, description, type, image, is_available, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)')
                ->execute(['M&M', 'Soft, golden cookie loaded with colorful M&M’s.', 'seasonal', 'images/mm.jpg',
                    ((int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM cookies')->fetchColumn()) + 10, $now, $now]);
        }
    }
    $pdo->prepare('REPLACE INTO settings (name, value) VALUES (?, ?)')->execute(['schema_version', (string) PB_SCHEMA_VERSION]);
}

function seed_defaults(PDO $pdo): void
{
    $ignore = db_driver() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $insert = $pdo->prepare("{$ignore} INTO settings (name, value) VALUES (?, ?)");
    foreach (default_settings() as $name => $value) {
        $insert->execute([$name, $value]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM cookies')->fetchColumn() === 0) {
        $now = now_str();
        [$from, $until] = seasonal_window_default($pdo);
        $add = $pdo->prepare('INSERT INTO cookies (name, description, type, image, is_available, sort_order, available_from, available_until, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)');
        foreach (default_cookies() as $i => $c) {
            $seasonal = $c[2] === 'seasonal';
            $add->execute([$c[0], $c[1], $c[2], $c[3], ($i + 1) * 10, $seasonal ? $from : null, $seasonal ? $until : null, $now, $now]);
        }
    }
}

/**
 * First and last day of the month of the earliest possible pickup date — the month a
 * "monthly special" is for. Reads the settings table directly because it runs during migration.
 */
function seasonal_window_default(PDO $pdo): array
{
    $lead = 7;
    try {
        $value = $pdo->query("SELECT value FROM settings WHERE name = 'lead_days'")->fetchColumn();
        if ($value !== false) {
            $lead = max(0, min(60, (int) $value));
        }
    } catch (PDOException) {
        // settings not seeded yet: use the default
    }
    $earliest = today()->modify("+{$lead} days");
    return [$earliest->format('Y-m-01'), $earliest->format('Y-m-t')];
}

/** Original sample photo URL => Pasha's own photo. */
function own_photo_swaps(): array
{
    return [
        'https://images.unsplash.com/photo-1673551490160-3f2e712b9373?auto=format&fit=crop&w=900&q=80' => 'images/chocolate-chunk.jpg',
        'https://assets-eu-01.kc-usercontent.com/21d2ecef-fb9b-01b1-9022-cf60b52c2c77/d79e9c4f-6fbe-4c62-8ca7-fe295e3169b3/Biscoff-Cookies-WEB-RES-1.jpg?auto=format&lossless=1&q=85&w=900' => 'images/biscoff.jpg',
        'https://scientificallysweet.com/wp-content/uploads/2022/09/IMG_3198-salted-toffee-chocolate-chip-cookies-feature2.jpg' => 'images/chocolate-sea-salt-toffee.jpg',
        'https://sallysbakingaddiction.com/wp-content/uploads/2013/12/red-velvet-white-chocolate-chip-cookies-2.jpg' => 'images/red-velvet.jpg',
    ];
}

function default_cookies(): array
{
    // name, description, type, image
    return [
        ['Chocolate Chunk', 'Brown butter base with semi-sweet chocolate chips, dark chocolate chunks, topped with sea salt flakes.', 'signature', 'images/chocolate-chunk.jpg'],
        ['Biscoff', 'Brown butter base with Biscoff cookie pieces, white chocolate chips, drizzled with Biscoff spread.', 'signature', 'images/biscoff.jpg'],
        ['Chocolate Sea Salt Toffee', 'Rich brown butter cookie with toffee bits, semi-sweet chocolate chips, topped with sea salt flakes.', 'signature', 'images/chocolate-sea-salt-toffee.jpg'],
        ['Red Velvet', 'Red cookie base with cocoa powder, white chocolate chips and white chocolate drizzle.', 'signature', 'images/red-velvet.jpg'],
        ['Pumpkin Chocolate Chip', 'Brown butter base with pumpkin purée, cinnamon, and chocolate chips.', 'seasonal', 'https://sallysbakingaddiction.com/wp-content/uploads/2013/09/chewy-pumpkin-chocolate-chip-cookies-3.jpg'],
        ['Maple Pecan', 'Brown butter base with cinnamon, maple syrup, pecans.', 'seasonal', 'https://confessionsofabakingqueen.com/wp-content/uploads/2020/11/plate-of-maple-pecan-cookies-1-of-1-1024x1536-1.jpg'],
    ];
}
