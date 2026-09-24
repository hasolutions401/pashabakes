<?php
/* Dashboard: stats, baking list and the order list. */
require __DIR__ . '/_init.php';
$user = require_admin();

$status = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, [...PB_STATUSES, 'all'], true)) {
    $status = 'pending';
}
$search = clean_text($_GET['q'] ?? '', 80);
$page = max(1, (int) ($_GET['page'] ?? 1));

$counts = order_counts();
$list = order_list($status, $search, $page);
$todayYmd = today()->format('Y-m-d');
$weekYmd = today()->modify('+6 days')->format('Y-m-d');
$todayPickups = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND pickup_date = ?", [$todayYmd]);
$weekPickups = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND pickup_date BETWEEN ? AND ?", [$todayYmd, $weekYmd]);
$plan = baking_plan(14);
$dayMax = max_cookies_per_day();

$tabs = [
    'pending' => 'Payment pending',
    'paid' => 'Paid · to bake',
    'completed' => 'Picked up',
    'cancelled' => 'Cancelled',
    'all' => 'All',
];

$qs = fn(array $extra) => '?' . http_build_query(array_filter(['status' => $status, 'q' => $search] + $extra, fn($v) => $v !== '' && $v !== null));

admin_header('Orders', 'orders', $user);
?>
<?php if (!accepting_orders()): ?>
  <div class="flash flash-warning">Online ordering is currently <strong>paused</strong>. Turn it back on in <a href="settings.php">Settings</a>.</div>
<?php endif; ?>

<section class="stats" aria-label="Summary">
  <a class="stat stat-pending" href="?status=pending"><strong><?= $counts['pending'] ?></strong><span>Payment pending</span></a>
  <a class="stat" href="?status=paid"><strong><?= $counts['paid'] ?></strong><span>Paid · to bake</span></a>
  <div class="stat"><strong><?= $todayPickups ?></strong><span>Pickups today</span></div>
  <div class="stat"><strong><?= $weekPickups ?></strong><span>Pickups next 7 days</span></div>
</section>

<details class="card plan"<?= $plan ? ' open' : '' ?>>
  <summary><h2>Baking list · next 14 days</h2><span class="muted">Paid orders only</span></summary>
  <?php if (!$plan): ?>
    <p class="muted">No paid orders to bake in the next 14 days.</p>
  <?php else: ?>
    <div class="plan-days">
      <?php foreach ($plan as $date => $day): ?>
        <div class="plan-day<?= $date === $todayYmd ? ' is-today' : '' ?>">
          <p class="plan-date"><?= e(short_date($date)) ?><?= $date === $todayYmd ? ' · Today' : '' ?></p>
          <p class="plan-meta"><?= $day['orders'] ?> order<?= $day['orders'] === 1 ? '' : 's' ?> · <?= $day['cookies'] ?> cookies<?php if ($dayMax > 0): ?> · <?= booked_cookies($date) ?> of <?= $dayMax ?> booked<?php endif; ?></p>
          <ul>
            <?php foreach ($day['flavors'] as $name => $qty): ?>
              <li><span><?= e($name) ?></span><strong><?= $qty ?></strong></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</details>

<section class="card">
  <div class="list-head">
    <h1>Orders</h1>
    <form class="search" method="get" role="search">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, order #, payer" aria-label="Search orders">
      <button class="btn" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="?status=<?= e($status) ?>">Clear</a><?php endif; ?>
    </form>
  </div>

  <nav class="status-tabs" aria-label="Order status">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="?status=<?= e($key) ?><?= $search !== '' ? '&q=' . rawurlencode($search) : '' ?>"<?= $key === $status ? ' aria-current="page"' : '' ?>>
        <?= e($label) ?> <span class="count"><?= $counts[$key] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$list['rows']): ?>
    <p class="empty"><?= $search !== '' ? 'No orders match “' . e($search) . '”.' : 'No orders here yet.' ?></p>
  <?php else: ?>
    <ul class="order-list">
      <?php foreach ($list['rows'] as $o): ?>
        <li>
          <a class="order-row" href="order.php?id=<?= (int) $o['id'] ?>">
            <div class="order-row-top">
              <strong class="code"><?= e($o['code']) ?></strong>
              <?= status_pill($o['status']) ?>
              <span class="total"><?= money((int) $o['total_cents']) ?></span>
            </div>
            <p class="who"><?= e($o['customer_name']) ?></p>
            <p class="pickup<?= $o['pickup_date'] === $todayYmd ? ' is-today' : '' ?>">Pickup <?= e(short_date($o['pickup_date'])) ?> · <?= e($o['pickup_slot']) ?></p>
            <p class="items"><?= e($o['items_text']) ?></p>
            <p class="pay"><?= e(payment_label($o['payment_method'])) ?><?php if ($o['payer_ref'] !== ''): ?> from <strong><?= e($o['payer_ref']) ?></strong><?php endif; ?> · placed <?= e(pretty_datetime($o['created_at'])) ?></p>
          </a>
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
