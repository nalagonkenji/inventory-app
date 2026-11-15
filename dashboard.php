<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

$totals = run_query($conn, "SELECT COUNT(*) AS total,
        COALESCE(SUM(quantity), 0) AS units,
        SUM(CASE WHEN quantity BETWEEN 1 AND " . LOW_STOCK . " THEN 1 ELSE 0 END) AS low,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS outc
    FROM parts")->fetch_assoc();

$catRows = run_query($conn, "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS cat, COUNT(*) AS c
    FROM parts GROUP BY cat ORDER BY c DESC")->fetch_all(MYSQLI_ASSOC);
$stockRows = run_query($conn, "SELECT name, quantity FROM parts ORDER BY quantity DESC, id DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);
$recent = run_query($conn, "SELECT id, name, category, price, quantity FROM parts ORDER BY id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$lowRows = run_query($conn, "SELECT id, name, category, quantity FROM parts
    WHERE quantity BETWEEN 1 AND " . LOW_STOCK . " ORDER BY quantity ASC, id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Donut: top 5 categories plus "Others"
$itemsTotal = array_sum(array_column($catRows, 'c'));
$colors = ['#2563eb', '#a855f7', '#22c55e', '#f59e0b', '#ef4444', '#64748b'];
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

$maxQty = max(1, (int)max(array_column($stockRows, 'quantity') ?: [1]));
$lowOrOut = (int)$totals['low'] + (int)$totals['outc'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('dashboard'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('monitor', 26) ?><h1>Dashboard</h1></div>
        <p>Overview of your inventory and stock.</p>
      </div>
      <div class="top-right">
        <div class="bell" title="<?= $lowOrOut ?> items need attention">
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
      <section class="welcome">
        <div>
          <h2>Welcome Back, <?= e($_SESSION['username']) ?>!</h2>
          <p>Here's what's happening with your inventory today.</p>
        </div>
      </section>

      <section class="stats">
        <div class="stat stat-blue">
          <div class="stat-icon"><?= icon('box', 26) ?></div>
          <div><small>Total Items</small><strong><?= (int)$totals['total'] ?></strong></div>
        </div>
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('layers', 26) ?></div>
          <div><small>In Stock (units)</small><strong><?= number_format((int)$totals['units']) ?></strong></div>
        </div>
        <div class="stat stat-amber">
          <div class="stat-icon"><?= icon('alert', 26) ?></div>
          <div><small>Low Stock</small><strong><?= (int)$totals['low'] ?></strong></div>
        </div>
        <div class="stat stat-red">
          <div class="stat-icon"><?= icon('x', 26) ?></div>
          <div><small>Out of Stock</small><strong><?= (int)$totals['outc'] ?></strong></div>
        </div>
      </section>

      <section class="dash-grid grid-a">
        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('chart', 20) ?> Stock Levels <span class="muted">(top items)</span></h2>
          </div>
          <?php if (!$stockRows): ?>
            <p class="muted">No items yet. Add parts on the Inventory page.</p>
          <?php endif; ?>
          <div class="bars">
            <?php foreach ($stockRows as $s): $w = round((int)$s['quantity'] / $maxQty * 100, 1); ?>
              <div class="bar-row">
                <span class="bar-label"><?= e($s['name']) ?></span>
                <div class="bar-track"><div class="bar-fill" style="width: <?= $w ?>%"></div></div>
                <span class="bar-value"><?= (int)$s['quantity'] ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('layers', 20) ?> Items by Category</h2>
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
                  <span class="legend-value"><?= $count ?> (<?= $itemsTotal ? number_format($count / $itemsTotal * 100, 1) : '0.0' ?>%)</span>
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
            <h2><?= icon('transfer', 20) ?> Recently Added Parts</h2>
            <a class="view-all" href="index.php">View All <?= icon('right', 14) ?></a>
          </div>
          <div class="table-wrap">
            <table class="tbl">
              <thead>
                <tr><th>#</th><th>Item</th><th>Category</th><th>Quantity</th><th>Stock</th></tr>
              </thead>
              <tbody>
              <?php if (!$recent): ?>
                <tr><td colspan="5" class="empty">No items yet.</td></tr>
              <?php endif; ?>
              <?php foreach ($recent as $r):
                  [$label, $key] = stock_status((int)$r['quantity']);
                  $pill = ['in' => 'pill-in', 'low' => 'pill-low', 'out' => 'pill-out'][$key];
              ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><strong><?= e($r['name']) ?></strong></td>
                  <td><span class="tag <?= tag_class($r['category']) ?>"><?= e($r['category'] !== '' ? $r['category'] : 'Uncategorized') ?></span></td>
                  <td><?= (int)$r['quantity'] ?></td>
                  <td><span class="pill <?= $pill ?>"><?= $label ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2><?= icon('alert', 20) ?> Low Stock Alerts</h2>
            <a class="view-all" href="index.php?status=low">View All <?= icon('right', 14) ?></a>
          </div>
          <?php if (!$lowRows): ?>
            <p class="muted">Nothing is running low right now.</p>
          <?php endif; ?>
          <ul class="alert-list">
            <?php foreach ($lowRows as $l): ?>
              <li>
                <div class="alert-item">
                  <span class="thumb"><?= icon('box', 20) ?></span>
                  <div>
                    <strong><?= e($l['name']) ?></strong>
                    <small><?= e($l['category'] !== '' ? $l['category'] : 'Uncategorized') ?></small>
                  </div>
                </div>
                <span class="qty-badge">Qty: <?= (int)$l['quantity'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    </main>
  </div>
</div>
</body>
</html>
