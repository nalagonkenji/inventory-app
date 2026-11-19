<?php
// Shared helpers for the inventory pages.
const LOW_STOCK = 6;   // quantity 1 to 6 = Low Stock, 0 = Out of Stock, 7+ = In Stock
const PER_PAGE = 8;

function stock_status(int $qty): array {
    if ($qty <= 0) return ['Out of Stock', 'out'];
    if ($qty <= LOW_STOCK) return ['Low Stock', 'low'];
    return ['In Stock', 'in'];
}

function tag_class(string $category): string {
    $palette = ['cyan', 'violet', 'amber', 'pink', 'green', 'blue', 'red', 'orange'];
    return 'tag-' . $palette[abs(crc32(strtolower($category))) % count($palette)];
}

// Builds the WHERE clause from ?q=, ?cat=, ?status= (used by the table and CSV export).
function build_filters(array $src): array {
    $where = [];
    $types = '';
    $params = [];
    $q = trim($src['q'] ?? '');
    $cat = trim($src['cat'] ?? '');
    $status = $src['status'] ?? 'all';

    if ($q !== '') { $where[] = 'name LIKE ?'; $types .= 's'; $params[] = '%' . $q . '%'; }
    if ($cat !== '') { $where[] = 'category = ?'; $types .= 's'; $params[] = $cat; }
    if ($status === 'out') $where[] = 'quantity = 0';
    elseif ($status === 'low') $where[] = 'quantity BETWEEN 1 AND ' . LOW_STOCK;
    elseif ($status === 'in') $where[] = 'quantity > ' . LOW_STOCK;

    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $filters = ['q' => $q, 'cat' => $cat, 'status' => in_array($status, ['all','in','low','out'], true) ? $status : 'all'];
    return [$clause, $types, $params, $filters];
}

function run_query(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $k => $v) { $refs[] = &$params[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    return $stmt->get_result();
}

// Writes one row to audit_log. Never breaks the page if the table is missing.
function audit_log(mysqli $conn, string $action, string $entity, int $entityId, string $detail): void {
    try {
        $user = $_SESSION['username'] ?? 'system';
        $detail = substr($detail, 0, 255);
        $stmt = $conn->prepare("INSERT INTO audit_log (username, action, entity, entity_id, detail) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssis", $user, $action, $entity, $entityId, $detail);
        $stmt->execute();
    } catch (Throwable $e) {
        // logging is best-effort
    }
}

// Feather-style line icons (MIT licensed paths).
function icon(string $name, int $size = 18): string {
    static $paths = [
        'arrowup'  => 'M12 19V5M5 12l7-7 7 7',
        'arrowdown'=> 'M12 5v14M19 12l-7 7-7-7',
        'adjust'   => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
        'star'     => 'M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z',
        'home'     => 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
        'monitor'  => 'M2 4h20v12H2zM8 20h8M12 16v4',
        'box'      => 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
        'layers'   => 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
        'truck'    => 'M1 6h13v10H1zM14 10h4l3 3v3h-7zM5.5 18.5a1.5 1.5 0 1 0 0 .01M17.5 18.5a1.5 1.5 0 1 0 0 .01',
        'transfer' => 'M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4',
        'chart'    => 'M3 3v18h18M7 15l4-4 3 3 5-6',
        'tag'      => 'M3 12V3h9l9 9-9 9zM7.5 7.5h.01',
        'users'    => 'M16 19v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 19v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
        'settings' => 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
        'bell'     => 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
        'search'   => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
        'plus'     => 'M12 5v14M5 12h14',
        'download' => 'M12 3v12M7 10l5 5 5-5M5 21h14',
        'edit'     => 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
        'trash'    => 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14',
        'check'    => 'M5 13l4 4L19 7',
        'alert'    => 'M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
        'x'        => 'M18 6L6 18M6 6l12 12',
        'logout'   => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
        'menu'     => 'M3 6h18M3 12h18M3 18h18',
        'left'     => 'M15 18l-6-6 6-6',
        'right'    => 'M9 18l6-6-6-6',
        'down'     => 'M6 9l6 6 6-6',
    ];
    $d = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}


function nav_link(string $active, string $key, string $href, string $label, string $icon): string {
    $cls = $active === $key ? 'nav-item active' : 'nav-item';
    return '<a class="' . $cls . '" href="' . $href . '">' . icon($icon) . ' ' . $label . '</a>';
}

function nav_soon(string $label, string $icon): string {
    return '<span class="nav-item soon" title="Coming soon">' . icon($icon) . ' ' . $label . '</span>';
}

// Reads one saved setting (cached per page load). Falls back to $default if missing.
function app_setting(string $key, string $default = ''): string {
    static $cache = null;
    global $conn;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (run_query($conn, "SELECT setting_key, setting_value FROM settings")->fetch_all(MYSQLI_ASSOC) as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $t) {
            // settings table not created yet: use defaults
        }
    }
    return $cache[$key] ?? $default;
}

