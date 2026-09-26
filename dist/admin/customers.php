<?php
/* Customers: everyone who has ordered, with their order count and total. */
require __DIR__ . '/_init.php';
$user = require_admin();

$search = clean_text($_GET['q'] ?? '', 80);
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = customer_list($search, $page);
$qs = fn(array $extra = []) => 'customers.php?' . http_build_query(array_filter(['q' => $search] + $extra, fn($v) => $v !== '' && $v !== null));

admin_header('Customers', 'customers', $user);
?>
<section class="card">
  <div class="list-head">
    <div>
      <h1>Customers</h1>
      <p class="muted section-intro">Everyone who has ordered, newest first. Each customer has a reference (like C-3F9A21) that also shows on their orders.</p>
    </div>
    <form class="search" method="get" role="search">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone or reference" aria-label="Search customers">
      <button class="btn" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="customers.php">Clear</a><?php endif; ?>
    </form>
  </div>
  <p class="list-links"><a href="data-request.php">Customer data requests (see or delete someone’s details)</a></p>

  <?php if (!$list['rows']): ?>
    <p class="empty"><?= $search !== '' ? 'No customers match “' . e($search) . '”.' : 'No customers yet.' ?></p>
  <?php else: ?>
    <ul class="customer-list">
      <?php foreach ($list['rows'] as $c): ?>
        <li>
          <a class="customer-row" href="orders.php?status=all&amp;q=<?= e(rawurlencode($c['email'])) ?>">
            <div class="order-row-top">
              <strong><?= e($c['name']) ?></strong>
              <?php if ($c['ref'] !== ''): ?><span class="ref"><?= e($c['ref']) ?></span><?php endif; ?>
              <?php if ((int) $c['unpaid'] > 0): ?><span class="pill pill-pending"><?= (int) $c['unpaid'] ?> unpaid</span><?php endif; ?>
              <span class="total"><?= money((int) $c['spent']) ?></span>
            </div>
            <p class="muted"><?= e($c['email']) ?> · <?= e($c['phone']) ?></p>
            <p class="muted"><?= (int) $c['orders'] ?> order<?= (int) $c['orders'] === 1 ? '' : 's' ?> · last ordered <?= e(pretty_datetime($c['last_order'])) ?></p>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($list['pages'] > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($list['page'] > 1): ?><a class="btn btn-ghost" href="<?= e($qs(['page' => $list['page'] - 1])) ?>">← Previous</a><?php endif; ?>
        <span>Page <?= $list['page'] ?> of <?= $list['pages'] ?> · <?= $list['total'] ?> customers</span>
        <?php if ($list['page'] < $list['pages']): ?><a class="btn btn-ghost" href="<?= e($qs(['page' => $list['page'] + 1])) ?>">Next →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer();
