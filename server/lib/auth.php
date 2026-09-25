<?php
declare(strict_types=1);

/* Admin login, sessions and CSRF protection. */

const PB_SESSION_DAYS = 30;

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = PB_SESSION_DAYS * 86400;
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    session_save_path(data_dir('sessions'));
    session_name('pb_admin');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Admin pages must never be cached, framed or indexed.
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
}

function admin_exists(): bool
{
    return (int) db_value('SELECT COUNT(*) FROM admin_users') > 0;
}

function current_admin(): ?array
{
    admin_session_start();
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return null;
    }
    $user = db_one('SELECT id, username, session_version FROM admin_users WHERE id = ?', [(int) $id]);
    // Sessions from before a password change are no longer valid (sessions older than this check count as version 1).
    if (!$user || (int) ($_SESSION['session_version'] ?? 1) !== (int) $user['session_version']) {
        $_SESSION = [];
        return null;
    }
    unset($user['session_version']);
    return $user;
}

/** Call at the top of every admin page. */
function require_admin(): array
{
    if (!admin_exists()) {
        redirect('setup.php');
    }
    $user = current_admin();
    if (!$user) {
        $back = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php' . ($back !== '' ? '?next=' . rawurlencode($back) : ''));
    }
    return $user;
}

/**
 * Where to go after logging in: a page in this site's /admin/ folder, else index.php.
 * Every path segment must be non-empty, so "//other-site/admin/x.php" (a link to another
 * website) is refused, as are backslashes and full URLs.
 */
function safe_admin_next(string $next): string
{
    return preg_match('#^/(?:[A-Za-z0-9_.-]+/)*admin/[A-Za-z0-9_-]+\.php(?:\?[A-Za-z0-9=&_%-]*)?$#', $next) ? $next : 'index.php';
}

/** Returns an error message, or null on success. */
function admin_login(string $username, string $password): ?string
{
    if (!rate_allowed('login', 6, 900)) {
        return 'Too many attempts. Please wait 15 minutes and try again.';
    }
    $user = db_one('SELECT * FROM admin_users WHERE username = ?', [mb_strtolower(trim($username))]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        rate_hit('login');
        return 'That username or password is not correct.';
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE admin_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    rate_clear('login');
    admin_session_start();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return null;
}

function admin_logout(): void
{
    admin_session_start();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}

function admin_create(string $username, string $password): void
{
    db_exec('INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)', [
        mb_strtolower(trim($username)), password_hash($password, PASSWORD_DEFAULT), now_str(),
    ]);
}

/** Sets a new password and logs out every other device (this session stays logged in, with a new session id). */
function admin_set_password(int $id, string $password): void
{
    db_exec('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['admin_id'] ?? 0) === $id) {
        session_regenerate_id(true);
        $_SESSION['session_version'] = (int) db_value('SELECT session_version FROM admin_users WHERE id = ?', [$id]);
    }
}

function password_problem(string $password, string $confirm): ?string
{
    if (mb_strlen($password) < 8) {
        return 'Please use at least 8 characters.';
    }
    if ($password !== $confirm) {
        return 'The two passwords do not match.';
    }
    return null;
}

function csrf_token(): string
{
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    admin_session_start();
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('This form expired. Please go back, refresh the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    admin_session_start();
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flashes(): array
{
    admin_session_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
