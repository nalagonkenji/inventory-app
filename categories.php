<?php
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/inventory_lib.php';

const CAT_PER_PAGE = 10;
const CAT_STATUSES = ['active', 'inactive'];

// ---------- Create / update / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $returnQs = preg_replace('/[\r\n]/', '', $_POST['return_query'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $cat = run_query($conn, "SELECT name FROM categories WHERE id = ?", "i", [$id])->fetch_assoc();
        if ($cat) {
            $used = run_query($conn, "SELECT COUNT(*) AS c FROM parts WHERE category = ?", "s", [$cat['name']])->fetch_assoc();
            if ((int)$used['c'] > 0) {
                $_SESSION['flash'] = 'This category still has items. Move or delete those items first.';
            } else {
                $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $_SESSION['flash'] = 'Category deleted.';
                audit_log($conn, 'delete', 'category', $id, 'Deleted category "' . $cat['name'] . '"');
            }
        }
    } else {
        $name = substr(trim($_POST['name'] ?? ''), 0, 50);
        $description = substr(trim($_POST['description'] ?? ''), 0, 255);
        $status = in_array($_POST['status'] ?? '', CAT_STATUSES, true) ? $_POST['status'] : 'active';
        $id = (int)($_POST['id'] ?? 0);

        if ($name === '') {
            $_SESSION['flash'] = 'Category name is required.';
        } else {
            $dup = run_query($conn, "SELECT id FROM categories WHERE name = ? AND id <> ?", "si", [$name, $id])->fetch_assoc();
            if ($dup) {
                $_SESSION['flash'] = 'A category with that name already exists.';
            } elseif ($action === 'update' && $id > 0) {
                $old = run_query($conn, "SELECT name FROM categories WHERE id = ?", "i", [$id])->fetch_assoc();
                if ($old) {
                    $conn->begin_transaction();
                    $u = $conn->prepare("UPDATE categories SET name = ?, description = ?, status = ? WHERE id = ?");
                    $u->bind_param("sssi", $name, $description, $status, $id);
                    $u->execute();
                    // keep items linked when a category is renamed
                    $p = $conn->prepare("UPDATE parts SET category = ? WHERE category = ?");
                    $p->bind_param("ss", $name, $old['name']);
                    $p->execute();
                    $conn->commit();
                    $_SESSION['flash'] = 'Category updated.';
                    audit_log($conn, 'update', 'category', $id, 'Edited category "' . $name . '" (was "' . $old['name'] . '")');
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (name, description, status) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $description, $status);
                $stmt->execute();
                $_SESSION['flash'] = 'Category added.';
                audit_log($conn, 'create', 'category', (int)$conn->insert_id, 'Added category "' . $name . '"');
            }
        }
    }
    header('Location: categories.php' . ($returnQs !== '' ? '?' . $returnQs : ''));
    exit;
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? 'all', array_merge(['all'], CAT_STATUSES), true) ? $_GET['status'] : 'all';

$where = [];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = "(c.name LIKE ? OR c.description LIKE ?)";
    $types .= 'ss';
    array_push($params, '%' . $q . '%', '%' . $q . '%');
}
if ($status !== 'all') { $where[] = 'c.status = ?'; $types .= 's'; $params[] = $status; }
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseParams = array_filter(['q' => $q, 'status' => $status], fn($v) => $v !== '' && $v !== 'all');

// ---------- CSV export ----------
if (isset($_GET['export'])) {
    $rows = run_query($conn, "SELECT c.name, c.description, c.status,
            (SELECT COUNT(*) FROM parts p WHERE p.category = c.name) AS items
        FROM categories c $clause ORDER BY c.name", $types, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="categories.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Description', 'Total Items', 'Status']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [$r['name'], $r['description'], $r['items'], $r['status']]);
    }
    fclose($out);
    exit;
}

