<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

// ---------- Handle add / edit / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $returnQs = preg_replace('/[\r\n]/', '', $_POST['return_query'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $old = run_query($conn, "SELECT name FROM parts WHERE id = ?", "i", [$id])->fetch_assoc();
        $stmt = $conn->prepare("DELETE FROM parts WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        if ($old) audit_log($conn, 'delete', 'item', $id, 'Deleted item "' . $old['name'] . '"');
    } else {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price = max(0, (float)($_POST['price'] ?? 0));
        $qty = max(0, (int)($_POST['quantity'] ?? 0));
        if ($name !== '') {
            if ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $before = run_query($conn, "SELECT name, category, price, quantity FROM parts WHERE id = ?", "i", [$id])->fetch_assoc();
                $stmt = $conn->prepare("UPDATE parts SET name = ?, category = ?, price = ?, quantity = ? WHERE id = ?");
                $stmt->bind_param("ssdii", $name, $category, $price, $qty, $id);
                $stmt->execute();
                if ($before) {
                    $changed = [];
                    if ($before['name'] !== $name) $changed[] = 'name';
                    if ($before['category'] !== $category) $changed[] = 'category';
                    if ((float)$before['price'] !== $price) $changed[] = 'price';
                    $diff = $qty - (int)$before['quantity'];
                    if ($diff !== 0) {
                        // Stock changed on the form: record it so the stock history matches the real quantity.
                        $who = $_SESSION['username'];
                        $note = 'Stock changed from ' . (int)$before['quantity'] . ' to ' . $qty . ' on the inventory form';
                        $tx = $conn->prepare("INSERT INTO transactions (part_id, type, quantity, performed_by, note) VALUES (?, 'adjust', ?, ?, ?)");
                        $tx->bind_param("iiss", $id, $diff, $who, $note);
                        $tx->execute();
                        $changed[] = 'quantity';
                    }
                    if ($changed) audit_log($conn, 'update', 'item', $id, 'Edited "' . $name . '": ' . implode(', ', $changed) . ' changed');
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO parts (name, category, price, quantity) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssdi", $name, $category, $price, $qty);
                $stmt->execute();
                audit_log($conn, 'create', 'item', (int)$conn->insert_id, 'Added item "' . $name . '" with ' . $qty . ' in stock');
            }
        }
    }
    header('Location: index.php' . ($returnQs !== '' ? '?' . $returnQs : ''));
    exit;
}

// ---------- Read data ----------
[$where, $types, $params, $filters] = build_filters($_GET);
$baseParams = array_filter($filters, fn($v) => $v !== '' && $v !== 'all');

