<?php
declare(strict_types=1);

/*
 * Simple sliding-window rate limiting stored in the database.
 * Buckets hash the visitor IP, so raw IP addresses are never stored.
 */

function rate_bucket(string $action): string
{
    return $action . ':' . substr(hash('sha256', client_ip() . '|' . (string) config('setup_key')), 0, 32);
}

/** True if the action is still allowed (does not record a hit). */
function rate_allowed(string $action, int $max, int $windowSeconds): bool
{
    $since = time() - $windowSeconds;
    $hits = (int) db_value('SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND created_at > ?', [rate_bucket($action), $since]);
    return $hits < $max;
}

function rate_hit(string $action): void
{
    db_exec('INSERT INTO rate_hits (bucket, created_at) VALUES (?, ?)', [rate_bucket($action), time()]);
    // Occasional cleanup of old entries.
    if (random_int(1, 50) === 1) {
        db_exec('DELETE FROM rate_hits WHERE created_at < ?', [time() - 86400]);
    }
}

function rate_clear(string $action): void
{
    db_exec('DELETE FROM rate_hits WHERE bucket = ?', [rate_bucket($action)]);
}
