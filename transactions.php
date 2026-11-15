<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

const TX_PER_PAGE = 10;

// ---------- Create / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $returnQs = preg_replace('/[\r\n]/', '', $_POST['return_query'] ?? '');

    if ($action === 'delete') {
        $tid = (int)($_POST['id'] ?? 0);
        $row = run_query($conn, "SELECT part_id, quantity FROM transactions WHERE id = ?", "i", [$tid])->fetch_assoc();
        if ($row) {
            $change = (int)$row['quantity'];
            $pid = (int)$row['part_id'];
            $conn->begin_transaction();
            $u = $conn->prepare("UPDATE parts SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
            $u->bind_param("ii", $change, $pid);
            $u->execute();
            $d = $conn->prepare("DELETE FROM transactions WHERE id = ?");
            $d->bind_param("i", $tid);
            $d->execute();
            $conn->commit();
        }
        $_SESSION['flash'] = 'Transaction deleted and stock reversed.';
    } else {
        $partId = (int)($_POST['part_id'] ?? 0);
        $type = in_array($_POST['type'] ?? '', ['in', 'out', 'adjust'], true) ? $_POST['type'] : 'in';
        $qty = (int)($_POST['quantity'] ?? 0);
        $note = substr(trim($_POST['note'] ?? ''), 0, 255);
        $part = run_query($conn, "SELECT id, quantity FROM parts WHERE id = ?", "i", [$partId])->fetch_assoc();

        if (!$part || $qty === 0) {
            $_SESSION['flash'] = 'Choose an item and a quantity other than zero.';
        } else {
            $change = $type === 'in' ? abs($qty) : ($type === 'out' ? -abs($qty) : $qty);
            if ((int)$part['quantity'] + $change < 0) {
                $_SESSION['flash'] = 'Not enough stock for that stock out.';
            } else {
                $user = $_SESSION['username'];
                $conn->begin_transaction();
                $u = $conn->prepare("UPDATE parts SET quantity = quantity + ? WHERE id = ?");
                $u->bind_param("ii", $change, $partId);
                $u->execute();
                $i = $conn->prepare("INSERT INTO transactions (part_id, type, quantity, performed_by, note) VALUES (?, ?, ?, ?, ?)");
                $i->bind_param("isiss", $partId, $type, $change, $user, $note);
                $i->execute();
                $conn->commit();
                $_SESSION['flash'] = 'Transaction recorded.';
            }
        }
    }
    header('Location: transactions.php' . ($returnQs !== '' ? '?' . $returnQs : ''));
    exit;
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$typeFilter = in_array($_GET['type'] ?? 'all', ['in', 'out', 'adjust'], true) ? $_GET['type'] : 'all';
$where = [];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = "(p.name LIKE ? OR t.performed_by LIKE ? OR CONCAT('TXN-', LPAD(t.id, 6, '0')) LIKE ?)";
    $types .= 'sss';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($typeFilter !== 'all') {
    $where[] = 't.type = ?';
    $types .= 's';
    $params[] = $typeFilter;
}
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseParams = array_filter(['q' => $q, 'type' => $typeFilter], fn($v) => $v !== '' && $v !== 'all');

// ---------- CSV export ----------
if (isset($_GET['export'])) {
    $rows = run_query($conn, "SELECT t.id, t.type, t.quantity, p.name, t.performed_by, t.created_at
        FROM transactions t LEFT JOIN parts p ON p.id = t.part_id $clause ORDER BY t.id DESC", $types, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transactions.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Transaction No', 'Type', 'Item', 'Quantity', 'Performed By', 'Date']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, ['TXN-' . str_pad($r['id'], 6, '0', STR_PAD_LEFT), $r['type'], $r['name'], $r['quantity'], $r['performed_by'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

// ---------- Data ----------
$totals = run_query($conn, "SELECT
        COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END), 0) AS stock_in,
        COALESCE(-SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) AS stock_out,
        SUM(CASE WHEN type = 'adjust' THEN 1 ELSE 0 END) AS adjustments
    FROM transactions")->fetch_assoc();

$countRow = run_query($conn, "SELECT COUNT(*) AS c FROM transactions t LEFT JOIN parts p ON p.id = t.part_id $clause", $types, $params)->fetch_assoc();
$matching = (int)$countRow['c'];
$pages = max(1, (int)ceil($matching / TX_PER_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * TX_PER_PAGE;

$rows = run_query($conn, "SELECT t.id, t.type, t.quantity, t.performed_by, t.created_at, p.name
    FROM transactions t LEFT JOIN parts p ON p.id = t.part_id $clause
    ORDER BY t.id DESC LIMIT ? OFFSET ?", $types . 'ii', [...$params, TX_PER_PAGE, $offset]);

$parts = run_query($conn, "SELECT id, name, quantity FROM parts ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$returnQs = http_build_query(array_merge($baseParams, ['page' => $page]));
$lowOrOut = 0;
$shownFrom = $matching ? $offset + 1 : 0;
$shownTo = min($offset + TX_PER_PAGE, $matching);

function tx_url(array $base, array $extra): string {
    return 'transactions.php?' . http_build_query(array_merge($base, $extra));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Transactions | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('transactions'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('transfer', 26) ?><h1>Transactions</h1></div>
        <p>Record and manage stock in, stock out, and adjustments.</p>
      </div>
      <div class="top-right">
        <div class="bell"><?= icon('bell', 22) ?></div>
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
      <?php if ($flash): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
      <?php endif; ?>

      <section class="stats stats-3">
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('arrowup', 26) ?></div>
          <div><small>Stock In (Received)</small><strong><?= number_format((int)$totals['stock_in']) ?></strong></div>
        </div>
        <div class="stat stat-red">
          <div class="stat-icon"><?= icon('arrowdown', 26) ?></div>
          <div><small>Stock Out (Issued)</small><strong><?= number_format((int)$totals['stock_out']) ?></strong></div>
        </div>
        <div class="stat stat-blue">
          <div class="stat-icon"><?= icon('adjust', 26) ?></div>
          <div><small>Adjustments</small><strong><?= (int)$totals['adjustments'] ?></strong></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('transfer', 22) ?> Transaction History</h2>
          <div class="actions">
            <button type="button" id="openNew" class="btn-d primary"><?= icon('plus', 16) ?> New Transaction</button>
            <a class="btn-d" href="transactions.php?<?= e(http_build_query(array_merge($baseParams, ['export' => 1]))) ?>"><?= icon('download', 16) ?> Export CSV</a>
          </div>
        </div>

        <form method="get" class="filters two">
          <label class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" placeholder="Search by transaction no., item, or user..." value="<?= e($q) ?>">
          </label>
          <select name="type" onchange="this.form.submit()" aria-label="Transaction type">
            <option value="all">All Transaction Types</option>
            <option value="in" <?= $typeFilter === 'in' ? 'selected' : '' ?>>Stock In</option>
            <option value="out" <?= $typeFilter === 'out' ? 'selected' : '' ?>>Stock Out</option>
            <option value="adjust" <?= $typeFilter === 'adjust' ? 'selected' : '' ?>>Adjustment</option>
          </select>
        </form>

        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>#</th><th>Transaction No.</th><th>Type</th><th>Item Name</th>
                <th>Quantity</th><th>Date &amp; Time</th><th>Performed By</th><th class="right">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($rows->num_rows === 0): ?>
              <tr><td colspan="8" class="empty">No transactions yet. Click New Transaction to record one.</td></tr>
            <?php endif; ?>
            <?php while ($r = $rows->fetch_assoc()):
                $change = (int)$r['quantity'];
                [$typeLabel, $pill, $arrow] = match ($r['type']) {
                    'in'     => ['Stock In', 'pill-in', 'arrowup'],
                    'out'    => ['Stock Out', 'pill-out', 'arrowdown'],
                    default  => ['Adjustment', 'pill-adj', 'adjust'],
                };
                $qtyClass = $change > 0 ? 'qty-up' : ($change < 0 ? 'qty-down' : '');
                $qtyText = ($change > 0 ? '+' : '') . $change;
            ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td>TXN-<?= str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT) ?></td>
                <td><span class="pill <?= $pill ?>"><?= icon($arrow, 14) ?> <?= $typeLabel ?></span></td>
                <td><strong><?= e($r['name'] ?? '(deleted item)') ?></strong></td>
                <td class="<?= $qtyClass ?>"><?= e($qtyText) ?></td>
                <td><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                <td><?= e($r['performed_by']) ?></td>
                <td class="right">
                  <div class="row-actions">
                    <form method="post" class="inline" onsubmit="return confirm('Delete this transaction? The stock change will be reversed.');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
          <span>Showing <?= $shownFrom ?> to <?= $shownTo ?> of <?= $matching ?> transactions</span>
          <nav class="pager">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(tx_url($baseParams, ['page' => max(1, $page - 1)])) ?>" aria-label="Previous"><?= icon('left', 16) ?></a>
            <?php for ($n = 1; $n <= $pages; $n++): ?>
              <a class="<?= $n === $page ? 'current' : '' ?>" href="<?= e(tx_url($baseParams, ['page' => $n])) ?>"><?= $n ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(tx_url($baseParams, ['page' => min($pages, $page + 1)])) ?>" aria-label="Next"><?= icon('right', 16) ?></a>
          </nav>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- New transaction dialog -->
<dialog id="txDialog" class="item-dialog">
  <form method="post" class="dialog-form">
    <h2>New transaction</h2>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
    <label>Item
      <select name="part_id" required>
        <option value="">Choose an item...</option>
        <?php foreach ($parts as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (stock: <?= (int)$p['quantity'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Type
      <select name="type" required>
        <option value="in">Stock In (received)</option>
        <option value="out">Stock Out (issued)</option>
        <option value="adjust">Adjustment (use + or - number)</option>
      </select>
    </label>
    <label>Quantity
      <input name="quantity" type="number" required value="1">
    </label>
    <label>Note (optional)
      <input name="note" maxlength="255">
    </label>
    <div class="dialog-actions">
      <button type="button" class="btn-d" id="cancelNew">Cancel</button>
      <button class="btn-d primary" type="submit">Save</button>
    </div>
  </form>
</dialog>

<script>
  (function () {
    var dlg = document.getElementById('txDialog');
    document.getElementById('openNew').addEventListener('click', function () { dlg.showModal(); });
    document.getElementById('cancelNew').addEventListener('click', function () { dlg.close(); });
  })();
</script>
</body>
</html>
