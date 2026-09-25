<?php
/* Business settings: ordering on/off, prices, pickup rules, payment handles, notifications. */
require __DIR__ . '/_init.php';
$user = require_admin();

$errors = [];
$form = settings_all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'restart_numbering') {
        restart_order_numbers()
            ? flash('Order numbers restarted. The next order will be PB1001.')
            : flash('Order numbers can only restart when there are no orders at all. Cancel and delete every order first.', 'warning');
        redirect('settings.php');
    }

    if (($_POST['action'] ?? '') === 'test_email') {
        $to = setting('notify_email');
        [$ok, $err] = send_test_email($to);
        if ($ok && $err === '') {
            flash("Test email sent to {$to}" . (setting('gmail_app_password') !== '' ? ' through Gmail' : '') . '. Check your inbox.');
        } elseif ($ok) {
            flash("Test email sent to {$to}, but NOT through Gmail (it may go to spam). Gmail said: {$err} — check the Gmail address and app password below.", 'warning');
        } else {
            flash('The test email could not be sent: ' . $err, 'error');
        }
        redirect('settings.php');
    }

    $in = $_POST;
    $prices = [];
    foreach (PB_BOX_SIZES as $size) {
        $raw = trim((string) ($in['price_' . $size] ?? ''));
        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
            $errors['price_' . $size] = 'Enter a price like 14 or 14.50';
            continue;
        }
        $prices[(string) $size] = (int) round((float) $raw * 100);
    }

    $slots = text_lines((string) ($in['pickup_slots'] ?? ''));
    if (!$slots) {
        $errors['pickup_slots'] = 'Add at least one pickup time.';
    }
    $occasions = text_lines((string) ($in['occasions'] ?? ''));

    $dates = [];
    foreach (text_lines((string) ($in['unavailable_dates'] ?? '')) as $line) {
        if (!parse_date($line)) {
            $errors['unavailable_dates'] = "“{$line}” is not a date. Use the format 2026-10-31 (one per line).";
            break;
        }
        $dates[] = $line;
    }

    $lead = (int) ($in['lead_days'] ?? 7);
    if ($lead < 0 || $lead > 60) {
        $errors['lead_days'] = 'Use a number between 0 and 60.';
    }
    $dayMax = trim((string) ($in['max_cookies_per_day'] ?? '0'));
    if (!preg_match('/^\d{1,4}$/', $dayMax === '' ? '0' : $dayMax)) {
        $errors['max_cookies_per_day'] = 'Use a whole number, or 0 for no limit.';
    }
    $payHours = trim((string) ($in['payment_hours'] ?? '0'));
    if (!preg_match('/^\d{1,3}$/', $payHours === '' ? '0' : $payHours) || (int) $payHours > 168) {
        $errors['payment_hours'] = 'Use a number of hours between 0 and 168 (one week), or 0 for no deadline.';
    }
    $maxAhead = (int) ($in['max_days_ahead'] ?? 90);
    if ($maxAhead < 14 || $maxAhead > 365) {
        $errors['max_days_ahead'] = 'Use a number between 14 and 365.';
    }

    $notify = clean_text($in['notify_email'] ?? '', 200);
    if (!filter_var($notify, FILTER_VALIDATE_EMAIL)) {
        $errors['notify_email'] = 'Enter a valid email address.';
    }
    $gmail = mb_strtolower(clean_text($in['gmail_address'] ?? '', 200));
    if ($gmail !== '' && !preg_match('/@(gmail|googlemail)\.com$/', $gmail)) {
        $errors['gmail_address'] = 'Enter a Gmail address (…@gmail.com).';
    }
    $appPass = str_replace(' ', '', (string) ($in['gmail_app_password'] ?? ''));
    if ($appPass !== '' && !preg_match('/^[a-zA-Z]{16}$/', $appPass)) {
        $errors['gmail_app_password'] = 'An app password is 16 letters, like “abcd efgh ijkl mnop”.';
    }
    $venmo = ltrim(clean_text($in['venmo_handle'] ?? '', 60), '@');
    $cashapp = ltrim(clean_text($in['cashapp_handle'] ?? '', 60), '$');
    if (!preg_match('/^[A-Za-z0-9_-]{2,60}$/', $venmo)) {
        $errors['venmo_handle'] = 'Enter the Venmo username without @.';
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $cashapp)) {
        $errors['cashapp_handle'] = 'Enter the Cash App $cashtag without $.';
    }

    $new = [
        'accepting_orders' => !empty($in['accepting_orders']) ? '1' : '0',
        'closed_message' => clean_text($in['closed_message'] ?? '', 400),
        'prices' => json_encode($prices),
        'lead_days' => (string) $lead,
        'max_days_ahead' => (string) $maxAhead,
        'max_cookies_per_day' => (string) (int) $dayMax,
        'payment_hours' => (string) (int) $payHours,
        'pickup_slots' => implode("\n", $slots),
        'unavailable_dates' => implode("\n", $dates),
        'occasions' => implode("\n", $occasions),
        'pickup_area' => clean_text($in['pickup_area'] ?? '', 120),
        'pickup_address' => clean_text($in['pickup_address'] ?? '', 500),
        'notify_email' => $notify,
        'venmo_handle' => $venmo,
        'cashapp_handle' => $cashapp,
        'gmail_address' => $gmail,
    ];
    if ($appPass !== '') {
        $new['gmail_app_password'] = $appPass;          // only replaced when a new one is typed
    } elseif (!empty($in['gmail_remove'])) {
        $new['gmail_app_password'] = '';
    }

    if (!$errors) {
        settings_save($new);
        flash('Settings saved.');
        redirect('settings.php');
    }
    $form = array_merge($form, $new, ['unavailable_dates' => (string) ($in['unavailable_dates'] ?? '')]);
}