function currency_symbol(): string {
    return ['PHP' => '₱', 'USD' => '$', 'EUR' => '€'][app_setting('currency', 'PHP')] ?? '₱';
}

function money(float $amount): string {
    return currency_symbol() . number_format($amount, 2);
}

function fmt_datetime(string $value): string {
    $date = app_setting('date_format', 'M d, Y');
    $time = app_setting('time_format', '12') === '24' ? 'H:i' : 'g:i A';
    return date($date . ' ' . $time, strtotime($value));
}

// Count of items needing attention, based on which notification toggles are on.
function notification_total(): int {
    global $conn;
    $total = 0;
    if (app_setting('notif_low_stock', '1') === '1') {
        $total += (int)run_query($conn, "SELECT COUNT(*) AS c FROM parts WHERE quantity <= ?", "i", [LOW_STOCK])->fetch_assoc()['c'];
    }
    if (app_setting('notif_inventory', '1') === '1') {
        $total += (int)run_query($conn, "SELECT COUNT(*) AS c FROM transactions WHERE type = 'in' AND created_at >= NOW() - INTERVAL 7 DAY")->fetch_assoc()['c'];
    }
    if (app_setting('notif_transactions', '1') === '1') {
        $total += (int)run_query($conn, "SELECT COUNT(*) AS c FROM transactions WHERE type = 'out' AND created_at >= NOW() - INTERVAL 7 DAY")->fetch_assoc()['c'];
    }
    return $total;
}

// Bell panel: a section builder, then one list builder per section.
function notif_section(string $title, string $key, string $default, callable $build): string {
    $body = app_setting($key, $default) === '1' ? $build() : '<li class="n-empty">Turned off in Settings.</li>';
    return '<div class="n-title">' . e($title) . '</div><ul class="n-list">' . $body . '</ul>';
}

function notif_low_list(): string {
    global $conn;
    $rows = run_query($conn, "SELECT name, quantity FROM parts WHERE quantity <= ? ORDER BY quantity, name LIMIT 20", "i", [LOW_STOCK])->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return '<li class="n-empty">Nothing is low on stock.</li>';
    $html = '';
    foreach ($rows as $r) {
        $html .= '<li><span>' . e($r['name']) . '</span><b>' . (int)$r['quantity'] . ' left</b></li>';
    }
    return $html;
}

function notif_movement_list(string $type): string {
    global $conn;
    $rows = run_query($conn, "SELECT t.quantity, t.performed_by, t.created_at, p.name FROM transactions t JOIN parts p ON p.id = t.part_id WHERE t.type = ? AND t.created_at >= NOW() - INTERVAL 7 DAY ORDER BY t.created_at DESC LIMIT 20", "s", [$type])->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return '<li class="n-empty">None in the last 7 days.</li>';
    $html = '';
    foreach ($rows as $r) {
        $qty = $type === 'in' ? '+' . (int)$r['quantity'] : '-' . abs((int)$r['quantity']);
        $html .= '<li><span>' . e($r['name']) . '<small>' . e($r['performed_by']) . ' &middot; ' . e(fmt_datetime($r['created_at'])) . '</small></span><b>' . $qty . '</b></li>';
    }
    return $html;
}

function notif_stock_in_list(): string { return notif_movement_list('in'); }
function notif_stock_out_list(): string { return notif_movement_list('out'); }

