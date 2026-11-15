<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

[$where, $types, $params] = build_filters($_GET);
$rows = run_query($conn, "SELECT id, name, category, price, quantity FROM parts $where ORDER BY id DESC", $types, $params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="inventory.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Name', 'Category', 'Price', 'Quantity', 'Status']);
while ($r = $rows->fetch_assoc()) {
    [$label] = stock_status((int)$r['quantity']);
    fputcsv($out, [$r['id'], $r['name'], $r['category'], $r['price'], $r['quantity'], $label]);
}
fclose($out);
exit;
