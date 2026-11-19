<?php
require __DIR__ . '/auth.php';

const LOGIN_MAX_FAILS = 5;   // failed tries allowed...
const LOGIN_WINDOW = 15;     // ...within this many minutes

$error = '';
$notice = isset($_GET['registered']) ? 'Account created. You can log in now.' : (isset($_GET['reset']) ? 'Password updated. You can log in now.' : '');
$ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $lk = $conn->prepare("SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL " . LOGIN_WINDOW . " MINUTE");
    $lk->bind_param("s", $ip);
    $lk->execute();
    $fails = (int)$lk->get_result()->fetch_assoc()['c'];

    if ($fails >= LOGIN_MAX_FAILS) {
        $error = 'Too many failed log in attempts. Wait ' . LOGIN_WINDOW . ' minutes and try again.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash, role, status FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (($user['status'] ?? 'active') !== 'active') {
                $error = 'This account is not active. Ask an administrator.';
            } else {
                $clear = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
                $clear->bind_param("s", $ip);
                $clear->execute();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'] ?? 'staff';

                if (!empty($_POST['remember'])) {
                    $selector = bin2hex(random_bytes(9));
                    $validator = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $validator);
                    $rt = $conn->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 30 DAY)");
                    $rt->bind_param("iss", $user['id'], $selector, $tokenHash);
                    $rt->execute();
                    setcookie('remember', $selector . ':' . $validator, [
                        'expires' => time() + 60 * 60 * 24 * 30,
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                $touch = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $touch->bind_param("i", $user['id']);
                $touch->execute();
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $name = substr($username, 0, 50);
            $rec = $conn->prepare("INSERT INTO login_attempts (ip, username) VALUES (?, ?)");
            $rec->bind_param("ss", $ip, $name);
            $rec->execute();
        }
        if ($error === '') $error = 'Invalid username or password.';
    }
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
  <video class="bg-video" autoplay muted loop playsinline src="login-bg.mp4"></video>
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
        <a href="forgot.php" class="forgot">Forgot password?</a>
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
  <?php if ($notice): ?>
  <style>
    .notice-toast{position:fixed;top:20px;right:20px;z-index:1000;max-width:90vw;background:rgba(22,101,52,.95);color:#dcfce7;border:1px solid #22c55e;padding:14px 18px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.35);font-weight:600;animation:notice-in .4s ease-out both,notice-out .4s ease-in 1.1s both}
    @keyframes notice-in{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
    @keyframes notice-out{from{transform:translateX(0);opacity:1}to{transform:translateX(120%);opacity:0}}
  </style>
  <div class="notice-toast" id="noticeToast" role="status"><?= e($notice) ?></div>
  <script>
    setTimeout(function () {
      var t = document.getElementById('noticeToast');
      if (t) t.remove();
    }, 1500);
  </script>
  <?php endif; ?>
</body>
</html>