function notif_announce_list(): string {
    global $conn;
    try {
        $rows = run_query($conn, "SELECT message, posted_by, created_at FROM announcements ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $t) {
        return '<li class="n-empty">Announcements are not set up yet.</li>';
    }
    if (!$rows) return '<li class="n-empty">No announcements yet.</li>';
    $html = '';
    foreach ($rows as $r) {
        $html .= '<li><span>' . e($r['message']) . '<small>' . e($r['posted_by']) . ' &middot; ' . e(fmt_datetime($r['created_at'])) . '</small></span></li>';
    }
    return $html;
}

// Header bell: click opens a dropdown panel over the page (no separate page).
function bell_link(): string {
    $n = notification_total();
    $dot = $n > 0 ? '<span class="dot">' . $n . '</span>' : '';
    $panel = notif_section('Low Stock Alerts', 'notif_low_stock', '1', 'notif_low_list')
        . notif_section('Stock In (last 7 days)', 'notif_inventory', '1', 'notif_stock_in_list')
        . notif_section('Stock Out (last 7 days)', 'notif_transactions', '1', 'notif_stock_out_list')
        . notif_section('System Announcements', 'notif_announcements', '0', 'notif_announce_list');
    // Clicking the bell marks the current count as seen, so the red number goes away.
    $js = <<<'JS'
<script>
document.querySelectorAll('.notif-menu').forEach(function (m) {
  var s = m.querySelector('summary.bell');
  var count = parseInt(s.getAttribute('data-count'), 10) || 0;
  var seen = 0;
  try { seen = parseInt(localStorage.getItem('notifSeen'), 10) || 0; } catch (e) {}
  if (count <= seen) { var d = s.querySelector('.dot'); if (d) d.remove(); }
  m.addEventListener('toggle', function () {
    if (!m.open) return;
    try { localStorage.setItem('notifSeen', String(count)); } catch (e) {}
    var d = s.querySelector('.dot'); if (d) d.remove();
  });
});
</script>
JS;
    return '<details class="notif-menu">'
        . '<summary class="bell" data-count="' . $n . '" title="Notifications" style="list-style:none;cursor:pointer;color:inherit">' . icon('bell', 22) . $dot . '</summary>'
        . '<div class="notif-panel"><div class="notif-head">Notifications</div>' . $panel . '</div>'
        . '</details>' . $js
        . '<style>'
        . '.notif-menu{position:relative;display:inline-block}'
        . '.notif-menu summary::-webkit-details-marker{display:none}'
        . '.notif-panel{position:absolute;right:0;top:calc(100% + 10px);width:360px;max-width:90vw;max-height:70vh;overflow:auto;background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:14px;box-shadow:0 20px 40px rgba(0,0,0,.4);z-index:60;text-align:left;font-size:14px}'
        . '.notif-head{padding:14px 16px;font-weight:700;border-bottom:1px solid #334155}'
        . '.n-title{padding:10px 16px 4px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8}'
        . '.n-list{list-style:none;margin:0;padding:0 8px 8px}'
        . '.n-list li{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px;border-radius:8px}'
        . '.n-list li:hover{background:rgba(59,130,246,.08)}'
        . '.n-list li span{display:flex;flex-direction:column}'
        . '.n-list li small{color:#94a3b8;font-size:12px}'
        . '.n-list li b{white-space:nowrap}'
        . '.n-empty{color:#94a3b8}'
        . 'body.theme-light .notif-panel{background:#fff;color:#0f172a;border-color:#e2e8f0}'
        . 'body.theme-light .notif-head{border-color:#e2e8f0}'
        . '</style>';
}

// Shared sidebar used by every page. $active = 'dashboard' or 'inventory'.
function app_theme(): string {
    global $conn;
    try {
        $row = run_query($conn, "SELECT setting_value FROM settings WHERE setting_key = 'theme'")->fetch_assoc();
    } catch (Throwable $t) {
        return 'dark';
    }
    $v = $row['setting_value'] ?? 'dark';
    return in_array($v, ['dark', 'light', 'system'], true) ? $v : 'dark';
}

function render_sidebar(string $active): void {
    $theme = json_encode(app_theme());
    echo '<script>(function(){var t=' . $theme . ';if(t==="system"){t=matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";}document.body.classList.toggle("theme-light",t==="light");})();</script>';
    echo '<aside class="sidebar">'
        . '<div class="brand"><div class="brand-logo">' . icon('box', 52) . '</div>'
        . '<h2>' . e(app_setting('system_name', 'Computer Parts Inventory Management')) . '</h2></div>'
        . '<div class="nav-label">Menu</div>'
        . '<nav class="nav">'
        . nav_link($active, 'dashboard', 'dashboard.php', 'Dashboard', 'home')
        . nav_link($active, 'inventory', 'index.php', 'Inventory', 'layers')
        . nav_link($active, 'transactions', 'transactions.php', 'Transactions', 'transfer')
        . nav_link($active, 'suppliers', 'suppliers.php', 'Suppliers', 'truck')
        . nav_link($active, 'reports', 'reports.php', 'Reports', 'chart')
        . '</nav>'
        . '<div class="nav-label">Management</div>'
        . '<nav class="nav">'
        . nav_link($active, 'categories', 'categories.php', 'Categories', 'tag')
        . nav_link($active, 'users', 'users.php', 'Users', 'users')
        . nav_link($active, 'settings', 'settings.php', 'Settings', 'settings')
        . '</nav>'
        . '<nav class="nav nav-bottom"><a class="logout-btn" href="logout.php">' . icon('logout') . ' Log Out</a></nav>'
        . '</aside>';
}
