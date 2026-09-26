<?php
/* Delete old orders in bulk: by pickup date range, or the orders ticked in the order list.
 * Only picked-up and cancelled orders are ever deleted; paid and payment-pending orders are left alone. */
require __DIR__ . '/_init.php';
$user = require_admin();

$from = clean_text($_REQUEST['from'] ?? '', 10);
$to = clean_text($_REQUEST['to'] ?? '', 10);
$ids = (array) ($_POST['ids'] ?? []);
$error = '';
$orders = null;   // null = nothing chosen yet

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        if (empty($_POST['understood'])) {
            flash('Please tick the box to confirm the orders should be deleted.', 'warning');
        } else {
            $codes = orders_delete($ids);
            $codes
                ? flash(count($codes) . ' order' . (count($codes) === 1 ? '' : 's') . ' deleted permanently: ' . implode(', ', $codes) . '.')
                : flash('Nothing was deleted. Only picked-up and cancelled orders can be deleted.', 'warning');
            redirect('index.php?status=completed');
        }
    }
    // Orders ticked in the list (or a failed confirmation): show them for review.
    $orders = finished_orders_by_id($ids);
    if (!$orders) {
        flash('None of the ticked orders can be deleted. Only picked-up and cancelled orders can be deleted.', 'warning');
        redirect('index.php?status=completed');
    }
} elseif ($from !== '' || $to !== '') {
    $f = parse_date($from);
    $t = parse_date($to);
    if (!$f || !$t) {
        $error = 'Choose both dates.';
    } elseif ($f > $t) {
        $error = 'The first date must be before the last date.';
    } else {
        $orders = finished_orders_between($from, $to);
    }
}

admin_header('Delete old orders', 'orders', $user);
?>
<p><a href="index.php?status=completed">← Orders</a></p>

<section class="card">
  <h1>Delete old orders</h1>
  <p class="muted">Deletes <strong>picked-up</strong> and <strong>cancelled</strong> orders for good, with their emails.
    Paid and payment-pending orders are never deleted. This can’t be undone, so
    <a href="export.php?what=orders">download the orders (CSV)</a> first if you want to keep a copy.</p>

  <form method="get" class="form range-form">
    <label>Pickup date from <input type="date" name="from" value="<?= e($from) ?>" required></label>
    <label>to <input type="date" name="to" value="<?= e($to) ?>" required></label>
    <button class="btn" type="submit">Find orders</button>
  </form>
  <?php if ($error !== ''): ?><p class="flash flash-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <p class="muted">Or tick orders in the <a href="index.php?status=completed">Picked up</a> or
    <a href="index.php?status=cancelled">Cancelled</a> list and choose “Delete selected”.</p>
</section>

<?php if ($orders !== null): ?>
<section class="card">
  <?php if (!$orders): ?>
    <p class="empty">No picked-up or cancelled orders with a pickup date in that range.</p>
  <?php else: ?>
    <?php $n = count($orders); $sum = array_sum(array_map(fn($o) => (int) $o['total_cents'], $orders)); ?>
    <h2><?= $n ?> order<?= $n === 1 ? '' : 's' ?> will be deleted</h2>
    <table class="items cleanup-table">
      <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Pickup</th><th scope="col">Status</th><th scope="col" class="num">Total</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr><td><a href="order.php?id=<?= (int) $o['id'] ?>"><?= e($o['code']) ?></a></td><td><?= e($o['customer_name']) ?></td>
            <td><?= e(short_date($o['pickup_date'])) ?></td><td><?= status_pill($o['status']) ?></td><td class="num"><?= money((int) $o['total_cents']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="sum"><td colspan="4"><?= $n ?> order<?= $n === 1 ? '' : 's' ?></td><td class="num"><?= money($sum) ?></td></tr></tfoot>
    </table>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
      <?php foreach ($orders as $o): ?><input type="hidden" name="ids[]" value="<?= (int) $o['id'] ?>"><?php endforeach; ?>
      <label class="check"><input type="checkbox" name="understood" value="1" required>
        I understand <?= $n === 1 ? 'this order' : "these {$n} orders" ?> will be deleted permanently.</label>
      <button class="btn btn-danger" type="submit" data-confirm="Delete <?= $n ?> order<?= $n === 1 ? '' : 's' ?> permanently? This cannot be undone.">Delete <?= $n ?> order<?= $n === 1 ? '' : 's' ?> permanently</button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_footer();
