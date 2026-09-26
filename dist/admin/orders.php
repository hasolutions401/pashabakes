<?php
/* Active orders: waiting for payment, and paid orders still to bake and hand over. */
require __DIR__ . '/_init.php';
$user = require_admin();

$tabs = ['paid' => 'Paid · to bake', 'pending' => 'Payment pending', 'all' => 'All orders'];
$status = (string) ($_GET['status'] ?? 'paid');
if (!isset($tabs[$status])) {
    $status = 'paid';
}
$search = clean_text($_GET['q'] ?? '', 80);
$page = max(1, (int) ($_GET['page'] ?? 1));
$qs = fn(array $extra = []) => 'orders.php?' . http_build_query(array_filter(['status' => $status, 'q' => $search] + $extra, fn($v) => $v !== '' && $v !== null));
admin_list_actions($qs(['page' => $page > 1 ? $page : null]));

$counts = order_counts();
$list = order_list($status, $search, $page);

admin_header('Orders', 'orders', $user);
?>
<section class="card">
  <div class="list-head">
    <div>
      <h1>Orders</h1>
      <p class="muted section-intro">Orders to bake and hand over. Unpaid orders are also under <a href="payments.php">Payments</a>;
        picked-up and cancelled ones move to <a href="past.php">Past orders</a>.</p>
    </div>
  </div>
  <?php admin_list_nav('orders.php', $tabs, $status, $search, $counts); ?>
  <p class="list-links"><a href="export.php?what=orders">Download orders (CSV)</a></p>

  <?php if (!$list['rows']): ?>
    <p class="empty"><?= $search !== '' ? 'No orders match “' . e($search) . '”.' : ($status === 'paid' ? 'Nothing to bake right now.' : 'No orders here.') ?></p>
  <?php else: ?>
    <?php admin_order_rows($list['rows'], ['confirm' => true, 'pickedUp' => true, 'select' => false]); ?>
    <?php admin_pager($list, $qs); ?>
  <?php endif; ?>
</section>
<?php admin_footer();
