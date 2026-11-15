<?php
require __DIR__ . '/auth.php';

$error = '';
$notice = isset($_GET['registered']) ? 'Account created. You can log in now.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log In | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
  <main class="auth-card">
    <div class="brand-logo" aria-hidden="true">
      <svg viewBox="0 0 64 64" width="64" height="64" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round">
        <path d="M32 6 56 19v26L32 58 8 45V19z"/>
        <path d="M8 19l24 13 24-13M32 32v26"/>
      </svg>
    </div>
    <h1>Computer Parts<br>Inventory Management</h1>
    <p class="subtitle">Sign in to manage stock, suppliers, and parts.</p>
    <span class="accent-line"></span>

    <?php if ($notice): ?>
      <div class="alert alert-success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
      <label class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg></span>
        <input name="username" placeholder="Username" required autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
      </label>
      <label class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span>
        <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
        <button type="button" class="toggle-pass" aria-label="Show password"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
      </label>
      <div class="form-row">
        <label class="remember"><input type="checkbox" name="remember"> Remember me</label>
        <a href="#" class="forgot" onclick="return false;" title="Contact the administrator to reset your password">Forgot password?</a>
      </div>
      <button type="submit" class="btn btn-block">Log In</button>
    </form>

    <p class="auth-switch">No account yet? <a href="register.php">Create one</a></p>
  </main>
  <script>
    document.querySelectorAll('.toggle-pass').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = btn.parentElement.querySelector('input');
        input.type = input.type === 'password' ? 'text' : 'password';
      });
    });
  </script>
</body>
</html>
