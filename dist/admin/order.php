<?php
/* One order: details and actions (mark paid, picked up, cancel, notes, resend emails). */
require __DIR__ . '/_init.php';
$user = require_admin();

$id = (int) ($_GET['id'] ?? 0);
$order = order_find($id);
if (!$order) {
    flash('That order could not be found.', 'error');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $self = 'order.php?id=' . $id;
    if ($order['email'] === '' && in_array($action, ['resend_confirmation', 'resend_receipt'], true)) {
        flash('This customer’s details were deleted on request, so there is no address to email.', 'warning');
        redirect($self);
    }

    switch ($action) {
        case 'mark_paid':
            // An overdue order stopped holding its day, so the day may have filled up meanwhile.
            $overBy = payment_overdue($order) && max_cookies_per_day() > 0
                ? booked_cookies($order['pickup_date']) + (int) $order['box_size'] - max_cookies_per_day() : 0;
            if (!order_mark_paid($id)) {
                flash('This order is no longer waiting for payment.', 'warning');
                break;
            }
            if ($overBy > 0) {
                flash('Heads up: ' . pretty_date($order['pickup_date']) . " is now {$overBy} cookies over your daily limit, because other orders took this unpaid order’s place.", 'warning');
            }
            [$ok] = send_customer_confirmation(order_find($id));
            // The one reliable "purchase": Pasha has seen the money arrive (off unless ads measurement is on).
            meta_send_order_event('Purchase', order_find($id), $order['code'] . '-paid');
            $ok
                ? flash("Marked as paid. Confirmation email sent to {$order['email']}.")
                : flash('Marked as paid, but the confirmation email could not be sent. Use “Resend confirmation” below.', 'warning');
            break;

        case 'mark_completed':
            order_set_status($id, 'completed');
            flash('Marked as picked up. 🎉');
            break;

        case 'mark_cancelled':
            order_set_status($id, 'cancelled');
            flash('Order cancelled. The customer was not emailed — contact them, and refund them if they already paid.', 'warning');
            break;

        case 'back_to_pending':
            order_set_status($id, 'pending');
            flash($order['status'] === 'cancelled' ? 'Order restored to “Payment pending”.' : 'Moved back to “Payment pending”.');
            // A restored order takes its place again, and the day may have been booked up meanwhile.
            $overBy = max_cookies_per_day() > 0 ? booked_cookies($order['pickup_date']) - max_cookies_per_day() : 0;
            if ($order['status'] === 'cancelled' && $overBy > 0) {
                flash('Heads up: ' . pretty_date($order['pickup_date']) . " is now {$overBy} cookies over your daily limit.", 'warning');
            }
            break;

        case 'back_to_paid':
            db_exec('UPDATE orders SET status = ?, completed_at = NULL, cancelled_at = NULL WHERE id = ?', ['paid', $id]);
            flash('Moved back to “Paid · to bake”.');
            break;

        case 'save_note':
            db_exec('UPDATE orders SET admin_note = ? WHERE id = ?', [clean_text($_POST['admin_note'] ?? '', 2000), $id]);
            flash('Note saved.');
            break;

        case 'resend_confirmation':
            if ($order['status'] === 'paid' || $order['status'] === 'completed') {
                [$ok, $err] = send_customer_confirmation($order);
                $ok ? flash('Confirmation email sent again.') : flash('Could not send the email: ' . $err, 'error');
            }
            break;

        case 'delete_order':
            if (order_delete($id)) {
                flash("Order {$order['code']} was deleted permanently.");
                redirect('index.php?status=cancelled');
            }
            flash('Only cancelled orders can be deleted. Cancel the order first.', 'warning');
            break;

        case 'resend_receipt':
            [$ok, $err] = send_customer_receipt($order);
            $ok ? flash('Receipt email sent again.') : flash('Could not send the email: ' . $err, 'error');
            break;

        default:
            flash('Unknown action.', 'error');
    }
    redirect($self);
}

$items = order_items($id);
$emails = email_details($id);
$emailLabels = [
    'admin_alert' => 'New-order alert to you',
    'receipt' => 'Receipt to customer',
    'confirmation' => 'Payment confirmation to customer',
];

