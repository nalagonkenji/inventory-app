<?php
require __DIR__ . '/auth.php';

$error = '';
$uid = (int)($_SESSION['reset_user'] ?? 0);
$sent = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $row = null;
    if ($uid) {
        $stmt = $conn->prepare("SELECT id, code_hash, attempts FROM password_resets WHERE user_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    }

    if (!$row || (int)$row['attempts'] >= 5) {
        $error = 'That code has expired or was used too many times. Request a new code.';
    } elseif (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $rid = (int)$row['id'];
        $inc = $conn->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?");
        $inc->bind_param("i", $rid);
        $inc->execute();
        $error = 'The code is incorrect.';
    } elseif (strlen($password) < 8) {
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
        unset($_SESSION['reset_user']);
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
  <title>Reset Password | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
  <video class="bg-video" autoplay muted loop playsinline src="login-bg.mp4"></video>
  <main class="auth-card">
    <div class="brand-logo" aria-hidden="true">
      <svg viewBox="0 0 64 64" width="64" height="64" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round">
        <path d="M32 6 56 19v26L32 58 8 45V19z"/>
        <path d="M8 19l24 13 24-13M32 32v26"/>
      </svg>
    </div>
    <h1>Reset password</h1>
    <p class="subtitle">Enter the 6-digit code from your email and choose a new password.</p>
    <span class="accent-line"></span>

    <?php if ($sent && !$error): ?>
      <div class="alert alert-success">If an account matches, a code was sent to its email. The code expires in 15 minutes.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
      <label class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4zM8 9h8M8 13h5"/></svg></span>
        <input name="code" placeholder="6-digit code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6">
      </label>
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

    <p class="auth-switch"><a href="forgot.php">Didn't get a code? Try again</a></p>
  </main>
</body>
</html>
