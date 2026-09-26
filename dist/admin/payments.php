<?php
/* Payments: every order still waiting for its money. Screenshots from customers come first,
 * so Pasha can check them against Venmo / Cash App and confirm with one tap. */
require __DIR__ . '/_init.php';
$user = require_admin();
admin_list_actions('payments.php');

$rows = db_all("SELECT * FROM orders WHERE status = 'pending' ORDER BY CASE WHEN payment_proof <> '' THEN 0 ELSE 1 END, created_at, id");
$withProof = array_values(array_filter($rows, fn($o) => $o['payment_proof'] !== ''));
$overdue = array_values(array_filter($rows, fn($o) => $o['payment_proof'] === '' && payment_overdue($o)));
$waiting = array_values(array_filter($rows, fn($o) => $o['payment_proof'] === '' && !payment_overdue($o)));
$sum = fn(array $list) => money(array_sum(array_map(fn($o) => (int) $o['total_cents'], $list)));

admin_header('Payments', 'payments', $user);
?>
<section class="card">
  <h1>Payments</h1>
  <p class="muted section-intro">Orders waiting for payment. Check your Venmo and Cash App for the order number in the note,
    then tap <strong>Confirm payment</strong>. The customer gets their confirmation email with the pickup address.</p>
  <?php if (!$rows): ?>
    <p class="empty">All caught up. No orders are waiting for payment. 🎉</p>
  <?php endif; ?>
</section>

<?php if ($withProof): ?>
<section class="card section-proof">
  <h2>Screenshot uploaded · check and confirm <span class="count-badge"><?= count($withProof) ?></span></h2>
  <p class="muted">The customer says they’ve paid <?= e($sum($withProof)) ?> in total. Tap a screenshot to see it full size.</p>
  <?php admin_order_rows($withProof, ['confirm' => true, 'proofs' => true]); ?>
</section>
<?php endif; ?>

<?php if ($waiting): ?>
<section class="card">
  <h2>Waiting for payment <span class="count-badge"><?= count($waiting) ?></span></h2>
  <p class="muted"><?= e($sum($waiting)) ?> in total. Each order holds its pickup day until its “pay by” time.</p>
  <?php admin_order_rows($waiting, ['confirm' => true]); ?>
</section>
<?php endif; ?>

<?php if ($overdue): ?>
<section class="card">
  <h2>Overdue <span class="count-badge is-red"><?= count($overdue) ?></span></h2>
  <p class="muted">The payment time has passed, so these no longer hold their pickup day. If the money arrives, you can still confirm;
    otherwise open the order and cancel it.</p>
  <?php admin_order_rows($overdue, ['confirm' => true]); ?>
</section>
<?php endif; ?>
<?php admin_footer();
