<?php
require __DIR__ . '/auth.php';
require_admin();
require __DIR__ . '/inventory_lib.php';

const USER_PER_PAGE = 10;
const USER_ROLES = ['admin', 'staff'];
const USER_STATUSES = ['active', 'pending', 'inactive'];

// ---------- Create / update / delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $returnQs = preg_replace('/[\r\n]/', '', $_POST['return_query'] ?? '');
    $self = (int)$_SESSION['user_id'];

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $self) {
            $_SESSION['flash'] = 'You cannot delete the account you are logged in with.';
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $_SESSION['flash'] = 'User deleted.';
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $full = substr(trim($_POST['full_name'] ?? ''), 0, 100);
        $email = substr(trim($_POST['email'] ?? ''), 0, 120);
        $role = in_array($_POST['role'] ?? '', USER_ROLES, true) ? $_POST['role'] : 'staff';
        $status = in_array($_POST['status'] ?? '', USER_STATUSES, true) ? $_POST['status'] : 'active';
        $password = $_POST['password'] ?? '';

        // Keep your own account usable: you cannot demote or deactivate yourself.
        if ($id === $self && $action === 'update') { $role = 'admin'; $status = 'active'; }

        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
            $_SESSION['flash'] = 'Username must be 3 to 30 letters, numbers, or underscores.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = 'Please enter a valid email address.';
        } elseif ($action === 'create' && strlen($password) < 8) {
            $_SESSION['flash'] = 'A new user needs a password of at least 8 characters.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $_SESSION['flash'] = 'A new password must be at least 8 characters.';
        } else {
            $dup = run_query($conn, "SELECT id FROM users WHERE username = ? AND id <> ?", "si", [$username, $id])->fetch_assoc();
            if ($dup) {
                $_SESSION['flash'] = 'That username is already taken.';
            } elseif ($action === 'update') {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, email=?, role=?, status=?, password_hash=? WHERE id=?");
                    $stmt->bind_param("ssssssi", $username, $full, $email, $role, $status, $hash, $id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username=?, full_name=?, email=?, role=?, status=? WHERE id=?");
                    $stmt->bind_param("sssssi", $username, $full, $email, $role, $status, $id);
                }
                $stmt->execute();
                $_SESSION['flash'] = 'User updated.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $username, $hash, $full, $email, $role, $status);
                $stmt->execute();
                $_SESSION['flash'] = 'User added.';
            }
        }
    }
    header('Location: users.php' . ($returnQs !== '' ? '?' . $returnQs : ''));
    exit;
}

// ---------- Filters ----------
$q = trim($_GET['q'] ?? '');
$role = in_array($_GET['role'] ?? 'all', array_merge(['all'], USER_ROLES), true) ? ($_GET['role'] ?? 'all') : 'all';
$status = in_array($_GET['status'] ?? 'all', array_merge(['all'], USER_STATUSES), true) ? ($_GET['status'] ?? 'all') : 'all';

$where = [];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $types .= 'sss';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
if ($role !== 'all') { $where[] = 'role = ?'; $types .= 's'; $params[] = $role; }
if ($status !== 'all') { $where[] = 'status = ?'; $types .= 's'; $params[] = $status; }
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseParams = array_filter(['q' => $q, 'role' => $role, 'status' => $status], fn($v) => $v !== '' && $v !== 'all');