$priceValues = [];
$savedPrices = json_decode((string) ($form['prices'] ?? '{}'), true) ?: [];
foreach (PB_BOX_SIZES as $size) {
    $priceValues[$size] = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (string) ($_POST['price_' . $size] ?? '')
        : (isset($savedPrices[(string) $size]) ? rtrim(rtrim(number_format($savedPrices[(string) $size] / 100, 2, '.', ''), '0'), '.') : '');
}
$sizeNames = [4 => '4 cookies', 6 => 'Half dozen', 12 => 'Dozen', 24 => '2 dozen', 36 => '3 dozen'];
$err = fn(string $k) => isset($errors[$k]) ? '<span class="error">' . e($errors[$k]) . '</span>' : '';

admin_header('Settings', 'settings', $user);
?>
<?php if ($errors): ?><div class="flash flash-error" role="alert">Please fix the highlighted fields below.</div><?php endif; ?>

<form method="post" class="form settings-form">
  <?= csrf_field() ?>

  <section class="card">
    <h2>Online ordering</h2>
    <label class="switch"><input type="checkbox" name="accepting_orders" value="1"<?= ($form['accepting_orders'] ?? '1') === '1' ? ' checked' : '' ?>> <span>Accept new orders on the website</span></label>
    <label>Message shown when ordering is paused
      <textarea name="closed_message" rows="2"><?= e($form['closed_message'] ?? '') ?></textarea>
    </label>
  </section>

  <section class="card">
    <h2>Box prices</h2>
    <div class="price-grid">
      <?php foreach (PB_BOX_SIZES as $size): ?>
        <label><?= e($sizeNames[$size]) ?>
          <span class="money-input"><span>$</span><input name="price_<?= $size ?>" value="<?= e($priceValues[$size]) ?>" inputmode="decimal" required></span>
          <?= $err('price_' . $size) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="muted">Remember to update prices written in the website text too (home page, menu, FAQs) if you change them.</p>
  </section>

  <section class="card">
    <h2>Pickup</h2>
    <div class="two-col">
      <label>Days of notice needed
        <input type="number" name="lead_days" value="<?= e($form['lead_days'] ?? '7') ?>" min="0" max="60" inputmode="numeric"><?= $err('lead_days') ?>
      </label>
      <label>Accept orders up to (days ahead)
        <input type="number" name="max_days_ahead" value="<?= e($form['max_days_ahead'] ?? '90') ?>" min="14" max="365" inputmode="numeric"><?= $err('max_days_ahead') ?>
      </label>
    </div>
    <label>Most cookies you can bake for one pickup day <small>(0 = no limit)</small>
      <input type="number" name="max_cookies_per_day" value="<?= e($form['max_cookies_per_day'] ?? '0') ?>" min="0" max="9999" step="1" inputmode="numeric"><?= $err('max_cookies_per_day') ?>
    </label>
    <p class="muted">When a day’s orders reach this number, customers can’t choose that day any more (or only a smaller box).
      Unpaid orders hold their place until the payment time below runs out; cancelled orders free it up at once.</p>
    <label>Pickup times <small>(one per line, shown in the order form)</small>
      <textarea name="pickup_slots" rows="6"><?= e($form['pickup_slots'] ?? '') ?></textarea><?= $err('pickup_slots') ?>
    </label>
    <label>Days you’re not available <small>(one date per line, like 2026-10-31)</small>
      <textarea name="unavailable_dates" rows="4" placeholder="2026-10-31"><?= e($form['unavailable_dates'] ?? '') ?></textarea><?= $err('unavailable_dates') ?>
    </label>
    <label>Pickup area shown on the website
      <input name="pickup_area" value="<?= e($form['pickup_area'] ?? '') ?>">
    </label>
    <label>Exact pickup address <small>(private — only sent in the confirmation email after payment)</small>
      <textarea name="pickup_address" rows="3" placeholder="House number, street, Tyngsboro, MA + any pickup instructions"><?= e($form['pickup_address'] ?? '') ?></textarea>
    </label>
    <?php if (trim((string) ($form['pickup_address'] ?? '')) === ''): ?>
      <p class="flash flash-warning">Add your pickup address so it’s included in confirmation emails.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Occasions</h2>
    <label>Occasion choices in the order form <small>(one per line)</small>
      <textarea name="occasions" rows="6"><?= e($form['occasions'] ?? '') ?></textarea>
    </label>
  </section>

  <section class="card">
    <h2>Payments</h2>
    <label>Ask customers to pay within (hours) <small>(0 = no deadline: unpaid orders hold their day until you cancel them)</small>
      <input type="number" name="payment_hours" value="<?= e($form['payment_hours'] ?? '24') ?>" min="0" max="168" step="1" inputmode="numeric"><?= $err('payment_hours') ?>
    </label>
    <p class="muted">Shown on the payment screen and in the “how to pay” email. Orders still unpaid after this are marked
      <strong>Overdue</strong> in your order list and <strong>no longer hold their pickup day</strong>, so other customers can book it.
      You can still mark an overdue order as paid if the money arrives. If you change this, update the FAQ text (“held for 24 hours”) too.</p>
    <div class="two-col">
      <label>Venmo username
        <span class="money-input"><span>@</span><input name="venmo_handle" value="<?= e($form['venmo_handle'] ?? '') ?>" autocapitalize="none"></span><?= $err('venmo_handle') ?>
      </label>
      <label>Cash App $cashtag
        <span class="money-input"><span>$</span><input name="cashapp_handle" value="<?= e($form['cashapp_handle'] ?? '') ?>" autocapitalize="none"></span><?= $err('cashapp_handle') ?>
      </label>
    </div>
  </section>

  <section class="card">
    <h2>Email sending</h2>
    <p class="muted">For emails to reach customers’ inboxes (not spam), they’re sent through Pasha’s own Gmail. This needs a Gmail <strong>app password</strong> — see the steps below.</p>
    <?php $hasApp = setting('gmail_app_password') !== ''; ?>
    <p class="flash <?= $hasApp ? 'flash-success' : 'flash-warning' ?>"><?= $hasApp ? '✓ Gmail app password saved — emails are sent through Gmail.' : 'Not connected yet — emails are sent by the server and may go to spam.' ?></p>
    <label>Gmail address
      <input type="email" name="gmail_address" value="<?= e($form['gmail_address'] ?? setting('gmail_address')) ?>" autocapitalize="none"><?= $err('gmail_address') ?>
    </label>
    <label>Gmail app password <small>(leave empty to keep the saved one)</small>
      <input type="password" name="gmail_app_password" value="" autocomplete="new-password" placeholder="<?= $hasApp ? '•••• •••• •••• ••••  (saved)' : 'abcd efgh ijkl mnop' ?>"><?= $err('gmail_app_password') ?>
    </label>
    <?php if ($hasApp): ?><label class="choice"><input type="checkbox" name="gmail_remove" value="1"> Remove the saved app password</label><?php endif; ?>
    <details>
      <summary>How to get a Gmail app password (2 minutes)</summary>
      <ol>
        <li>Sign in to the Pashabakess Gmail and open <a href="https://myaccount.google.com/security" target="_blank" rel="noopener">myaccount.google.com/security</a>.</li>
        <li>Turn on <strong>2-Step Verification</strong> if it isn’t on already.</li>
        <li>Open <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>, type the name <em>Pashabakess website</em> and click <strong>Create</strong>.</li>
        <li>Copy the 16-letter password Google shows, paste it above, click <strong>Save settings</strong>, then use <strong>Send test email</strong>.</li>
      </ol>
    </details>
  </section>

  <section class="card">
    <h2>Notifications</h2>
    <label>Send new-order alerts to
      <input type="email" name="notify_email" value="<?= e($form['notify_email'] ?? '') ?>" autocapitalize="none"><?= $err('notify_email') ?>
    </label>
  </section>

  <div class="sticky-save"><button class="btn btn-primary btn-lg" type="submit">Save settings</button></div>
</form>

<section class="card">
  <h2>Order numbers</h2>
  <?php $orderCount = (int) db_value('SELECT COUNT(*) FROM orders'); ?>
  <p class="muted">The next order will be <strong><?= e(next_order_code()) ?></strong>.
    <?= $orderCount > 0 ? "To start again from PB1001, first cancel and delete all {$orderCount} order" . ($orderCount === 1 ? '' : 's') . ' (open each one → Cancel order → Delete permanently).' : '' ?></p>
  <?php if ($orderCount === 0): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restart_numbering">
      <button class="btn" type="submit" data-confirm="Restart order numbers so the next order is PB1001?">Restart order numbers at PB1001</button></form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Test your email</h2>
  <p class="muted">Sends a test message to <?= e(setting('notify_email')) ?> so you know order emails are working.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_email"><button class="btn" type="submit">Send test email</button></form>
</section>
<?php admin_footer();