// ---------- Data ----------
$totals = run_query($conn, "SELECT COUNT(*) AS total,
        SUM(CASE WHEN (SELECT COUNT(*) FROM parts p WHERE p.category = c.name) > 0 THEN 1 ELSE 0 END) AS with_items
    FROM categories c")->fetch_assoc();
$totalCats = (int)$totals['total'];
$withItems = (int)$totals['with_items'];
$emptyCats = $totalCats - $withItems;

$countRow = run_query($conn, "SELECT COUNT(*) AS c FROM categories c $clause", $types, $params)->fetch_assoc();
$matching = (int)$countRow['c'];
$pages = max(1, (int)ceil($matching / CAT_PER_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * CAT_PER_PAGE;

$rows = run_query($conn, "SELECT c.*, (SELECT COUNT(*) FROM parts p WHERE p.category = c.name) AS items
    FROM categories c $clause ORDER BY c.name LIMIT ? OFFSET ?", $types . 'ii', [...$params, CAT_PER_PAGE, $offset])->fetch_all(MYSQLI_ASSOC);

$editRow = null;
if (isset($_GET['edit'])) {
    $editRow = run_query($conn, "SELECT * FROM categories WHERE id = ?", "i", [(int)$_GET['edit']])->fetch_assoc();
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$returnQs = http_build_query(array_merge($baseParams, ['page' => $page]));
$shownFrom = $matching ? $offset + 1 : 0;
$shownTo = min($offset + CAT_PER_PAGE, $matching);

function cat_url(array $base, array $extra): string {
    return 'categories.php?' . http_build_query(array_merge($base, $extra));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Categories | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('categories'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('tag', 26) ?><h1>Categories</h1></div>
        <p>Create and manage item categories for your inventory.</p>
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
          <div class="stat-icon"><?= icon('tag', 26) ?></div>
          <div><small>Total Categories</small><strong><?= $totalCats ?></strong></div>
        </div>
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('box', 26) ?></div>
          <div><small>Categories with Items</small><strong><?= $withItems ?></strong></div>
        </div>
        <div class="stat stat-red">
          <div class="stat-icon"><?= icon('tag', 26) ?></div>
          <div><small>Empty Categories</small><strong><?= $emptyCats ?></strong></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('tag', 22) ?> Category List</h2>
          <div class="actions">
            <button type="button" id="openNew" class="btn-d primary"><?= icon('plus', 16) ?> Add Category</button>
            <a class="btn-d" href="categories.php?<?= e(http_build_query(array_merge($baseParams, ['export' => 1]))) ?>"><?= icon('download', 16) ?> Export CSV</a>
          </div>
        </div>

        <form method="get" class="filters two">
          <label class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" placeholder="Search category by name or description..." value="<?= e($q) ?>">
          </label>
          <select name="status" onchange="this.form.submit()" aria-label="Status">
            <option value="all">All Status</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </form>

        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr><th>#</th><th>Category Name</th><th>Description</th><th>Total Items</th><th>Status</th><th class="right">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="empty">No categories match your filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $active = $r['status'] === 'active';
            ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td>
                  <div class="item">
                    <span class="sup-badge <?= tag_class($r['name']) ?>"><?= icon('tag', 18) ?></span>
                    <strong><?= e($r['name']) ?></strong>
                  </div>
                </td>
                <td class="addr"><?= e($r['description']) ?></td>
                <td><?= (int)$r['items'] ?></td>
                <td><span class="pill <?= $active ? 'pill-in' : 'pill-out' ?>"><?= icon($active ? 'check' : 'x', 14) ?> <?= $active ? 'Active' : 'Inactive' ?></span></td>
                <td class="right">
                  <div class="row-actions">
                    <a class="icon-btn edit" title="Edit" href="<?= e(cat_url($baseParams, ['page' => $page, 'edit' => $r['id']])) ?>"><?= icon('edit', 16) ?></a>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this category?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
                      <button class="icon-btn del" title="Delete" type="submit"><?= icon('trash', 16) ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="foot">
          <span>Showing <?= $shownFrom ?> to <?= $shownTo ?> of <?= $matching ?> categories</span>
          <nav class="pager">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(cat_url($baseParams, ['page' => max(1, $page - 1)])) ?>" aria-label="Previous"><?= icon('left', 16) ?></a>
            <?php for ($n = 1; $n <= $pages; $n++): ?>
              <a class="<?= $n === $page ? 'current' : '' ?>" href="<?= e(cat_url($baseParams, ['page' => $n])) ?>"><?= $n ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(cat_url($baseParams, ['page' => min($pages, $page + 1)])) ?>" aria-label="Next"><?= icon('right', 16) ?></a>
          </nav>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- Add / edit dialog -->
<dialog id="catDialog" class="item-dialog" data-open="<?= $editRow ? 1 : 0 ?>">
  <form method="post" class="dialog-form">
    <h2><?= $editRow ? 'Edit category' : 'Add category' ?></h2>
    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
    <label>Category name
      <input name="name" required maxlength="50" value="<?= e($editRow['name'] ?? '') ?>">
    </label>
    <label>Description
      <input name="description" maxlength="255" value="<?= e($editRow['description'] ?? '') ?>">
    </label>
    <label>Status
      <select name="status">
        <option value="active" <?= ($editRow['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </label>
    <div class="dialog-actions">
      <a class="btn-d" href="<?= e('categories.php?' . http_build_query($baseParams)) ?>">Cancel</a>
      <button class="btn-d primary" type="submit">Save</button>
    </div>
  </form>
</dialog>

<script>
  (function () {
    var dlg = document.getElementById('catDialog');
    document.getElementById('openNew').addEventListener('click', function () { dlg.showModal(); });
    if (dlg.dataset.open === '1') dlg.showModal();
  })();
</script>
</body>
</html>
