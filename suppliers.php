<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

const SUP_PER_PAGE = 10;
const SUP_STATUSES = ['active', 'pending', 'inactive'];
const SUP_CATEGORIES = ['Processors', 'Graphics Cards', 'Memory (RAM)', 'Storage (SSD/HDD)',
    'Motherboards', 'Power Supply', 'Cooling', 'PC Case', 'Peripherals', 'All Parts'];

// ---------- Create / update / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $returnQs = preg_replace('/[\r\n]/', '', $_POST['return_query'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $_SESSION['flash'] = 'Supplier deleted.';
    } else {
        $name = substr(trim($_POST['name'] ?? ''), 0, 100);
        $person = substr(trim($_POST['contact_person'] ?? ''), 0, 100);
        $number = substr(trim($_POST['contact_number'] ?? ''), 0, 30);
        $email = substr(trim($_POST['email'] ?? ''), 0, 120);
        $address = substr(trim($_POST['address'] ?? ''), 0, 255);
        $status = in_array($_POST['status'] ?? '', SUP_STATUSES, true) ? $_POST['status'] : 'active';
        $cats = array_values(array_intersect($_POST['cats'] ?? [], SUP_CATEGORIES));
        $categories = implode(', ', $cats);

        if ($name === '') {
            $_SESSION['flash'] = 'Supplier name is required.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = 'Please enter a valid email address.';
        } else {
            if ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("UPDATE suppliers SET name=?, contact_person=?, contact_number=?, email=?, address=?, categories=?, status=? WHERE id=?");
                $stmt->bind_param("sssssssi", $name, $person, $number, $email, $address, $categories, $status, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO suppliers (name, contact_person, contact_number, email, address, categories, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssss", $name, $person, $number, $email, $address, $categories, $status);
            }
            $stmt->execute();
            $_SESSION['flash'] = $action === 'update' ? 'Supplier updated.' : 'Supplier added.';
        }
    }
    header('Location: suppliers.php' . ($returnQs !== '' ? '?' . $returnQs : ''));
    exit;
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? 'all', array_merge(['all'], SUP_STATUSES), true) ? ($_GET['status'] ?? 'all') : 'all';
$cat = in_array($_GET['cat'] ?? '', SUP_CATEGORIES, true) ? $_GET['cat'] : '';

$where = [];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = "(name LIKE ? OR contact_person LIKE ? OR contact_number LIKE ? OR email LIKE ?)";
    $types .= 'ssss';
    array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
}
if ($status !== 'all') { $where[] = 'status = ?'; $types .= 's'; $params[] = $status; }
if ($cat !== '') { $where[] = 'categories LIKE ?'; $types .= 's'; $params[] = '%' . $cat . '%'; }
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseParams = array_filter(['q' => $q, 'status' => $status, 'cat' => $cat], fn($v) => $v !== '' && $v !== 'all');

// ---------- CSV export ----------
if (isset($_GET['export'])) {
    $rows = run_query($conn, "SELECT * FROM suppliers $clause ORDER BY id DESC", $types, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="suppliers.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Name', 'Contact Person', 'Contact Number', 'Email', 'Address', 'Categories', 'Status']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [$r['id'], $r['name'], $r['contact_person'], $r['contact_number'], $r['email'], $r['address'], $r['categories'], $r['status']]);
    }
    fclose($out);
    exit;
}

