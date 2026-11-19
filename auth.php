<?php
// Shared session and helper code. Included by every page.
session_start();
require_once __DIR__ . '/db.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Logs the user in again from a valid "remember me" cookie.
function try_remember_login(mysqli $conn): void {
    if (!empty($_SESSION['user_id']) || empty($_COOKIE['remember'])) return;
    $parts = explode(':', $_COOKIE['remember'], 2);
    if (count($parts) !== 2) return;
    [$selector, $validator] = $parts;
    try {
        $stmt = $conn->prepare("SELECT t.user_id, t.token_hash, u.username, u.role, u.status FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? AND t.expires_at > NOW()");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $t) {
        return;
    }
    if (!$row || ($row['status'] ?? '') !== 'active' || !hash_equals($row['token_hash'], hash('sha256', $validator))) return;
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['user_id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['role'] = $row['role'] ?? 'staff';
}

try_remember_login($conn);

function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// Only administrators may manage users.
function require_admin() {
    require_login();
    if (($_SESSION['role'] ?? 'staff') !== 'admin') {
        http_response_code(403);
        exit('Only administrators can open this page.');
    }
}
