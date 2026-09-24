<?php
/* Enquiries sent from the Contact and Celebrations pages. */
require __DIR__ . '/_init.php';
$user = require_admin();

$status = (string) ($_GET['status'] ?? 'new');
if (!in_array($status, ['new', 'done', 'all'], true)) {
    $status = 'new';
}
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);
    $done = ($_POST['action'] ?? '') === 'done';
    enquiry_set_status($id, $done ? 'done' : 'new');
    flash($done ? 'Marked as answered.' : 'Moved back to New.');
    redirect('enquiries.php?status=' . rawurlencode($status));
}

$list = enquiry_list($status, $page);
$counts = [
    'new' => enquiry_new_count(),
    'done' => (int) db_value("SELECT COUNT(*) FROM enquiries WHERE status = 'done'"),
];
$counts['all'] = $counts['new'] + $counts['done'];
$tabs = ['new' => 'New', 'done' => 'Answered', 'all' => 'All'];

admin_header('Inquiries', 'enquiries', $user);
?>
<section class="card">
  <div class="list-head">
    <div>
      <h1>Inquiries</h1>
      <p class="muted">Messages from the Contact and Celebrations pages. Each one is also emailed to <?= e(setting('notify_email')) ?> — reply from your email.</p>
    </div>
  </div>

  <nav class="status-tabs" aria-label="Inquiry status">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="?status=<?= e($key) ?>"<?= $key === $status ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= $counts[$key] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$list['rows']): ?>
    <p class="empty"><?= $status === 'new' ? 'No new inquiries.' : 'No inquiries here yet.' ?></p>
  <?php else: ?>
    <ul class="order-list">
      <?php foreach ($list['rows'] as $q): ?>
        <li class="enquiry">
          <div class="order-row-top">
            <strong><?= e($q['type']) ?></strong><?php if ($q['order_ref'] !== ''): ?> · <?= e($q['order_ref']) ?><?php endif; ?>
            <span class="pill<?= $q['status'] === 'new' ? ' pill-pending' : ' pill-completed' ?>"><?= $q['status'] === 'new' ? 'New' : 'Answered' ?></span>
            <span class="muted enquiry-date"><?= e(pretty_datetime($q['created_at'])) ?></span>
          </div>
          <p class="who"><?= e($q['name']) ?> · <a href="mailto:<?= e($q['email']) ?>?subject=<?= rawurlencode('Re: ' . $q['type'] . ' inquiry') ?>"><?= e($q['email']) ?></a></p>
          <?php if ($q['event_date'] || $q['quantity'] !== ''): ?>
            <p class="muted"><?= $q['event_date'] ? 'Event ' . e(pretty_date($q['event_date'])) : '' ?><?= $q['event_date'] && $q['quantity'] !== '' ? ' · ' : '' ?><?= e($q['quantity']) ?></p>
          <?php endif; ?>
          <p class="note-box"><?= nl2br(e($q['message'])) ?></p>
          <form method="post" class="enquiry-action">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
            <?php if ($q['status'] === 'new'): ?>
              <button class="btn btn-small" type="submit" name="action" value="done">✓ Mark as answered</button>
            <?php else: ?>
              <button class="btn btn-small btn-ghost" type="submit" name="action" value="new">Move back to New</button>
            <?php endif; ?>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($list['pages'] > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($list['page'] > 1): ?><a class="btn btn-ghost" href="?status=<?= e($status) ?>&amp;page=<?= $list['page'] - 1 ?>">← Previous</a><?php endif; ?>
        <span>Page <?= $list['page'] ?> of <?= $list['pages'] ?> · <?= $list['total'] ?> inquiries</span>
        <?php if ($list['page'] < $list['pages']): ?><a class="btn btn-ghost" href="?status=<?= e($status) ?>&amp;page=<?= $list['page'] + 1 ?>">Next →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php admin_footer();
