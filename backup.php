<?php
require __DIR__ . '/auth.php';
require_admin();

// ---------- Download a full backup (.sql) ----------
if (isset($_GET['download'])) {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory-backup-' . date('Ymd-His') . '.sql"');
    echo "-- Computer Parts Inventory backup, created " . date('Y-m-d H:i:s') . "\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM);
    foreach ($tables as [$table]) {
        $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
        echo "DROP TABLE IF EXISTS `$table`;\n" . $create . ";\n\n";
        $res = $conn->query("SELECT * FROM `$table`", MYSQLI_USE_RESULT);
        while ($row = $res->fetch_row()) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string((string)$v) . "'", $row);
            echo "INSERT INTO `$table` VALUES (" . implode(', ', $vals) . ");\n";
        }
        $res->free();
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

// ---------- Restore from an uploaded .sql backup ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['dump'] ?? null;
    $flash = ['type' => 'error', 'msg' => 'Choose a .sql backup file to restore.'];
    if ($file && $file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'sql') {
        if ($file['size'] > 50 * 1024 * 1024) {
            $flash = ['type' => 'error', 'msg' => 'That backup file is too large (50 MB limit).'];
        } else {
            $sql = file_get_contents($file['tmp_name']);
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            $ok = $conn->multi_query($sql);
            $err = $ok ? '' : $conn->error;
            if ($ok) {
                do {
                    if ($res = $conn->store_result()) $res->free();
                    if (!$conn->more_results()) break;
                    if (!$conn->next_result()) {
                        $err = $conn->error ?: 'A statement in the backup failed.';
                        break;
                    }
                } while (true);
            }
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            $flash = $err === '' ? ['type' => 'success', 'msg' => 'Backup restored.'] : ['type' => 'error', 'msg' => 'Restore stopped: ' . $err];
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: settings.php?tab=backup');
    exit;
}

header('Location: settings.php?tab=backup');
exit;
