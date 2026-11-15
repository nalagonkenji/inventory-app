<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

// Report library: each report is generated fresh when downloaded.
const REPORTS = [
    'inventory'    => ['Monthly Inventory Summary', 'inventory', 'All items with quantity, price, and stock value.'],
    'category'     => ['Category Stock Summary', 'inventory', 'Items, units, and value grouped by category.'],
    'low_stock'    => ['Low Stock Items Report', 'stock', 'Items that are low or out of stock.'],
    'transactions' => ['Transaction History Report', 'transaction', 'Every stock in, stock out, and adjustment.'],
    'suppliers'    => ['Supplier Directory', 'supplier', 'Supplier contacts, categories, and status.'],
];
const REPORT_TYPE_PILLS = [
    'inventory'   => ['Inventory Report', 'pill-blue', 'box'],
    'stock'       => ['Stock Report', 'pill-low', 'alert'],
    'transaction' => ['Transaction Report', 'pill-in', 'transfer'],
    'supplier'    => ['Supplier Report', 'pill-violet', 'truck'],
];

// ---------- CSV downloads ----------
if (isset($_GET['download']) && isset(REPORTS[$_GET['download']])) {
    $key = $_GET['download'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($key === 'inventory') {
        fputcsv($out, ['ID', 'Name', 'Category', 'Price', 'Quantity', 'Stock Value', 'Status']);
        $rows = run_query($conn, "SELECT id, name, category, price, quantity FROM parts ORDER BY id");
        while ($r = $rows->fetch_assoc()) {
            [$label] = stock_status((int)$r['quantity']);
            fputcsv($out, [$r['id'], $r['name'], $r['category'], $r['price'], $r['quantity'], number_format((float)$r['price'] * (int)$r['quantity'], 2, '.', ''), $label]);
        }
    } elseif ($key === 'category') {
        fputcsv($out, ['Category', 'Items', 'Units', 'Stock Value']);
        $rows = run_query($conn, "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS cat, COUNT(*) AS items,
                SUM(quantity) AS units, SUM(price * quantity) AS value FROM parts GROUP BY cat ORDER BY cat");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [$r['cat'], $r['items'], $r['units'], number_format((float)$r['value'], 2, '.', '')]);
        }
    } elseif ($key === 'low_stock') {
        fputcsv($out, ['ID', 'Name', 'Category', 'Quantity', 'Status']);
        $rows = run_query($conn, "SELECT id, name, category, quantity FROM parts WHERE quantity <= " . LOW_STOCK . " ORDER BY quantity ASC");
        while ($r = $rows->fetch_assoc()) {
            [$label] = stock_status((int)$r['quantity']);
            fputcsv($out, [$r['id'], $r['name'], $r['category'], $r['quantity'], $label]);
        }
    } elseif ($key === 'transactions') {
        fputcsv($out, ['Transaction No', 'Type', 'Item', 'Quantity', 'Performed By', 'Date']);
        $rows = run_query($conn, "SELECT t.id, t.type, t.quantity, p.name, t.performed_by, t.created_at
            FROM transactions t LEFT JOIN parts p ON p.id = t.part_id ORDER BY t.id DESC");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, ['TXN-' . str_pad($r['id'], 6, '0', STR_PAD_LEFT), $r['type'], $r['name'], $r['quantity'], $r['performed_by'], $r['created_at']]);
        }
    } elseif ($key === 'suppliers') {
        fputcsv($out, ['ID', 'Name', 'Contact Person', 'Contact Number', 'Email', 'Address', 'Categories', 'Status']);
        $rows = run_query($conn, "SELECT * FROM suppliers ORDER BY id");
        while ($r = $rows->fetch_assoc()) {
            fputcsv($out, [$r['id'], $r['name'], $r['contact_person'], $r['contact_number'], $r['email'], $r['address'], $r['categories'], $r['status']]);
        }
    }
    fclose($out);
    exit;
}

// ---------- Summary data ----------
$inv = run_query($conn, "SELECT COUNT(*) AS items, COALESCE(SUM(price * quantity), 0) AS value,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS outc FROM parts")->fetch_assoc();
$txThisMonth = (int)run_query($conn, "SELECT COUNT(*) AS c FROM transactions
        WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch_assoc()['c'];

// Monthly stock in / out for the last 6 months
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[date('Y-m', strtotime("-$i month"))] = ['in' => 0, 'out' => 0];
}
$mrows = run_query($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
        COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END), 0) AS inq,
        COALESCE(-SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) AS outq
    FROM transactions
    WHERE created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY ym")->fetch_all(MYSQLI_ASSOC);
foreach ($mrows as $m) {
    if (isset($months[$m['ym']])) {
        $months[$m['ym']] = ['in' => (int)$m['inq'], 'out' => (int)$m['outq']];
    }
}
$maxMonth = max(1, max(array_map(fn($m) => max($m['in'], $m['out']), $months)));
$hasMovement = array_sum(array_map(fn($m) => $m['in'] + $m['out'], $months)) > 0;