// ---------- CSV export ----------
if (isset($_GET['export'])) {
    $rows = run_query($conn, "SELECT id, username, full_name, email, role, status, last_login FROM users $clause ORDER BY id", $types, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Username', 'Full Name', 'Email', 'Role', 'Status', 'Last Login']);
    while ($r = $rows->fetch_assoc()) {
        fputcsv($out, [$r['id'], $r['username'], $r['full_name'], $r['email'], $r['role'], $r['status'], $r['last_login']]);
    }
    fclose($out);
    exit;
}

// ---------- Data ----------
$totals = run_query($conn, "SELECT COUNT(*) AS total,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
        SUM(CASE WHEN role = 'staff' THEN 1 ELSE 0 END) AS staff
    FROM users")->fetch_assoc();

$countRow = run_query($conn, "SELECT COUNT(*) AS c FROM users $clause", $types, $params)->fetch_assoc();
$matching = (int)$countRow['c'];
$pages = max(1, (int)ceil($matching / USER_PER_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * USER_PER_PAGE;

$rows = run_query($conn, "SELECT * FROM users $clause ORDER BY id LIMIT " . (int)USER_PER_PAGE . " OFFSET " . (int)$offset, $types, $params)->fetch_all(MYSQLI_ASSOC);

$editRow = null;
if (isset($_GET['edit'])) {
    $editRow = run_query($conn, "SELECT * FROM users WHERE id = ?", "i", [(int)$_GET['edit']])->fetch_assoc();
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$returnQs = http_build_query(array_merge($baseParams, ['page' => $page]));
$shownFrom = $matching ? $offset + 1 : 0;
$shownTo = min($offset + USER_PER_PAGE, $matching);
$selfId = (int)$_SESSION['user_id'];

function user_url(array $base, array $extra): string {
    return 'users.php?' . http_build_query(array_merge($base, $extra));
}
function user_initials(string $name): string {
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
  <title>Users | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('users'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('users', 26) ?><h1>Users</h1></div>
        <p>Manage system users, roles, and access permissions.</p>
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
        <div class="stat stat-blue">
          <div class="stat-icon"><?= icon('users', 26) ?></div>
          <div><small>Total Users</small><strong><?= (int)$totals['total'] ?></strong></div>
        </div>
        <div class="stat stat-green">
          <div class="stat-icon"><?= icon('star', 26) ?></div>
          <div><small>Administrators</small><strong><?= (int)$totals['admins'] ?></strong></div>
        </div>
        <div class="stat stat-violet">
          <div class="stat-icon"><?= icon('users', 26) ?></div>
          <div><small>Staff Users</small><strong><?= (int)$totals['staff'] ?></strong></div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-head">
          <h2><?= icon('users', 22) ?> User List</h2>
          <div class="actions">
            <button type="button" id="openNew" class="btn-d primary"><?= icon('plus', 16) ?> Add User</button>
            <a class="btn-d" href="users.php?<?= e(http_build_query(array_merge($baseParams, ['export' => 1]))) ?>"><?= icon('download', 16) ?> Export CSV</a>
          </div>
        </div>

        <form method="get" class="filters three">
          <label class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" placeholder="Search user by name, username, or email..." value="<?= e($q) ?>">
          </label>
          <select name="role" onchange="this.form.submit()" aria-label="Role">
            <option value="all">All Roles</option>
            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrator</option>
            <option value="staff" <?= $role === 'staff' ? 'selected' : '' ?>>Staff</option>
          </select>
          <select name="status" onchange="this.form.submit()" aria-label="Status">
            <option value="all">All Status</option>
            <?php foreach (USER_STATUSES as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <div class="table-wrap">
          <table class="tbl">
            <thead>
              <tr><th>#</th><th>User</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th class="right">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="8" class="empty">No users match your filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $isAdmin = $r['role'] === 'admin';
                [$spill, $sclass, $sicon] = match ($r['status']) {
                    'active'  => ['Active', 'pill-in', 'check'],
                    'pending' => ['Pending', 'pill-low', 'alert'],
                    default   => ['Inactive', 'pill-out', 'x'],
                };
                $display = $r['full_name'] !== '' ? $r['full_name'] : $r['username'];
            ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td>
                  <div class="item">
                    <span class="sup-badge <?= tag_class($display) ?>"><?= e(user_initials($display)) ?></span>
                    <strong><?= e($display) ?></strong>
                  </div>
                </td>
                <td><?= e($r['username']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><span class="pill <?= $isAdmin ? 'pill-violet' : 'pill-blue' ?>"><?= icon($isAdmin ? 'star' : 'users', 14) ?> <?= $isAdmin ? 'Administrator' : 'Staff' ?></span></td>
                <td><span class="pill <?= $sclass ?>"><?= icon($sicon, 14) ?> <?= $spill ?></span></td>
                <td><?= $r['last_login'] ? e(fmt_datetime($r['last_login'])) : '-' ?></td>
                <td class="right">
                  <div class="row-actions">
                    <a class="icon-btn edit" title="Edit" href="<?= e(user_url($baseParams, ['page' => $page, 'edit' => $r['id']])) ?>"><?= icon('edit', 16) ?></a>
                    <?php if ((int)$r['id'] !== $selfId): ?>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this user?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
                      <button class="icon-btn del" title="Delete" type="submit"><?= icon('trash', 16) ?></button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="foot">
          <span>Showing <?= $shownFrom ?> to <?= $shownTo ?> of <?= $matching ?> users</span>
          <nav class="pager">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(user_url($baseParams, ['page' => max(1, $page - 1)])) ?>" aria-label="Previous"><?= icon('left', 16) ?></a>
            <?php for ($n = 1; $n <= $pages; $n++): ?>
              <a class="<?= $n === $page ? 'current' : '' ?>" href="<?= e(user_url($baseParams, ['page' => $n])) ?>"><?= $n ?></a>
            <?php endfor; ?>
            <a class="<?= $page >= $pages ? 'disabled' : '' ?>" href="<?= e(user_url($baseParams, ['page' => min($pages, $page + 1)])) ?>" aria-label="Next"><?= icon('right', 16) ?></a>
          </nav>
        </div>
      </section>
    </main>
  </div>
</div>

<!-- Add / edit dialog -->
<dialog id="userDialog" class="item-dialog wide" data-open="<?= $editRow ? 1 : 0 ?>">
  <form method="post" class="dialog-form">
    <h2><?= $editRow ? 'Edit user' : 'Add user' ?></h2>
    <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
    <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <input type="hidden" name="return_query" value="<?= e($returnQs) ?>">
    <div class="form-2col">
      <label>Full name
        <input name="full_name" maxlength="100" value="<?= e($editRow['full_name'] ?? '') ?>">
      </label>
      <label>Username
        <input name="username" required minlength="3" maxlength="30" value="<?= e($editRow['username'] ?? '') ?>">
      </label>
      <label>Email
        <input name="email" type="email" maxlength="120" value="<?= e($editRow['email'] ?? '') ?>">
      </label>
      <label><?= $editRow ? 'New password (leave blank to keep)' : 'Password' ?>
        <input name="password" type="password" minlength="<?= $editRow ? 0 : 8 ?>" <?= $editRow ? '' : 'required' ?>>
      </label>
      <label>Role
        <select name="role">
          <option value="staff" <?= ($editRow['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Staff</option>
          <option value="admin" <?= ($editRow['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrator</option>
        </select>
      </label>
      <label>Status
        <select name="status">
          <?php foreach (USER_STATUSES as $s): ?>
            <option value="<?= $s ?>" <?= ($editRow['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="dialog-actions">
      <a class="btn-d" href="<?= e('users.php?' . http_build_query($baseParams)) ?>">Cancel</a>
      <button class="btn-d primary" type="submit">Save</button>
    </div>
  </form>
</dialog>

<script>
  (function () {
    var dlg = document.getElementById('userDialog');
    document.getElementById('openNew').addEventListener('click', function () { dlg.showModal(); });
    if (dlg.dataset.open === '1') dlg.showModal();
  })();
</script>
</body>
</html>
