<?php
declare(strict_types=1);

/* Shared admin page shell. */

function admin_header(string $title, string $active = '', ?array $user = null): void
{
    $nav = [
        'orders' => ['index.php', 'Orders'],
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
  <link rel="stylesheet" href="admin.css?v=6">
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
    <?php $newEnquiries = enquiry_new_count(); ?>
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?><?php if ($key === 'enquiries' && $newEnquiries > 0): ?> <span class="count"><?= $newEnquiries ?><span class="sr-only"> new</span></span><?php endif; ?></a>
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
