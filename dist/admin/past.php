<?php
/* Past orders: picked up and cancelled, with the archive, the spreadsheet download and deleting old orders. */
require __DIR__ . '/_init.php';
$user = require_admin();

$tabs = ['completed' => 'Picked up', 'cancelled' => 'Cancelled'];
$status = (string) ($_GET['status'] ?? 'completed');
if (!isset($tabs[$status])) {
    $status = 'completed';
}
$search = clean_text($_GET['q'] ?? '', 80);
$page = max(1, (int) ($_GET['page'] ?? 1));
$qs = fn(array $extra = []) => 'past.php?' . http_build_query(array_filter(['status' => $status, 'q' => $search] + $extra, fn($v) => $v !== '' && $v !== null));

$counts = order_counts();
$list = order_list($status, $search, $page);
$archivedCount = (int) db_value('SELECT COUNT(*) FROM archived_orders');

admin_header('Past orders', 'past', $user);
?>
<section class="card">
  <div class="list-head">
    <div>
      <h1>Past orders</h1>
      <p class="muted section-intro">Orders that were picked up or cancelled.</p>
    </div>
  </div>
  <?php admin_list_nav('past.php', $tabs, $status, $search, $counts); ?>
  <p class="list-links"><a href="export.php?what=orders">Download orders (CSV)</a><?php if ($archivedCount > 0): ?> · <a href="archive.php">Archived orders (<?= $archivedCount ?>)</a><?php endif; ?> · <a href="cleanup.php">Delete old orders by date</a></p>

  <?php if (!$list['rows']): ?>
    <p class="empty"><?= $search !== '' ? 'No orders match “' . e($search) . '”.' : 'No orders here yet.' ?></p>
  <?php else: ?>
    <form id="bulk" method="post" action="cleanup.php" class="bulk-bar">
      <?= csrf_field() ?>
      <label class="check"><input type="checkbox" id="select-all"> Select all on this page</label>
      <button class="btn btn-danger btn-small" type="submit">Delete selected…</button>
      <span class="muted">You’ll see the list and confirm before anything is deleted.</span>
    </form>
    <?php admin_order_rows($list['rows'], ['select' => true]); ?>
    <?php admin_pager($list, $qs); ?>
  <?php endif; ?>
</section>
<?php admin_footer();
