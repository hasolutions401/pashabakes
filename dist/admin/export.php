<?php
/* Download orders as a CSV file (opens in Excel, Numbers or Google Sheets). ?what=orders (current) or ?what=archive. */
require __DIR__ . '/_init.php';
require_admin();

$which = ($_GET['what'] ?? '') === 'archive' ? 'archive' : 'orders';
$name = 'pashabakess-' . ($which === 'archive' ? 'archived-orders' : 'orders') . '-' . today()->format('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // lets Excel read accents and "×" correctly
foreach (orders_csv_rows($which) as $row) {
    fputcsv($out, $row, ',', '"', '');
}
fclose($out);
