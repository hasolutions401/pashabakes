<?php
/* Customer data requests: find everything stored for an email address, and delete the personal details on request. */
require __DIR__ . '/_init.php';
$user = require_admin();

$email = mb_strtolower(clean_text($_POST['email'] ?? $_GET['email'] ?? '', 200));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forget') {
    csrf_check();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Enter the customer’s email address.', 'error');
        redirect('data-request.php');
    }
    $done = forget_customer($email);
    $parts = [];
    foreach (['orders' => ['order', 'orders'], 'archived' => ['archived order', 'archived orders'], 'enquiries' => ['inquiry', 'inquiries']] as $key => [$one, $many]) {
        if ($done[$key] > 0) {
            $parts[] = $done[$key] . ' ' . ($done[$key] === 1 ? $one : $many);
        }
    }
    $summary = $parts ? 'Details removed from ' . implode(', ', $parts) . '.' : 'Nothing was stored for that address.';
    if ($done['skipped'] > 0) {
        $summary .= " {$done['skipped']} order" . ($done['skipped'] === 1 ? ' is' : 's are') . ' still waiting for payment or pickup and were not changed — finish or cancel them, then do this again.';
    }
    flash($summary, $done['skipped'] > 0 ? 'warning' : 'success');
    redirect('data-request.php?email=' . rawurlencode($email));
}

$records = filter_var($email, FILTER_VALIDATE_EMAIL) ? customer_records($email) : null;
admin_header('Customer data requests', 'customers', $user);
?>
<a class="back" href="settings.php">← Settings</a>
<section class="card narrow-wide">
  <h1>Customer data requests</h1>
  <p class="muted">If a customer asks what you have stored about them, or asks you to delete it (as the privacy notice promises),
    look them up by the email address they used.</p>
  <form method="get" class="search" role="search">
    <input type="email" name="email" value="<?= e($email) ?>" placeholder="customer@example.com" aria-label="Customer email address" required autocapitalize="none">
    <button class="btn" type="submit">Look up</button>
  </form>

  <?php if ($records !== null): ?>
    <?php $total = count($records['orders']) + count($records['archived']) + count($records['enquiries']); ?>
    <?php if ($total === 0): ?>
      <p class="empty">Nothing is stored for <?= e($email) ?>.</p>
    <?php else: ?>
      <h2>Stored for <?= e($email) ?></h2>
      <ul class="plain-list">
        <?php foreach ($records['orders'] as $o): ?>
          <li><a href="order.php?id=<?= (int) $o['id'] ?>"><?= e($o['code']) ?></a> <?= status_pill($o['status']) ?> · pickup <?= e(short_date($o['pickup_date'])) ?> · <?= money((int) $o['total_cents']) ?></li>
        <?php endforeach; ?>
        <?php foreach ($records['archived'] as $o): ?>
          <li><?= e($o['code']) ?> <span class="pill">Archived</span> · pickup <?= e(short_date($o['pickup_date'])) ?> · <?= money((int) $o['total_cents']) ?></li>
        <?php endforeach; ?>
        <?php foreach ($records['enquiries'] as $q): ?>
          <li>Inquiry: <?= e($q['type']) ?> · <?= e(pretty_datetime($q['created_at'])) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="muted">Deleting removes the name, email, phone, notes and payer name. Picked-up and cancelled orders keep only the
        cookies, amounts and dates for your records; inquiries and email history are deleted. Orders still waiting for payment or
        pickup are not changed. This cannot be undone.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="forget">
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <button class="btn btn-danger" type="submit" data-confirm="Delete the personal details stored for <?= e($email) ?>? This cannot be undone.">Delete this customer’s details</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer();
