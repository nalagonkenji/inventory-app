<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

$isAdmin = ($_SESSION['role'] ?? 'staff') === 'admin';

// ---------- Admin actions: post or remove announcements ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Only administrators can change announcements.');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'announce') {
        $msg = trim($_POST['message'] ?? '');
        if ($msg === '' || mb_strlen($msg) > 255) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'An announcement must be 1 to 255 characters.'];
        } else {
            $by = $_SESSION['username'];
            $stmt = $conn->prepare("INSERT INTO announcements (message, posted_by) VALUES (?, ?)");
            $stmt->bind_param("ss", $msg, $by);
            $stmt->execute();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Announcement posted.'];
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Announcement removed.'];
    }
    header('Location: notifications.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Load each section only if its toggle is on ----------
$on = fn(string $key, string $default = '1') => app_setting($key, $default) === '1';

$low = $on('notif_low_stock')
    ? run_query($conn, "SELECT name, category, quantity FROM parts WHERE quantity <= ? ORDER BY quantity, name LIMIT 50", "i", [LOW_STOCK])->fetch_all(MYSQLI_ASSOC)
    : null;

$ins = $on('notif_inventory')
    ? run_query($conn, "SELECT t.quantity, t.performed_by, t.created_at, p.name FROM transactions t JOIN parts p ON p.id = t.part_id WHERE t.type = 'in' AND t.created_at >= NOW() - INTERVAL 7 DAY ORDER BY t.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC)
    : null;

$outs = $on('notif_transactions')
    ? run_query($conn, "SELECT t.quantity, t.performed_by, t.created_at, p.name FROM transactions t JOIN parts p ON p.id = t.part_id WHERE t.type = 'out' AND t.created_at >= NOW() - INTERVAL 7 DAY ORDER BY t.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC)
    : null;

$anns = $on('notif_announcements', '0')
    ? run_query($conn, "SELECT id, message, posted_by, created_at FROM announcements ORDER BY created_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC)
    : null;

function off_note(): string {
    return '<p class="muted">Turned off in <a href="settings.php?tab=notifications">Settings &rarr; Notifications</a>.</p>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('notifications'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('bell', 26) ?><h1>Notifications</h1></div>
        <p>Low stock alerts, stock movements, and system announcements.</p>
      </div>
      <div class="top-right">
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
        <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>

      <section class="panel">
        <div class="panel-head"><h2><?= icon('alert', 20) ?> Low Stock Alerts</h2></div>
        <?php if ($low === null): echo off_note(); else: ?>
          <?php if (!$low): ?>
            <p class="muted">Nothing is low on stock.</p>
          <?php else: ?>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>Item</th><th>Category</th><th>Quantity</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($low as $r): [$label, $cls] = stock_status((int)$r['quantity']); ?>
                <tr>
                  <td><?= e($r['name']) ?></td>
                  <td><?= e($r['category']) ?></td>
                  <td><?= (int)$r['quantity'] ?></td>
                  <td><span class="pill pill-<?= $cls === 'out' ? 'out' : 'low' ?>"><?= e($label) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="panel" style="margin-top:18px">
        <div class="panel-head"><h2><?= icon('plus', 20) ?> Stock In (last 7 days)</h2></div>
        <?php if ($ins === null): echo off_note(); elseif (!$ins): ?>
          <p class="muted">No stock received in the last 7 days.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>When</th><th>Item</th><th>Quantity</th><th>By</th></tr></thead>
              <tbody>
              <?php foreach ($ins as $r): ?>
                <tr>
                  <td><?= e(fmt_datetime($r['created_at'])) ?></td>
                  <td><?= e($r['name']) ?></td>
                  <td>+<?= (int)$r['quantity'] ?></td>
                  <td><?= e($r['performed_by']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel" style="margin-top:18px">
        <div class="panel-head"><h2><?= icon('download', 20) ?> Stock Out (last 7 days)</h2></div>
        <?php if ($outs === null): echo off_note(); elseif (!$outs): ?>
          <p class="muted">No stock issued in the last 7 days.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th>When</th><th>Item</th><th>Quantity</th><th>By</th></tr></thead>
              <tbody>
              <?php foreach ($outs as $r): ?>
                <tr>
                  <td><?= e(fmt_datetime($r['created_at'])) ?></td>
                  <td><?= e($r['name']) ?></td>
                  <td>-<?= abs((int)$r['quantity']) ?></td>
                  <td><?= e($r['performed_by']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel" style="margin-top:18px">
        <div class="panel-head"><h2><?= icon('bell', 20) ?> System Announcements</h2></div>
        <?php if ($anns === null): echo off_note(); else: ?>
          <?php if ($isAdmin): ?>
            <form method="post" class="dialog-form" style="margin-bottom:16px">
              <input type="hidden" name="action" value="announce">
              <label>New announcement
                <input name="message" maxlength="255" required placeholder="Example: Stock count this Friday">
              </label>
              <div class="dialog-actions"><button class="btn-d primary" type="submit"><?= icon('plus', 16) ?> Post</button></div>
            </form>
          <?php endif; ?>
          <?php if (!$anns): ?>
            <p class="muted">No announcements yet.</p>
          <?php else: foreach ($anns as $a): ?>
            <div class="setting-row">
              <span>
                <strong><?= e($a['message']) ?></strong><br>
                <small class="muted"><?= e($a['posted_by']) ?> &middot; <?= e(fmt_datetime($a['created_at'])) ?></small>
              </span>
              <?php if ($isAdmin): ?>
              <form method="post" class="inline" onsubmit="return confirm('Remove this announcement?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button class="icon-btn del" title="Remove" type="submit"><?= icon('trash', 16) ?></button>
              </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>
</body>
</html>
