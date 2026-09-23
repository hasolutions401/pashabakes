<?php
/* One-time admin account creation. Needs the setup_key from server/config.php. */
require __DIR__ . '/_init.php';

if (admin_exists()) {
    redirect('login.php');
}

$checks = [
    ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1.0', '>='), 'This server runs PHP ' . PHP_VERSION . '.'],
    ['Database connected', true, db_driver() === 'mysql' ? 'MySQL / MariaDB' : 'SQLite file in server/data/'],
    ['server/data is writable', is_writable(PB_ROOT . '/data'), 'Needed for sessions and logs.'],
    ['Website address matches', parse_url((string) config('site_url'), PHP_URL_HOST) === explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0], 'site_url in config.php is ' . config('site_url') . ' — email links use it.'],
    ['Setup key changed', config('setup_key') !== 'change-this-to-a-long-random-phrase' && mb_strlen((string) config('setup_key')) >= 12, 'Use a long random phrase in config.php.'],
    ['Email configured', config('mail.transport') !== 'smtp' || (string) config('mail.smtp_pass') !== '', 'Transport: ' . config('mail.transport')],
];

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean_text($_POST['username'] ?? '', 64);
    $password = (string) ($_POST['password'] ?? '');
    if (!rate_allowed('setup', 8, 900)) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } elseif (!hash_equals((string) config('setup_key'), (string) ($_POST['setup_key'] ?? ''))) {
        rate_hit('setup');
        $error = 'The setup key is not correct. It is the setup_key value in server/config.php.';
    } elseif (!preg_match('/^[a-z0-9._-]{3,64}$/i', $username)) {
        $error = 'Username: 3 or more letters or numbers (no spaces).';
    } elseif ($problem = password_problem($password, (string) ($_POST['password_confirm'] ?? ''))) {
        $error = $problem;
    } else {
        admin_create($username, $password);
        admin_login($username, $password);
        flash('Your admin account is ready. Welcome!');
        redirect('index.php');
    }
}

admin_header('Set up admin');
?>
<section class="card narrow">
  <h1>Set up your admin login</h1>
  <p class="muted">This page only works once. After your account is created it disappears.</p>

  <ul class="checks">
    <?php foreach ($checks as [$label, $ok, $hint]): ?>
      <li class="<?= $ok ? 'ok' : 'warn' ?>"><strong><?= $ok ? '✓' : '!' ?> <?= e($label) ?></strong><span><?= e($hint) ?></span></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="form" autocomplete="off">
    <label>Setup key<input type="password" name="setup_key" required></label>
    <label>Choose a username<input name="username" value="<?= e($username) ?>" required autocapitalize="none" autocomplete="username"></label>
    <label>Choose a password <small>(at least 8 characters)</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Type the password again<input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-primary btn-block" type="submit">Create admin account</button>
  </form>
</section>
<?php admin_footer();