$action = fn(string $name, string $label, string $class = 'btn', string $confirm = '') =>
    '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="' . e($name) . '">'
    . '<button type="submit" class="' . e($class) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . e($label) . '</button></form>';

admin_header('Order ' . $order['code'], 'orders', $user);
?>
<a class="back" href="index.php?status=<?= e($order['status']) ?>">← All orders</a>

<section class="card order-head">
  <div>
    <h1><?= e($order['code']) ?> <?= status_pill($order['status']) ?></h1>
    <p class="muted">Placed <?= e(pretty_datetime($order['created_at'])) ?>
      <?= $order['paid_at'] ? ' · Paid ' . e(pretty_datetime($order['paid_at'])) : '' ?>
      <?= $order['completed_at'] ? ' · Picked up ' . e(pretty_datetime($order['completed_at'])) : '' ?>
      <?= $order['cancelled_at'] ? ' · Cancelled ' . e(pretty_datetime($order['cancelled_at'])) : '' ?></p>
  </div>
  <p class="big-total"><?= money((int) $order['total_cents']) ?></p>
</section>

<section class="card actions-card">
  <?php if ($order['status'] === 'pending'): ?>
    <div class="verify">
      <?php if (payment_overdue($order)): ?><p class="verify-title">⚠ Payment overdue — placed more than <?= payment_hours() ?> hours ago. It no longer holds its pickup day, so other customers can book that day.
        You can still mark it as paid if the money arrives<?php if (max_cookies_per_day() > 0): ?> (<?= e(short_date($order['pickup_date'])) ?> has <?= max(0, max_cookies_per_day() - booked_cookies($order['pickup_date'])) ?> of <?= max_cookies_per_day() ?> cookies free)<?php endif; ?>, or cancel it.</p><?php endif; ?>
      <p class="verify-title">Check <?= e(payment_label($order['payment_method'])) ?></p>
      <p>Look for <strong><?= money((int) $order['total_cents']) ?></strong> with <strong><?= e($order['code']) ?></strong> in the payment note<?= $order['payer_ref'] !== '' ? ' (the customer said they’ll pay from <strong>' . e($order['payer_ref']) . '</strong>)' : '' ?>. When you see it, mark the order as paid — the customer gets their confirmation email with the pickup address automatically.</p>
      <p class="verify-hint">Can’t fill this order (for example, the date is fully booked)? Email the customer before cancelling. If they already paid, refund them in full.</p>
    </div>
    <div class="action-row">
      <?= $action('mark_paid', '✓ Mark as Paid', 'btn btn-primary btn-lg', 'Mark ' . $order['code'] . ' as paid? The customer will get the confirmation email now.') ?>
      <?= $action('mark_cancelled', 'Cancel order', 'btn btn-danger', 'Cancel order ' . $order['code'] . '?') ?>
    </div>
  <?php elseif ($order['status'] === 'paid'): ?>
    <?php if (trim(setting('pickup_address')) === ''): ?>
      <p class="flash flash-warning">Your pickup address is empty in <a href="settings.php">Settings</a>, so the confirmation email had no pickup details. Add it, then tap <strong>Resend confirmation</strong>.</p>
    <?php endif; ?>
    <p class="verify-title">Paid · ready to bake for <?= e(short_date($order['pickup_date'])) ?></p>
    <div class="action-row">
      <?= $action('mark_completed', '✓ Mark as Picked up', 'btn btn-primary btn-lg') ?>
      <?= $action('resend_confirmation', 'Resend confirmation') ?>
      <?= $action('back_to_pending', 'Move back to Payment pending', 'btn btn-ghost', 'Move this order back to “Payment pending”?') ?>
      <?= $action('mark_cancelled', 'Cancel order', 'btn btn-danger', 'Cancel order ' . $order['code'] . '?') ?>
    </div>
  <?php elseif ($order['status'] === 'completed'): ?>
    <p class="verify-title">Picked up — all done.</p>
    <div class="action-row"><?= $action('back_to_paid', 'Move back to Paid', 'btn btn-ghost') ?></div>
  <?php else: ?>
    <p class="verify-title">This order was cancelled.</p>
    <div class="action-row">
      <?= $action('back_to_pending', 'Restore order', 'btn', 'Restore this order to “Payment pending”?') ?>
      <?= $action('delete_order', 'Delete permanently', 'btn btn-danger', 'Delete order ' . $order['code'] . ' permanently? This cannot be undone.') ?>
    </div>
  <?php endif; ?>
