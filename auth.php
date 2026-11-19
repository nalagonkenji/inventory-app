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

// ---------- Session timeout (Settings > Security) ----------
function enforce_session_timeout(mysqli $conn): void {
    if (empty($_SESSION['user_id'])) return;
    $minutes = 30;
    try {
        $row = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'session_timeout'")->fetch_assoc();
        if ($row && in_array($row['setting_value'], ['15', '30', '60'], true)) $minutes = (int)$row['setting_value'];
    } catch (Throwable $t) {
        // keep the default
    }
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $minutes * 60) {
        try {
            $uid = (int)$_SESSION['user_id'];
            $d = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
            $d->bind_param("i", $uid);
            $d->execute();
        } catch (Throwable $t) {
            // best effort
        }
        setcookie('remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        $_SESSION = [];
        session_destroy();
        session_start();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_seen'] = time();
}
enforce_session_timeout($conn);

// ---------- Finishing a login (used by the login and two-factor pages) ----------
function complete_login(mysqli $conn, array $user, bool $remember): void {
    session_regenerate_id(true);
    $uid = (int)$user['id'];
    $_SESSION['user_id'] = $uid;
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'] ?? 'staff';
    $_SESSION['last_seen'] = time();
    if ($remember) {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $validator);
        $rt = $conn->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL 30 DAY)");
        $rt->bind_param("iss", $uid, $selector, $tokenHash);
        $rt->execute();
        setcookie('remember', $selector . ':' . $validator, ['expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    $touch = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $touch->bind_param("i", $uid);
    $touch->execute();
    header('Location: dashboard.php');
    exit;
}

// ---------- Two-factor sign-in code (Settings > Security) ----------
function send_login_code(mysqli $conn, array $user): void {
    $uid = (int)$user['id'];
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $del = $conn->prepare("DELETE FROM two_factor_codes WHERE user_id = ?");
    $del->bind_param("i", $uid);
    $del->execute();
    $hash = hash('sha256', $code);
    $ins = $conn->prepare("INSERT INTO two_factor_codes (user_id, code_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE)");
    $ins->bind_param("is", $uid, $hash);
    $ins->execute();
    $body = "Hello " . $user['username'] . ",\n\n"
          . "Your sign-in code is: " . $code . "\n\n"
          . "The code expires in 10 minutes. If you did not try to log in, change your password.\n";
    @mail($user['email'], 'Inventory sign-in code', $body, "Content-Type: text/plain; charset=UTF-8");
}

// ---------- CSRF protection ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

// Stops a POST that did not come from one of our own forms.
function csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(403);
        exit('Your form expired. Go back, refresh the page, and try again.');
    }
}

// Adds the hidden token to every POST form in the page output, so pages need no changes.
function csrf_inject(string $html): string {
    $field = '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    return preg_replace_callback(
        '/<form\b[^>]*\bmethod\s*=\s*["\']post["\'][^>]*>/i',
        fn($m) => $m[0] . $field,
        $html
    );
}

csrf_check();
ob_start('csrf_inject');

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