// ---------- Data ----------
$totals = run_query($conn, "SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
    FROM suppliers")->fetch_assoc();

$countRow = run_query($conn, "SELECT COUNT(*) AS c FROM suppliers $clause", $types, $params)->fetch_assoc();
$matching = (int)$countRow['c'];
$pages = max(1, (int)ceil($matching / SUP_PER_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * SUP_PER_PAGE;

$rows = run_query($conn, "SELECT * FROM suppliers $clause ORDER BY id DESC LIMIT ? OFFSET ?", $types . 'ii', [...$params, SUP_PER_PAGE, $offset]);

$editRow = null;
if (isset($_GET['edit'])) {
    $editRow = run_query($conn, "SELECT * FROM suppliers WHERE id = ?", "i", [(int)$_GET['edit']])->fetch_assoc();
}
$editCats = $editRow ? array_map('trim', explode(',', $editRow['categories'])) : [];

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$returnQs = http_build_query(array_merge($baseParams, ['page' => $page]));
$shownFrom = $matching ? $offset + 1 : 0;
$shownTo = min($offset + SUP_PER_PAGE, $matching);

function sup_url(array $base, array $extra): string {
    return 'suppliers.php?' . http_build_query(array_merge($base, $extra));
}
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $p) { $letters .= mb_substr($p, 0, 1); }
    return strtoupper($letters ?: '?');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suppliers | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('suppliers'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('truck', 26) ?><h1>Suppliers</h1></div>
        <p>Manage your suppliers, contact details, and supply records.</p>
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
      <?php if ($flash): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
      <?php endif; ?>

      <section class="stats stats-3">
        <div class="stat stat-blue">
          <div class="stat-icon"><?= icon('users', 26) ?></div>
          <div><small>Total Suppliers</small><strong><?= (int)$totals['total'] ?></strong></div>
        </div>
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('box', 26) ?></div>
          <div><small>Active Suppliers</small><strong><?= (int)$totals['active'] ?></strong></div>
        </div>
        <div class="stat stat-red">
          <div class="stat-icon"><?= icon('x', 26) ?></div>
          <div><small>Inactive Suppliers</small><strong><?= (int)$totals['inactive'] ?></strong></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('truck', 22) ?> Supplier List</h2>
          <div class="actions">
            <button type="button" id="openNew" class="btn-d primary"><?= icon('plus', 16) ?> Add Supplier</button>
            <a class="btn-d" href="suppliers.php?<?= e(http_build_query(array_merge($baseParams, ['export' => 1]))) ?>"><?= icon('download', 16) ?> Export CSV</a>
          </div>
        </div>

        <form method="get" class="filters three">
          <label class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" placeholder="Search supplier by name, contact, or email..." value="<?= e($q) ?>">
          </label>
          <select name="status" onchange="this.form.submit()" aria-label="Status">
            <option value="all">All Status</option>
            <?php foreach (SUP_STATUSES as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="cat" onchange="this.form.submit()" aria-label="Category">
            <option value="">All Categories</option>
            <?php foreach (SUP_CATEGORIES as $c): if ($c === 'All Parts') continue; ?>
              <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="table-wrap">
          <table class="tbl tbl-sup">
            <thead>
              <tr>
                <th>#</th><th>Supplier Name</th><th>Contact Person</th><th>Contact Number</th>
                <th>Email</th><th>Address</th><th>Categories Supplied</th><th>Status</th><th class="right">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($rows->num_rows === 0): ?>
              <tr><td colspan="9" class="empty">No suppliers match your filters.</td></tr>
            <?php endif; ?>
            <?php while ($r = $rows->fetch_assoc()):
                [$label, $pill, $statusIcon] = match ($r['status']) {
                    'active'   => ['Active', 'pill-in', 'check'],
                    'pending'  => ['Pending', 'pill-low', 'alert'],
                    default    => ['Inactive', 'pill-out', 'x'],
                };
                $catChips = array_filter(array_map('trim', explode(',', $r['categories'])));
            ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td>
                  <div class="item">
                    <span class="sup-badge <?= tag_class($r['name']) ?>"><?= e(initials($r['name'])) ?></span>
                    <strong><?= e($r['name']) ?></strong>
                  </div>
                </td>
                <td><?= e($r['contact_person']) ?></td>
                <td><?= e($r['contact_number']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td class="addr"><?= e($r['address']) ?></td>
                <td>
                  <div class="chips">
                    <?php foreach ($catChips as $c): ?>
                      <span class="tag <?= tag_class($c) ?>"><?= e($c) ?></span>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td><span class="pill <?= $pill ?>"><?= icon($statusIcon, 14) ?> <?= $label ?></span></td>
                <td class="right">
                  <div class="row-actions">
                    <a class="icon-btn edit" title="Edit" href="<?= e(sup_url($baseParams, ['page' => $page, 'edit' => $r['id']])) ?>"><?= icon('edit', 16) ?></a>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this supplier?');">
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
          <span>Showing <?= $shownFrom ?> to <?= $shownTo ?> of <?= $matching ?> suppliers</span>
          <nav class="pager">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(sup_url($baseParams, ['page' => max(1, $page - 1)])) ?>" aria-label="Previous"><?= icon('left', 16) ?></a>
            <?php for ($n = 1; $n <= $pages; $n++): ?>
              <a class="<?= $n === $page ? 'current' : '' ?>" href="<?= e(sup_url($baseParams, ['page' => $n])) ?>"><?= $n ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(sup_url($baseParams, ['page' => min($pages, $page + 1)])) ?>" aria-label="Next"><?= icon('right', 16) ?></a>
          </nav>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- Add / edit dialog -->
<dialog id="supDialog" class="item-dialog wide" data-open="<?= $editRow ? 1 : 0 ?>">
  <form method="post" class="dialog-form">
    <h2><?= $editRow ? 'Edit supplier' : 'Add supplier' ?></h2>
    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">

    <div class="form-2col">
      <label>Supplier name
        <input name="name" required maxlength="100" value="<?= e($editRow['name'] ?? '') ?>">
      </label>
      <label>Contact person
        <input name="contact_person" maxlength="100" value="<?= e($editRow['contact_person'] ?? '') ?>">
      </label>
      <label>Contact number
        <input name="contact_number" maxlength="30" value="<?= e($editRow['contact_number'] ?? '') ?>">
      </label>
      <label>Email
        <input name="email" type="email" maxlength="120" value="<?= e($editRow['email'] ?? '') ?>">
      </label>
    </div>
    <label>Address
      <input name="address" maxlength="255" value="<?= e($editRow['address'] ?? '') ?>">
    </label>
    <label>Status
      <select name="status">
        <?php foreach (SUP_STATUSES as $s): ?>
          <option value="<?= $s ?>" <?= ($editRow['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <fieldset class="cats">
      <legend>Categories supplied</legend>
      <div class="cat-grid">
        <?php foreach (SUP_CATEGORIES as $c): if ($c === 'All Parts') continue; ?>
          <label class="check"><input type="checkbox" name="cats[]" value="<?= e($c) ?>" <?= in_array($c, $editCats, true) ? 'checked' : '' ?>> <?= e($c) ?></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="dialog-actions">
      <a class="btn-d" href="<?= e('suppliers.php?' . http_build_query($baseParams)) ?>">Cancel</a>
      <button class="btn-d primary" type="submit">Save</button>
    </div>
  </form>
</dialog>

<script>
  (function () {
    var dlg = document.getElementById('supDialog');
    document.getElementById('openNew').addEventListener('click', function () { dlg.showModal(); });
    if (dlg.dataset.open === '1') dlg.showModal();
  })();
</script>
</body>
</html>
