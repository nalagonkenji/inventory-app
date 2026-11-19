<?php
require __DIR__ . '/auth.php';

// Forget the "remember me" login on this device and in the database.
if (!empty($_COOKIE['remember'])) {
    $selector = explode(':', $_COOKIE['remember'], 2)[0];
    try {
        $del = $conn->prepare("DELETE FROM remember_tokens WHERE selector = ?");
        $del->bind_param("s", $selector);
        $del->execute();
    } catch (Throwable $t) {
        // table missing: nothing to remove
    }
    setcookie('remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
