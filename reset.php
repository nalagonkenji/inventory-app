<?php
require __DIR__ . '/auth.php';

$uid = (int)($_SESSION['reset_user'] ?? 0);
if (!$uid || empty($_SESSION['reset_verified'])) {
    header('Location: forgot.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $upd->bind_param("si", $hash, $uid);
        $upd->execute();
        $d1 = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $d1->bind_param("i", $uid);
        $d1->execute();
        $d2 = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $d2->bind_param("i", $uid);
        $d2->execute();
        unset($_SESSION['reset_user'], $_SESSION['reset_stage'], $_SESSION['reset_verified']);
        header('Location: login.php?reset=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>New Password | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .back-link { display: inline-block; margin-bottom: 10px; color: inherit; opacity: .85; text-decoration: none; transition: color .25s ease, opacity .25s ease; }
    .back-link:hover { color: #67e8f9; opacity: 1; }
  </style>
</head>
<body class="auth-page">
  <video class="bg-video" autoplay muted loop playsinline src="login-bg.mp4"></video>
  <main class="auth-card">
    <a href="forgot.php" class="back-link">&larr; Back</a>
    <div class="brand-logo" aria-hidden="true">
      <svg viewBox="0 0 64 64" width="64" height="64" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round">
        <path d="M32 6 56 19v26L32 58 8 45V19z"/>
        <path d="M8 19l24 13 24-13M32 32v26"/>
      </svg>
    </div>
    <h1>Set new password</h1>
    <p class="subtitle">Your code was verified. Choose a new password.</p>
    <span class="accent-line"></span>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
      <label class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
        <input type="password" name="password" placeholder="New password (at least 8 characters)" required minlength="8">
      </label>
      <label class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
        <input type="password" name="confirm" placeholder="Confirm new password" required minlength="8">
      </label>
      <button type="submit" class="btn btn-block">Set new password</button>
    </form>
  </main>
</body>
</html>
