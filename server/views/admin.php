<?php
declare(strict_types=1);

/* Shared admin page shell. */

function admin_header(string $title, string $active = '', ?array $user = null): void
{
    // Daily work first (Today, Payments, Orders, Past orders), then customers and the shop set-up.
    $nav = [
        'dashboard' => ['index.php', 'Today'],
        'payments' => ['payments.php', 'Payments'],
        'orders' => ['orders.php', 'Orders'],
        'past' => ['past.php', 'Past orders'],
        'customers' => ['customers.php', 'Customers'],
        'enquiries' => ['enquiries.php', 'Inquiries'],
        'menu' => ['menu.php', 'Menu'],
        'settings' => ['settings.php', 'Settings'],
        'account' => ['account.php', 'Account'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#442c25">
  <title><?= e($title) ?> · Pashabakess Admin</title>
  <link rel="icon" type="image/png" href="../favicon-64.png">
  <link rel="stylesheet" href="admin.css?v=7">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><img src="../logo-192.jpg" alt="" width="36" height="36"> Pashabakess <span>Admin</span></a>
    <?php if ($user): ?>
      <a class="view-site" href="../index.html" target="_blank" rel="noopener">View website ↗</a>
    <?php endif; ?>
  </div>
  <?php if ($user): ?>
  <nav class="tabs" aria-label="Admin">
    <?php $newEnquiries = enquiry_new_count(); $unpaid = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'pending'"); ?>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($key === 'enquiries' && $newEnquiries > 0): ?> <span class="count"><?= $newEnquiries ?><span class="sr-only"> new</span></span><?php endif; ?><?php if ($key === 'payments' && $unpaid > 0): ?> <span class="count"><?= $unpaid ?><span class="sr-only"> waiting</span></span><?php endif; ?></a>
    <?php endforeach; ?>
    <form method="post" action="logout.php" class="logout"><?= csrf_field() ?><button type="submit">Log out</button></form>
  </nav>
  <?php endif; ?>
</header>
<main class="page">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach;
}

function admin_footer(): void
{
    ?>
</main>
<script src="admin.js?v=2" defer></script>
</body>
</html>
<?php
}

function status_pill(string $status): string
{
    return '<span class="pill pill-' . e($status) . '">' . e(status_label($status)) . '</span>';
}

/**
 * Buttons on list rows ("Confirm payment", "Picked up"): handles the POST and goes back to $back.
 * Call before any output.
 */
function admin_list_actions(string $back): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    switch ((string) ($_POST['action'] ?? '')) {
        case 'confirm_paid':
            foreach (order_confirm_payment($id) as [$message, $type]) {
                flash($message, $type);
            }
            break;
        case 'mark_completed':
            $order = order_find($id);
            if ($order && $order['status'] === 'paid') {
                order_set_status($id, 'completed');
                flash("{$order['code']} marked as picked up. 🎉");
            }
            break;
    }
    redirect($back);
}

/**
 * One order list, used by every section. Options: select (tick boxes for deleting finished orders),
 * confirm (Confirm payment on unpaid orders), pickedUp ("Picked up" on paid orders), proofs (screenshot thumbnails).
 */
function admin_order_rows(array $rows, array $opts = []): void
{
    $todayYmd = today()->format('Y-m-d');
    ?>
    <ul class="order-list">
      <?php foreach ($rows as $o): ?>
        <?php $proof = ($o['payment_proof'] ?? '') !== ''; $deadline = $o['status'] === 'pending' ? payment_deadline($o) : null; ?>
        <li class="order-item">
          <?php if (!empty($opts['select']) && in_array($o['status'], PB_DELETABLE_STATUSES, true)): ?>
            <input class="row-check" type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" form="bulk" aria-label="Select <?= e($o['code']) ?>">
          <?php endif; ?>
          <?php if (!empty($opts['proofs']) && $proof): ?>
            <a class="proof-thumb" href="proof.php?id=<?= (int) $o['id'] ?>" target="_blank" rel="noopener" title="Open the payment screenshot">
              <img src="proof.php?id=<?= (int) $o['id'] ?>" alt="Payment screenshot for <?= e($o['code']) ?>" loading="lazy" width="72" height="96">
            </a>
          <?php endif; ?>
          <a class="order-row" href="order.php?id=<?= (int) $o['id'] ?>">
            <div class="order-row-top">
              <strong class="code"><?= e($o['code']) ?></strong>
              <?= status_pill($o['status']) ?><?php if (payment_overdue($o)): ?> <span class="pill pill-cancelled">Overdue</span><?php endif; ?>
              <?php if ($proof && $o['status'] === 'pending'): ?> <span class="pill pill-proof">📎 Screenshot</span><?php endif; ?>
              <span class="total"><?= money((int) $o['total_cents']) ?></span>
            </div>
            <p class="who"><?= e($o['customer_name']) ?><?php if (($o['customer_ref'] ?? '') !== ''): ?> <span class="ref"><?= e($o['customer_ref']) ?></span><?php endif; ?></p>
            <p class="pickup<?= $o['pickup_date'] === $todayYmd ? ' is-today' : '' ?>">Pickup <?= e(short_date($o['pickup_date'])) ?> · <?= e($o['pickup_slot']) ?></p>
            <p class="items"><?= e($o['items_text'] ?? order_items_text((int) $o['id'])) ?></p>
            <p class="pay"><?= e(payment_label($o['payment_method'])) ?><?php if ($o['payer_ref'] !== ''): ?> from <strong><?= e($o['payer_ref']) ?></strong><?php endif; ?>
              · placed <?= e(pretty_datetime($o['created_at'])) ?><?php if ($deadline && !payment_overdue($o)): ?> · pay by <?= e($deadline->format('D g:i A')) ?><?php endif; ?></p>
          </a>
          <?php if (!empty($opts['confirm']) && $o['status'] === 'pending'): ?>
            <form method="post" class="row-action">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="confirm_paid"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <button class="btn btn-primary btn-small" type="submit"
                data-confirm="Mark <?= e($o['code']) ?> as paid (<?= e(money((int) $o['total_cents'])) ?>) and email the confirmation to <?= e($o['customer_name']) ?>?">✓ Confirm payment</button>
            </form>
          <?php elseif (!empty($opts['pickedUp']) && $o['status'] === 'paid' && $o['pickup_date'] <= $todayYmd): ?>
            <form method="post" class="row-action">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_completed"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
              <button class="btn btn-small" type="submit">✓ Picked up</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php
}

/** Search box + status tabs for a list section. */
function admin_list_nav(string $page, array $tabs, string $status, string $search, array $counts): void
{
    ?>
    <form class="search" method="get" role="search">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone, order #, payer" aria-label="Search orders">
      <button class="btn" type="submit">Search</button>
      <?php if ($search !== ''): ?><a class="btn btn-ghost" href="<?= e($page) ?>?status=<?= e($status) ?>">Clear</a><?php endif; ?>
    </form>
    <nav class="status-tabs" aria-label="Order status">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e($page) ?>?status=<?= e($key) ?><?= $search !== '' ? '&q=' . rawurlencode($search) : '' ?>"<?= $key === $status ? ' aria-current="page"' : '' ?>>
          <?= e($label) ?> <span class="count"><?= (int) ($counts[$key] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

function admin_pager(array $list, callable $qs): void
{
    if ($list['pages'] <= 1) {
        return;
    }
    ?>
    <nav class="pager" aria-label="Pages">
      <?php if ($list['page'] > 1): ?><a class="btn btn-ghost" href="<?= e($qs(['page' => $list['page'] - 1])) ?>">← Previous</a><?php endif; ?>
      <span>Page <?= $list['page'] ?> of <?= $list['pages'] ?> · <?= $list['total'] ?> orders</span>
      <?php if ($list['page'] < $list['pages']): ?><a class="btn btn-ghost" href="<?= e($qs(['page' => $list['page'] + 1])) ?>">Next →</a><?php endif; ?>
    </nav>
    <?php
}
