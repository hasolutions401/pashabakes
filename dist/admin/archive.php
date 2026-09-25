<?php
/* Archived orders: finished orders kept from before the order numbers were restarted. */
require __DIR__ . '/_init.php';
$user = require_admin();

$search = clean_text($_GET['q'] ?? '', 80);
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = archived_order_list($search, $page);
$qs = fn(array $extra) => '?' . http_build_query(array_filter(['q' => $search] + $extra, fn($v) => $v !== '' && $v !== null));

admin_header('Archived orders', 'orders', $user);
?>
<a class="back" href="index.php">← Orders</a>
<section class="card">
  <div class="list-head">
    <div>
      <h1>Archived orders</h1>
      <p class="muted">Finished orders from before the order numbers were restarted. An order number can appear here and in your current orders.</p>
    </div>
    <form class="search" method="get" role="search">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, order #, payer" aria-label="Search archived orders">
      <button class="btn" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="archive.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <p><a class="btn btn-small" href="export.php?what=archive">Download archived orders (CSV)</a></p>

  <?php if (!$list['rows']): ?>
    <p class="empty"><?= $search !== '' ? 'No archived orders match “' . e($search) . '”.' : 'No archived orders yet.' ?></p>
  <?php else: ?>
    <ul class="order-list">
      <?php foreach ($list['rows'] as $o): ?>
        <li class="order-row archived-row">
          <div class="order-row-top">
            <strong class="code"><?= e($o['code']) ?></strong>
            <?= status_pill($o['status']) ?>
            <span class="total"><?= money((int) $o['total_cents']) ?></span>
          </div>
          <p class="who"><?= e($o['customer_name']) ?><?php if ($o['email'] !== ''): ?> · <a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a><?php endif; ?><?= $o['phone'] !== '' ? ' · ' . e($o['phone']) : '' ?></p>
          <p class="pickup">Pickup <?= e(short_date($o['pickup_date'])) ?> · <?= e($o['pickup_slot']) ?></p>
          <p class="items"><?= e($o['items_text']) ?></p>
          <p class="pay"><?= e(payment_label($o['payment_method'])) ?><?php if ($o['payer_ref'] !== ''): ?> from <strong><?= e($o['payer_ref']) ?></strong><?php endif; ?>
            · placed <?= e(pretty_datetime($o['created_at'])) ?> · archived <?= e(pretty_datetime($o['archived_at'])) ?></p>
          <?php if ($o['notes'] !== ''): ?><p class="muted">Notes: <?= e($o['notes']) ?></p><?php endif; ?>
          <?php if ($o['admin_note'] !== ''): ?><p class="muted">Private note: <?= e($o['admin_note']) ?></p><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($list['pages'] > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($list['page'] > 1): ?><a class="btn btn-ghost" href="<?= e($qs(['page' => $list['page'] - 1])) ?>">← Previous</a><?php endif; ?>
        <span>Page <?= $list['page'] ?> of <?= $list['pages'] ?> · <?= $list['total'] ?> orders</span>
        <?php if ($list['page'] < $list['pages']): ?><a class="btn btn-ghost" href="<?= e($qs(['page' => $list['page'] + 1])) ?>">Next →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer();
