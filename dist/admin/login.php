<?php
require __DIR__ . '/_init.php';

if (!admin_exists()) {
    redirect('setup.php');
}
if (current_admin()) {
    redirect('index.php');
}

// Only allow returning to pages inside /admin/.
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^/[A-Za-z0-9/_.-]*admin/[A-Za-z0-9_.-]*\.php(\?[A-Za-z0-9=&_%-]*)?$#', $next)) {
    $next = 'index.php';
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $error = admin_login($username, (string) ($_POST['password'] ?? ''));
    if ($error === null) {
        redirect($next);
    }
}

admin_header('Log in');
?>
<section class="card narrow login-card">
  <img class="login-logo" src="../logo-192.jpg" alt="" width="84" height="84">
  <h1>Welcome back</h1>
  <p class="muted">Log in to manage your orders and menu.</p>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>Username<input name="username" value="<?= e($username) ?>" required autocapitalize="none" autocomplete="username" autofocus></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">Log in</button>
  </form>
</section>
<?php admin_footer();
