<?php
/* Menu: list of cookie flavors with quick show/hide. */
require __DIR__ . '/_init.php';
$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $cookie = cookie_find($id);
    if ($cookie) {
        if (($_POST['action'] ?? '') === 'toggle') {
            db_exec('UPDATE cookies SET is_available = ?, updated_at = ? WHERE id = ?', [(int) $cookie['is_available'] ? 0 : 1, now_str(), $id]);
            flash((int) $cookie['is_available'] ? "“{$cookie['name']}” is now hidden from the website." : "“{$cookie['name']}” is now on the website.");
        } elseif (($_POST['action'] ?? '') === 'delete') {
            cookie_delete($id);
            flash("“{$cookie['name']}” was deleted.");
        }
    }
    redirect('menu.php');
}

$cookies = menu_cookies(true);
admin_header('Menu', 'menu', $user);
?>
<section class="card">
  <div class="list-head">
    <div>
      <h1>Menu</h1>
      <p class="muted">Changes show on the website straight away. Hidden flavors can’t be ordered.</p>
    </div>
    <a class="btn btn-primary" href="cookie.php">+ Add a flavor</a>
  </div>

  <ul class="cookie-list">
    <?php foreach ($cookies as $c): ?>
      <li class="<?= $c['is_available'] ? '' : 'is-hidden' ?>">
        <?php if ($c['image'] !== ''): ?>
          <img src="<?= e(str_starts_with($c['image'], 'uploads/') ? '../' . $c['image'] : $c['image']) ?>" alt="" width="72" height="72" loading="lazy">
        <?php else: ?>
          <span class="no-photo">No photo</span>
        <?php endif; ?>
        <div class="cookie-info">
          <strong><?= e($c['name']) ?></strong>
          <span class="muted"><?= $c['type'] === 'seasonal' ? 'Monthly special' : 'Signature' ?> · <?= $c['is_available'] ? 'On the website' : 'Hidden' ?></span>
        </div>
        <div class="cookie-actions">
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="action" value="toggle">
            <button class="btn btn-small" type="submit"><?= $c['is_available'] ? 'Hide' : 'Show' ?></button>
          </form>
          <a class="btn btn-small" href="cookie.php?id=<?= $c['id'] ?>">Edit</a>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><input type="hidden" name="action" value="delete">
            <button class="btn btn-small btn-danger" type="submit" data-confirm="Delete “<?= e($c['name']) ?>”? Past orders keep their details.">Delete</button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!$cookies): ?><p class="empty">No flavors yet. Add your first one!</p><?php endif; ?>
</section>
<?php admin_footer();
