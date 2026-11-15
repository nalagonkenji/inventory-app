<?php
// Shared session and helper code. Included by every page.
session_start();
require_once __DIR__ . '/db.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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
