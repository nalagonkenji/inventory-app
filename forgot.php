<?php
require __DIR__ . '/auth.php';

if (isset($_GET['restart'])) {
    unset($_SESSION['reset_user'], $_SESSION['reset_stage'], $_SESSION['reset_verified']);
    header('Location: forgot.php');
    exit;
}

$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        unset($_SESSION['reset_user'], $_SESSION['reset_verified']);
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
                $hash = hash('sha256', $code);

                $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $del->bind_param("i", $uid);
                $del->execute();

                $ins = $conn->prepare("INSERT INTO password_resets (user_id, code_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)");
                $ins->bind_param("is", $uid, $hash);
                $ins->execute();

                $body = "Hello " . $u['username'] . ",\n\n"
                      . "Your password reset code is: " . $code . "\n\n"
                      . "The code expires in 15 minutes. If you did not ask to reset your password, you can ignore this email.\n";
                @mail($u['email'], 'Inventory password reset code', $body, "Content-Type: text/plain; charset=UTF-8");

                $_SESSION['reset_user'] = $uid;
            }
            // Same answer whether or not the account exists, so usernames cannot be guessed.
            $_SESSION['reset_stage'] = 'code';
            $info = 'If an account matches, a code was sent to its email. The code expires in 15 minutes.';
        }
    } elseif ($action === 'verify') {
        $code = trim($_POST['code'] ?? '');
        $uid = (int)($_SESSION['reset_user'] ?? 0);
        $row = null;
        if ($uid) {
            $stmt = $conn->prepare("SELECT id, code_hash, attempts FROM password_resets WHERE user_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        }
        if (!$row || (int)$row['attempts'] >= 5) {
            $error = 'That code has expired or was used too many times. Send a new code.';
        } elseif (!hash_equals($row['code_hash'], hash('sha256', $code))) {
            $rid = (int)$row['id'];
            $inc = $conn->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?");
            $inc->bind_param("i", $rid);
            $inc->execute();
            $error = 'The code is incorrect.';
        } else {
            $_SESSION['reset_verified'] = true;
            header('Location: reset.php');
            exit;
        }
    }
}

$codeStage = ($_SESSION['reset_stage'] ?? '') === 'code';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Forgot Password | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .auth-form { margin-bottom: 22px; }
    .auth-form .field { margin-bottom: 16px; }
    .field { position: relative; }
    .field input { padding-right: 130px; }
    .send-inside { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); padding: 8px 14px; border: 0; border-radius: 8px; background: linear-gradient(90deg, #22d3ee, #2563eb); color: #fff; font-weight: 600; cursor: pointer; transition: opacity .25s ease; }
    .send-inside:hover { opacity: .85; }
    .otp { display: flex; justify-content: center; gap: 10px; margin: 4px 0 14px; }
    .otp-box { width: 48px; height: 56px; padding: 0; text-align: center; font-family: inherit; font-size: 22px; font-weight: 700; color: inherit; background: rgba(8, 20, 36, .6); border: 1px solid rgba(34, 211, 238, .45); border-radius: 12px; outline: none; transition: border-color .25s ease, box-shadow .25s ease; }
    .otp-box:focus { border-color: #22d3ee; box-shadow: 0 0 0 3px rgba(34, 211, 238, .25), 0 0 16px rgba(34, 211, 238, .35); }
    .otp-box:disabled { opacity: .45; cursor: not-allowed; }
    .code-hint { margin: 0 0 6px; font-size: 13px; opacity: .75; text-align: center; }
    .back-link { display: inline-block; color: inherit; text-decoration: none; transition: color .25s ease; }
    .back-link:hover { color: #67e8f9; }
  </style>
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
    <p class="subtitle">Enter your username or email. We'll send a 6-digit code to the email on your account.</p>
    <span class="accent-line"></span>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php elseif ($info): ?>
      <div class="alert alert-success"><?= e($info) ?></div>
    <?php endif; ?>

    <form method="post" class="auth-form">
      <input type="hidden" name="action" value="send">
      <div class="field">
        <span class="field-icon"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg></span>
        <input name="identifier" placeholder="Username or email" required value="<?= e($_POST['identifier'] ?? '') ?>">
        <button type="submit" class="send-inside">Send code</button>
      </div>
    </form>

    <form method="post" class="auth-form" id="verifyForm">
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="code" id="codeHidden">
      <div class="otp">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input class="otp-box" type="text" inputmode="numeric" maxlength="1" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="Code digit <?= $i + 1 ?>" <?= $codeStage ? '' : 'disabled' ?>>
        <?php endfor; ?>
      </div>
      <p class="code-hint">Type the code from your email. It checks automatically once all 6 digits are entered.</p>
    </form>

    <p class="auth-switch"><a class="back-link" href="login.php">&larr; Back to log in</a></p>
  </main>
  <script>
    (function () {
      var boxes = Array.prototype.slice.call(document.querySelectorAll('.otp-box'));
      if (!boxes.length || boxes[0].disabled) return;
      var form = document.getElementById('verifyForm');
      var hidden = document.getElementById('codeHidden');

      function collect() {
        var code = boxes.map(function (b) { return b.value; }).join('');
        hidden.value = code;
        if (/^\d{6}$/.test(code)) form.submit();
      }

      boxes.forEach(function (box, i) {
        box.addEventListener('input', function () {
          box.value = box.value.replace(/\D/g, '').slice(0, 1);
          if (box.value && i < 5) boxes[i + 1].focus();
          collect();
        });
        box.addEventListener('keydown', function (ev) {
          if (ev.key === 'Backspace' && !box.value && i > 0) {
            boxes[i - 1].value = '';
            boxes[i - 1].focus();
          }
        });
        box.addEventListener('paste', function (ev) {
          ev.preventDefault();
          var text = (ev.clipboardData || window.clipboardData).getData('text');
          var digits = text.replace(/\D/g, '').slice(0, 6);
          digits.split('').forEach(function (d, k) { boxes[k].value = d; });
          boxes[Math.min(digits.length, 5)].focus();
          collect();
        });
      });
      boxes[0].focus();
    })();
  </script>
</body>
</html>
