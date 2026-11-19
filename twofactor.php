<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/inventory_lib.php';

const TWOFA_MAX_TRIES = 5;

$pending = $_SESSION['2fa_pending'] ?? null;
if (!$pending) {
    header('Location: login.php');
    exit;
}
$uid = (int)$pending['uid'];

if (isset($_GET['cancel'])) {
    unset($_SESSION['2fa_pending']);
    header('Location: login.php');
    exit;
}

if (isset($_GET['resend'])) {
    $u = run_query($conn, "SELECT id, username, email, status FROM users WHERE id = ?", "i", [$uid])->fetch_assoc();
    if ($u && $u['status'] === 'active' && trim((string)$u['email']) !== '') {
        send_login_code($conn, $u);
    }
    header('Location: twofactor.php?sent=1');
    exit;
}

$error = '';
$info = isset($_GET['sent']) ? 'A new code was sent to your email.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
    $row = run_query($conn, "SELECT id, code_hash, attempts FROM two_factor_codes WHERE user_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1", "i", [$uid])->fetch_assoc();

    if (!$row || (int)$row['attempts'] >= TWOFA_MAX_TRIES) {
        $error = 'This code expired or was used too many times. Send a new code.';
    } elseif (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        $rid = (int)$row['id'];
        run_query($conn, "UPDATE two_factor_codes SET attempts = attempts + 1 WHERE id = ?", "i", [$rid]);
        $error = 'The code is incorrect.';
    } else {
        $u = run_query($conn, "SELECT id, username, role, status FROM users WHERE id = ?", "i", [$uid])->fetch_assoc();
        unset($_SESSION['2fa_pending']);
        run_query($conn, "DELETE FROM two_factor_codes WHERE user_id = ?", "i", [$uid]);
        if ($u && $u['status'] === 'active') {
            complete_login($conn, $u, (bool)$pending['remember']);
        }
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verify Sign-in | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .otp { display: flex; justify-content: center; gap: 10px; margin: 6px 0 16px; }
    .otp-box { width: 48px; height: 56px; padding: 0; text-align: center; font-family: inherit; font-size: 22px; font-weight: 700; color: inherit; background: rgba(8, 20, 36, .6); border: 1px solid rgba(34, 211, 238, .45); border-radius: 12px; outline: none; transition: border-color .25s ease, box-shadow .25s ease; }
    .otp-box:focus { border-color: #22d3ee; box-shadow: 0 0 0 3px rgba(34, 211, 238, .25), 0 0 16px rgba(34, 211, 238, .35); }
    .links { display: flex; justify-content: space-between; margin-top: 14px; font-size: 14px; }
    .links a { color: inherit; text-decoration: none; transition: color .25s ease; }
    .links a:hover { color: #67e8f9; }
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
    <h1>Verify it's you</h1>
    <p class="subtitle">Enter the 6-digit code sent to your email. It checks automatically once all 6 digits are entered.</p>
    <span class="accent-line"></span>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php elseif ($info): ?>
      <div class="alert alert-success"><?= e($info) ?></div>
    <?php endif; ?>

    <form method="post" id="otpForm" class="auth-form">
      <input type="hidden" name="code" id="codeHidden">
      <div class="otp">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input class="otp-box" type="text" inputmode="numeric" maxlength="1" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" aria-label="Code digit <?= $i + 1 ?>">
        <?php endfor; ?>
      </div>
    </form>

    <p class="links"><a href="twofactor.php?resend=1">Send a new code</a><a href="twofactor.php?cancel=1">Cancel</a></p>
  </main>
  <script>
    (function () {
      var boxes = Array.prototype.slice.call(document.querySelectorAll('.otp-box'));
      var form = document.getElementById('otpForm');
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