</section>

<div class="detail-grid">
  <section class="card">
    <h2>Cookies</h2>
    <table class="items">
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['cookie_name']) ?></td><td class="num">× <?= (int) $it['quantity'] ?></td></tr>
      <?php endforeach; ?>
      <tr class="sum"><td><?= (int) $order['box_size'] ?> cookies</td><td class="num"><?= money((int) $order['total_cents']) ?></td></tr>
    </table>
  </section>

  <section class="card">
    <h2>Pickup</h2>
    <p class="lead"><?= e(pretty_date($order['pickup_date'])) ?></p>
    <p><?= e($order['pickup_slot']) ?> (Eastern)</p>
    <?php if ($order['occasion'] !== ''): ?><p class="muted">Occasion: <?= e($order['occasion']) ?></p><?php endif; ?>
  </section>

  <section class="card">
    <h2>Customer</h2>
    <p class="lead"><?= e($order['customer_name']) ?></p>
    <?php if ($order['email'] === ''): ?>
      <p class="muted">The customer’s details were deleted on request.</p>
    <?php else: ?>
      <p><a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a></p>
      <p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $order['phone'])) ?>"><?= e($order['phone']) ?></a></p>
    <?php endif; ?>
    <?php if ($order['notes'] !== ''): ?>
      <p class="note-box"><strong>Customer notes</strong><br><?= nl2br(e($order['notes'])) ?></p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Payment</h2>
    <p class="lead"><?= e(payment_label($order['payment_method'])) ?></p>
    <p>Payment note: <strong><?= e($order['code']) ?></strong></p>
    <?php if ($order['payer_ref'] !== ''): ?><p>Paying from: <strong><?= e($order['payer_ref']) ?></strong></p><?php endif; ?>
    <p>Amount due: <strong><?= money((int) $order['total_cents']) ?></strong></p>
  </section>

  <section class="card">
    <h2>Emails</h2>
    <ul class="email-status">
      <?php foreach ($emailLabels as $kind => $label): ?>
        <?php $mail = $emails[$kind] ?? null; $st = $mail['status'] ?? null; ?>
        <li>
          <span><?= e($label) ?><?php if ($mail): ?><small class="muted email-meta">to <?= e($mail['recipient']) ?> · <?= e(pretty_datetime($mail['created_at'])) ?></small><?php endif; ?></span>
          <?php if ($st === 'sent'): ?><span class="pill pill-completed">Sent</span>
          <?php elseif ($st === 'failed'): ?><span class="pill pill-cancelled">Failed</span>
          <?php else: ?><span class="pill">Not sent yet</span><?php endif; ?>
        </li>
        <?php if ($mail && $mail['error'] !== ''): ?><li class="email-error"><?= e($mail['error']) ?><?= $st === 'sent' ? ' — set up the Gmail app password in Settings → Email sending.' : '' ?></li><?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <?php if (in_array($order['status'], ['paid', 'completed'], true) && $order['email'] !== ''): ?>
      <p class="muted">Customer didn’t get it? Ask them to check spam, check the email address above, then resend.</p>
      <?= $action('resend_confirmation', 'Resend confirmation', 'btn btn-small') ?>
    <?php endif; ?>
    <?php if (($emails['receipt']['status'] ?? '') === 'failed'): ?>
      <?= $action('resend_receipt', 'Resend receipt', 'btn btn-small') ?>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Private note</h2>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_note">
      <label class="sr-only" for="admin_note">Private note</label>
      <textarea id="admin_note" name="admin_note" rows="3" placeholder="Only you can see this"><?= e($order['admin_note']) ?></textarea>
      <button class="btn btn-small" type="submit">Save note</button>
    </form>
  </section>
</div>
<?php admin_footer();