$totals = run_query($conn, "SELECT COUNT(*) AS total,
        COALESCE(SUM(quantity), 0) AS units,
        SUM(CASE WHEN quantity BETWEEN 1 AND " . LOW_STOCK . " THEN 1 ELSE 0 END) AS low,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS outc
    FROM parts")->fetch_assoc();

$countRow = run_query($conn, "SELECT COUNT(*) AS c FROM parts $where", $types, $params)->fetch_assoc();
$matching = (int)$countRow['c'];
$pages = max(1, (int)ceil($matching / PER_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * PER_PAGE;

$rows = run_query($conn, "SELECT * FROM parts $where ORDER BY id DESC LIMIT ? OFFSET ?", $types . 'ii', [...$params, PER_PAGE, $offset]);
$catList = run_query($conn, "SELECT DISTINCT category FROM parts WHERE category <> '' ORDER BY category")->fetch_all(MYSQLI_ASSOC);

$editRow = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $editRow = run_query($conn, "SELECT * FROM parts WHERE id = ?", "i", [$eid])->fetch_assoc();
}
$dialogOpen = $editRow !== null;

$returnQs = http_build_query(array_merge($baseParams, ['page' => $page]));
function page_url(array $base, array $extra): string {
    return 'index.php?' . http_build_query(array_merge($base, $extra));
}
$lowOrOut = (int)$totals['low'] + (int)$totals['outc'];
$shownFrom = $matching ? $offset + 1 : 0;
$shownTo = min($offset + PER_PAGE, $matching);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventory | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('inventory'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('box', 26) ?><h1>Inventory</h1></div>
        <p>Manage and track computer parts inventory.</p>
      </div>
      <div class="top-right">
        <?= bell_link() ?>
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
      <section class="stats">
        <div class="stat stat-blue">
          <div class="stat-icon"><?= icon('box', 26) ?></div>
          <div><small>Total Items</small><strong><?= (int)$totals['total'] ?></strong></div>
        </div>
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('layers', 26) ?></div>
          <div><small>Units In Stock</small><strong><?= number_format((int)$totals['units']) ?></strong></div>
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

      <section class="panel">
        <div class="panel-head">
          <h2>Inventory Items</h2>
          <div class="actions">
            <button type="button" id="openAdd" class="btn-d primary"><?= icon('plus', 16) ?> Add Item</button>
            <a class="btn-d" href="export.php?<?= e(http_build_query($baseParams)) ?>"><?= icon('download', 16) ?> Export CSV</a>
          </div>
        </div>

        <form method="get" class="filters">
          <label class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" placeholder="Search item by name..." value="<?= e($filters['q']) ?>">
          </label>
          <select name="cat" onchange="this.form.submit()" aria-label="Category">
            <option value="">All Categories</option>
            <?php foreach ($catList as $c): ?>
              <option value="<?= e($c['category']) ?>" <?= $filters['cat'] === $c['category'] ? 'selected' : '' ?>><?= e($c['category']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" onchange="this.form.submit()" aria-label="Stock status">
            <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All Stock Status</option>
            <option value="in" <?= $filters['status'] === 'in' ? 'selected' : '' ?>>In Stock</option>
            <option value="low" <?= $filters['status'] === 'low' ? 'selected' : '' ?>>Low Stock</option>
            <option value="out" <?= $filters['status'] === 'out' ? 'selected' : '' ?>>Out of Stock</option>
          </select>
        </form>

        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>#</th><th>Item Name</th><th>Category</th><th>Price</th>
                <th>Quantity</th><th>Stock Status</th><th class="right">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($rows->num_rows === 0): ?>
              <tr><td colspan="7" class="empty">No items match your filters.</td></tr>
            <?php endif; ?>
            <?php while ($row = $rows->fetch_assoc()):
                [$label, $key] = stock_status((int)$row['quantity']);
                $pill = ['in' => 'pill-in', 'low' => 'pill-low', 'out' => 'pill-out'][$key];
                $statusIcon = ['in' => 'check', 'low' => 'alert', 'out' => 'x'][$key];
            ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td>
                  <div class="item">
                    <span class="thumb"><?= icon('box', 20) ?></span>
                    <strong><?= e($row['name']) ?></strong>
                  </div>
                </td>
                <td><span class="tag <?= tag_class($row['category']) ?>"><?= e($row['category'] !== '' ? $row['category'] : 'Uncategorized') ?></span></td>
                <td><?= money((float)$row['price']) ?></td>
                <td><?= (int)$row['quantity'] ?></td>
                <td><span class="pill <?= $pill ?>"><?= icon($statusIcon, 14) ?> <?= $label ?></span></td>
                <td class="right">
                  <div class="row-actions">
                    <a class="icon-btn edit" title="Edit" href="<?= e(page_url($baseParams, ['page' => $page, 'edit' => $row['id']])) ?>"><?= icon('edit', 16) ?></a>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this item?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
                      <button class="icon-btn del" title="Delete" type="submit"><?= icon('trash', 16) ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>

        <div class="foot">
          <span>Showing <?= $shownFrom ?> to <?= $shownTo ?> of <?= $matching ?> items</span>
          <nav class="pager">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(page_url($baseParams, ['page' => max(1, $page - 1)])) ?>" aria-label="Previous"><?= icon('left', 16) ?></a>
            <?php for ($n = 1; $n <= $pages; $n++): ?>
              <a class="<?= $n === $page ? 'current' : '' ?>" href="<?= e(page_url($baseParams, ['page' => $n])) ?>"><?= $n ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(page_url($baseParams, ['page' => min($pages, $page + 1)])) ?>" aria-label="Next"><?= icon('right', 16) ?></a>
          </nav>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- Add / edit dialog -->
<dialog id="itemDialog" class="item-dialog" data-open="<?= $dialogOpen ? 1 : 0 ?>">
  <form method="post" class="dialog-form">
    <h2><?= $editRow ? 'Edit item' : 'Add item' ?></h2>
    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'add' ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
    <label>Item name
      <input name="name" required maxlength="100" value="<?= e($editRow['name'] ?? '') ?>">
    </label>
    <label>Category
      <input name="category" list="catList" maxlength="50" value="<?= e($editRow['category'] ?? '') ?>">
      <datalist id="catList"><?php foreach ($catList as $c): ?><option value="<?= e($c['category']) ?>"><?php endforeach; ?></datalist>
    </label>
    <label>Price (<?= e(currency_symbol()) ?>)
      <input name="price" type="number" step="0.01" min="0" required value="<?= e($editRow['price'] ?? '') ?>">
    </label>
    <label>Quantity
      <input name="quantity" type="number" min="0" required value="<?= e($editRow['quantity'] ?? '0') ?>">
    </label>
    <div class="dialog-actions">
      <a class="btn-d" href="<?= e('index.php?' . $returnQs) ?>">Cancel</a>
      <button class="btn-d primary" type="submit">Save</button>
    </div>
  </form>
</dialog>

<script>
  (function () {
    var dlg = document.getElementById('itemDialog');
    document.getElementById('openAdd').addEventListener('click', function () { dlg.showModal(); });
    if (dlg.dataset.open === '1') dlg.showModal();
  })();
</script>
</body>
</html>
