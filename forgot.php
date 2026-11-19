<?php
require __DIR__ . '/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = trim($_POST['identifier'] ?? '');
    if ($ident === '') {
        $error = 'Enter your username or email.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE (username = ? OR email = ?) AND email <> '' AND status = 'active' LIMIT 1");
        $stmt->bind_param("ss", $ident, $ident);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        if ($u) {
            $uid = (int)$u['id'];
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires = date('Y-m-d H:i:s', time() + 15 * 60);
            $hash = hash('sha256', $code);

            $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $del->bind_param("i", $uid);
            $del->execute();

            $ins = $conn->prepare("INSERT INTO password_resets (user_id, code_hash, expires_at) VALUES (?, ?, ?)");
            $ins->bind_param("iss", $uid, $hash, $expires);
            $ins->execute();

            $body = "Hello " . $u['username'] . ",\n\n"
                  . "Your password reset code is: " . $code . "\n\n"
                  . "The code expires in 15 minutes. If you did not ask to reset your password, you can ignore this email.\n";
            @mail($u['email'], 'Inventory password reset code', $body, "Content-Type: text/plain; charset=UTF-8");

            $_SESSION['reset_user'] = $uid;
        }
        // Same answer whether or not the account exists, so usernames cannot be guessed.
        header('Location: reset.php?sent=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password | Computer Parts Inventory</title>
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
    <h1>Forgot password</h1>
    <p class="subtitle">Enter your username or the email on your account. We'll send a 6-digit code.</p>
    <span class="accent-line"></span>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
      <label class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg></span>
        <input name="identifier" placeholder="Username or email" required value="<?= e($_POST['identifier'] ?? '') ?>">
      </label>
      <button type="submit" class="btn btn-block">Send code</button>
    </form>

    <p class="auth-switch"><a href="login.php">Back to log in</a></p>
  </main>
</body>
</html>
