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

// Feather-style line icons (MIT licensed paths).
function icon(string $name, int $size = 18): string {
    static $paths = [
        'home'     => 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
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
