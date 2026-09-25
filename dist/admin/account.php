<?php
/* Change the admin password. */
require __DIR__ . '/_init.php';
$user = require_admin();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = db_one('SELECT password_hash FROM admin_users WHERE id = ?', [$user['id']]);
    if (!$row || !password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif ($problem = password_problem((string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? ''))) {
        $error = $problem;
    } else {
        admin_set_password((int) $user['id'], (string) $_POST['password']);
        flash('Password changed. Any other phone or computer that was logged in has been logged out.');
        redirect('account.php');
    }
}

admin_header('Account', 'account', $user);
?>
<section class="card narrow">
  <h1>Account</h1>
  <p class="muted">Logged in as <strong><?= e($user['username']) ?></strong>.</p>
  <h2>Change password</h2>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
    <label>New password <small>(at least 8 characters)</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>Type the new password again<input type="password" name="password_confirm" required minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-primary" type="submit">Change password</button>
  </form>
</section>
<?php admin_footer();
