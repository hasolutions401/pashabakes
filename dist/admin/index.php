<?php
/* Today: what needs doing now — payments to check, today's and tomorrow's pickups, the baking list. */
require __DIR__ . '/_init.php';
$user = require_admin();

// Old links (index.php?status=…) go to the section that now holds that list.
if (isset($_GET['status'])) {
    $status = (string) $_GET['status'];
    $q = isset($_GET['q']) ? '&q=' . rawurlencode((string) $_GET['q']) : '';
    redirect(in_array($status, ['completed', 'cancelled'], true) ? "past.php?status={$status}{$q}" : 'orders.php?status=' . rawurlencode($status) . $q);
}
admin_list_actions('index.php');
// Backup for the hourly reminder task.
maybe_send_pickup_reminders();

$todayYmd = today()->format('Y-m-d');
$tomorrowYmd = today()->modify('+1 day')->format('Y-m-d');
$counts = order_counts();
$pending = db_all("SELECT * FROM orders WHERE status = 'pending'");
$withProof = count(array_filter($pending, fn($o) => $o['payment_proof'] !== ''));
$overdue = count(array_filter($pending, fn($o) => payment_overdue($o)));
$todayOrders = db_all("SELECT * FROM orders WHERE status IN ('paid', 'completed') AND pickup_date = ? ORDER BY status DESC, pickup_slot, id", [$todayYmd]);
$todayLeft = count(array_filter($todayOrders, fn($o) => $o['status'] === 'paid'));
$tomorrowOrders = db_all("SELECT * FROM orders WHERE status = 'paid' AND pickup_date = ? ORDER BY pickup_slot, id", [$tomorrowYmd]);
$reminded = count(array_filter($tomorrowOrders, fn($o) => $o['reminder_sent_at'] !== null));
$plan = baking_plan(14);
$dayMax = max_cookies_per_day();

admin_header('Today', 'dashboard', $user);
?>
<?php if (!accepting_orders()): ?>
  <div class="flash flash-warning">Online ordering is currently <strong>paused</strong>. Turn it back on in <a href="settings.php">Settings</a>.</div>
<?php endif; ?>

<?php $mailProblems = recent_email_problems(7); $kindLabel = ['admin_alert' => 'new-order alert to you', 'receipt' => 'receipt to customer', 'confirmation' => 'confirmation to customer', 'enquiry' => 'inquiry alert to you', 'reminder' => 'pickup reminder to customer', 'admin_reminder' => 'tomorrow’s pickups list to you', 'proof_alert' => 'payment screenshot alert to you']; ?>
<?php if ($mailProblems['failed']): ?>
  <div class="flash flash-error" role="alert">
    <strong><?= count($mailProblems['failed']) ?> email<?= count($mailProblems['failed']) === 1 ? '' : 's' ?> could not be sent in the last 7 days.</strong>
    Open each one to resend it, and check <a href="settings.php">Settings → Email sending</a>.
    <ul class="mail-problems">
      <?php foreach (array_slice($mailProblems['failed'], 0, 8) as $p): ?>
        <li><?php if ($p['order_id']): ?><a href="order.php?id=<?= (int) $p['order_id'] ?>"><?= e($p['code']) ?></a><?php elseif ($p['kind'] === 'enquiry'): ?><a href="enquiries.php">Inquiry</a><?php else: ?>Email<?php endif; ?>
          · <?= e($kindLabel[$p['kind']] ?? $p['kind']) ?> · <?= e(pretty_datetime($p['created_at'])) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($mailProblems['no_gmail']): ?>
  <div class="flash flash-warning"><?= count($mailProblems['no_gmail']) ?> email<?= count($mailProblems['no_gmail']) === 1 ? '' : 's' ?> in the last 7 days went out without Gmail, so they may be in customers’ spam folders.
    Check <a href="settings.php">Settings → Email sending</a>.</div>
<?php endif; ?>

<div class="today-head">
  <h1>Today · <?= e(today()->format('l, F j')) ?></h1>
  <p class="muted">What needs your attention, in order.</p>
</div>

<section class="stats" aria-label="What needs doing">
  <a class="stat<?= $withProof ? ' stat-alert' : '' ?>" href="payments.php"><strong><?= $withProof ?></strong><span>Screenshots to check</span></a>
  <a class="stat stat-pending" href="payments.php"><strong><?= $counts['pending'] ?></strong><span>Waiting for payment<?= $overdue ? " · {$overdue} overdue" : '' ?></span></a>
  <a class="stat" href="#today"><strong><?= $todayLeft ?></strong><span>Pickups left today</span></a>
  <a class="stat" href="#tomorrow"><strong><?= count($tomorrowOrders) ?></strong><span>Pickups tomorrow</span></a>
  <a class="stat" href="orders.php?status=paid"><strong><?= $counts['paid'] ?></strong><span>Paid · to bake</span></a>
</section>

<section class="card" id="today">
  <h2>Today’s pickups</h2>
  <?php if (!$todayOrders): ?>
    <p class="muted">No pickups today.</p>
  <?php else: ?>
    <p class="muted">Tap <strong>Picked up</strong> when a customer collects their box.</p>
    <?php admin_order_rows($todayOrders, ['pickedUp' => true]); ?>
  <?php endif; ?>
</section>

<section class="card" id="tomorrow">
  <h2>Tomorrow’s pickups</h2>
  <?php if (!$tomorrowOrders): ?>
    <p class="muted">No paid orders for tomorrow yet.</p>
  <?php else: ?>
    <p class="muted"><?= $reminded === count($tomorrowOrders)
        ? 'Every customer got their reminder email, and you got the list by email.'
        : 'Reminder emails go to these customers (and the list to you) from ' . PB_REMINDER_HOUR . ' AM today.' ?></p>
    <?php admin_order_rows($tomorrowOrders); ?>
  <?php endif; ?>
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
<?php admin_footer();