// Items by category (donut)
$catRows = run_query($conn, "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS cat, COUNT(*) AS c
    FROM parts GROUP BY cat ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$itemsTotal = array_sum(array_column($catRows, 'c'));
$colors = ['#3b82f6', '#a855f7', '#22c55e', '#f59e0b', '#ef4444', '#64748b'];
$segments = [];
foreach (array_slice($catRows, 0, 5) as $i => $r) $segments[] = [$r['cat'], (int)$r['c'], $colors[$i]];
$others = array_sum(array_column(array_slice($catRows, 5), 'c'));
if ($others > 0) $segments[] = ['Others', $others, $colors[5]];
$stops = [];
$acc = 0.0;
foreach ($segments as [$label, $count, $color]) {
    $pct = $itemsTotal ? $count / $itemsTotal * 100 : 0;
    $stops[] = $color . ' ' . round($acc, 2) . '% ' . round($acc + $pct, 2) . '%';
    $acc += $pct;
}
$gradient = $stops ? implode(', ', $stops) : '#334155 0% 100%';

// Low stock table
$lowRows = run_query($conn, "SELECT id, name, category, quantity FROM parts
    WHERE quantity <= " . LOW_STOCK . " ORDER BY quantity ASC, id DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);

// Top selling categories (by stock out)
$topCats = run_query($conn, "SELECT COALESCE(NULLIF(p.category, ''), 'Uncategorized') AS cat,
        SUM(-t.quantity) AS sold
    FROM transactions t JOIN parts p ON p.id = t.part_id
    WHERE t.type = 'out'
    GROUP BY cat ORDER BY sold DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$maxSold = max(1, max(array_map(fn($r) => (int)$r['sold'], $topCats) ?: [1]));

// ---------- Report library filters ----------
$q = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? 'all';
$now = fmt_datetime('now');
$lowOrOut = (int)$inv['outc'];

$library = [];
foreach (REPORTS as $key => [$name, $type, $desc]) {
    if ($typeFilter !== 'all' && $typeFilter !== $type) continue;
    if ($q !== '' && stripos($name . ' ' . $desc, $q) === false) continue;
    $library[$key] = [$name, $type, $desc];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('reports'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('chart', 26) ?><h1>Reports</h1></div>
        <p>View inventory insights, stock summaries, and transaction reports.</p>
      </div>
      <div class="top-right">
        <div class="bell" title="<?= $lowOrOut ?> items are out of stock">
          <?= icon('bell', 22) ?>
          <?php if ($lowOrOut > 0): ?><span class="dot"><?= $lowOrOut ?></span><?php endif; ?>
        </div>
        <details class="userbox">
          <summary>
            <span class="avatar"><?= icon('users', 18) ?></span>
            <span><?= e($_SESSION['username']) ?></span>
            <?= icon('down', 16) ?>
          </summary>
          <a class="userbox-menu" href="logout.php"><?= icon('logout', 16) ?> Log out</a>
        </details>
      </div>
    </header>

    <main class="content">
      <section class="stats stats-4">
        <div class="stat stat-blue">
          <div class="stat-icon"><?= icon('layers', 26) ?></div>
          <div><small>Report Types</small><strong><?= count(REPORTS) ?></strong></div>
        </div>
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('transfer', 26) ?></div>
          <div><small>Transactions This Month</small><strong><?= $txThisMonth ?></strong></div>
        </div>
        <div class="stat stat-amber">
          <div class="stat-icon"><?= icon('box', 26) ?></div>
          <div><small>Inventory Value</small><strong><?= money((float)$inv['value']) ?></strong></div>
        </div>
        <div class="stat stat-red">
          <div class="stat-icon"><?= icon('alert', 26) ?></div>
          <div><small>Out of Stock Items</small><strong><?= $lowOrOut ?></strong></div>
        </div>
      </section>

      <section class="dash-grid grid-a">
        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('chart', 20) ?> Monthly Stock Summary <span class="muted">(last 6 months)</span></h2>
            <span class="legend-inline"><span class="swatch" style="background:#3b82f6"></span> Stock In <span class="swatch" style="background:#a855f7"></span> Stock Out</span>
          </div>
          <?php if (!$hasMovement): ?>
            <p class="muted">No stock movements yet. Record transactions to see the monthly summary.</p>
          <?php endif; ?>
          <div class="month-chart">
            <?php foreach ($months as $ym => $m):
                $hIn = round($m['in'] / $maxMonth * 100, 1);
                $hOut = round($m['out'] / $maxMonth * 100, 1);
            ?>
              <div class="month-col">
                <div class="month-bars">
                  <div class="mbar mbar-in" style="height: <?= $hIn ?>%" title="In: <?= $m['in'] ?>"></div>
                  <div class="mbar mbar-out" style="height: <?= $hOut ?>%" title="Out: <?= $m['out'] ?>"></div>
                </div>
                <small><?= date('M', strtotime($ym . '-01')) ?></small>
                <span class="net">Net <?= ($m['in'] - $m['out']) >= 0 ? '+' : '' ?><?= $m['in'] - $m['out'] ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('layers', 20) ?> Inventory by Category</h2>
          </div>
          <div class="donut-wrap">
            <div class="donut" style="background: conic-gradient(<?= $gradient ?>)">
              <div class="donut-hole">
                <strong><?= $itemsTotal ?></strong>
                <span>Items</span>
              </div>
            </div>
            <ul class="legend">
              <?php foreach ($segments as [$label, $count, $color]): ?>
                <li>
                  <span class="swatch" style="background: <?= $color ?>"></span>
                  <span class="legend-label"><?= e($label) ?></span>
                  <span class="legend-value"><?= $count ?> (<?= number_format($count / $itemsTotal * 100, 1) ?>%)</span>
                </li>
              <?php endforeach; ?>
              <?php if (!$segments): ?><li class="muted">No items yet.</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </section>

      <section class="dash-grid grid-b">
        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('alert', 20) ?> Low Stock Report</h2>
            <a class="view-all" href="reports.php?download=low_stock">Download CSV <?= icon('download', 14) ?></a>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead>
                <tr><th>#</th><th>Item</th><th>Category</th><th>Current Qty</th><th>Status</th></tr>
              </thead>
              <tbody>
              <?php if (!$lowRows): ?>
                <tr><td colspan="5" class="empty">Nothing is low on stock right now.</td></tr>
              <?php endif; ?>
              <?php foreach ($lowRows as $r):
                  $isOut = (int)$r['quantity'] === 0;
              ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><strong><?= e($r['name']) ?></strong></td>
                  <td><span class="tag <?= tag_class($r['category']) ?>"><?= e($r['category'] !== '' ? $r['category'] : 'Uncategorized') ?></span></td>
                  <td>Qty: <?= (int)$r['quantity'] ?></td>
                  <td><span class="pill <?= $isOut ? 'pill-out' : 'pill-low' ?>"><?= $isOut ? 'Critical' : 'Low Stock' ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('arrowup', 20) ?> Top Selling Categories</h2>
          </div>
          <?php if (!$topCats): ?>
            <p class="muted">No stock out transactions yet.</p>
          <?php endif; ?>
          <div class="bars">
            <?php foreach ($topCats as $c): $w = round((int)$c['sold'] / $maxSold * 100, 1); ?>
              <div class="bar-row">
                <span class="bar-label"><?= e($c['cat']) ?></span>
                <div class="bar-track"><div class="bar-fill" style="width: <?= $w ?>%"></div></div>
                <span class="bar-value"><?= (int)$c['sold'] ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('download', 20) ?> Report Library</h2>
        </div>
        <form method="get" class="filters two">
          <label class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" placeholder="Search report name or keyword..." value="<?= e($q) ?>">
          </label>
          <select name="type" onchange="this.form.submit()" aria-label="Report type">
            <option value="all">All Report Types</option>
            <?php foreach (REPORT_TYPE_PILLS as $k => [$lbl]): ?>
              <option value="<?= $k ?>" <?= $typeFilter === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr><th>#</th><th>Report Name</th><th>Type</th><th>Description</th><th>Prepared By</th><th>Date Generated</th><th>Status</th><th class="right">Download</th></tr>
            </thead>
            <tbody>
            <?php if (!$library): ?>
              <tr><td colspan="8" class="empty">No reports match your search.</td></tr>
            <?php endif; ?>
            <?php $n = 0; foreach ($library as $key => [$name, $type, $desc]):
                $n++;
                [$tlabel, $tpill, $ticon] = REPORT_TYPE_PILLS[$type];
            ?>
              <tr>
                <td><?= $n ?></td>
                <td><strong><?= e($name) ?></strong></td>
                <td><span class="pill <?= $tpill ?>"><?= icon($ticon, 14) ?> <?= $tlabel ?></span></td>
                <td class="addr"><?= e($desc) ?></td>
                <td><?= e($_SESSION['username']) ?></td>
                <td><?= e($now) ?></td>
                <td><span class="pill pill-in"><?= icon('check', 14) ?> Ready</span></td>
                <td class="right">
                  <div class="row-actions">
                    <a class="btn-d" href="reports.php?download=<?= e($key) ?>"><?= icon('download', 14) ?> CSV</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted small-note">Reports are generated when you download them, so they always show current data. Excel files open directly from the CSV download.</p>
      </section>
    </main>
  </div>
</div>
</body>
</html>
