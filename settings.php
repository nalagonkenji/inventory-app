<?php
require __DIR__ . '/auth.php';
require_admin();
require __DIR__ . '/inventory_lib.php';

const SETTINGS_TABS = [
    'general'       => ['General', 'settings'],
    'appearance'    => ['Appearance', 'monitor'],
    'notifications' => ['Notifications', 'bell'],
    'security'      => ['Security', 'adjust'],
    'backup'        => ['Backup & Restore', 'download'],
];
$defaults = [
    'system_name' => 'Computer Parts Inventory Management',
    'currency' => 'PHP',
    'date_format' => 'M d, Y',
    'time_format' => '12',
    'notif_low_stock' => '1',
    'notif_inventory' => '1',
    'notif_transactions' => '1',
    'notif_announcements' => '0',
    'two_factor' => '0',
    'session_timeout' => '30',
];

function save_setting(mysqli $conn, string $key, string $value): void {
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
}

// ---------- Save actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    $back = array_key_exists($_POST['tab'] ?? '', SETTINGS_TABS) ? $_POST['tab'] : 'general';
    $flash = ['type' => 'success', 'msg' => 'Settings saved.'];

    if ($section === 'general') {
        $name = substr(trim($_POST['system_name'] ?? ''), 0, 100);
        if ($name === '') {
            $flash = ['type' => 'error', 'msg' => 'System name cannot be empty.'];
        } else {
            save_setting($conn, 'system_name', $name);
            save_setting($conn, 'currency', in_array($_POST['currency'] ?? '', ['PHP', 'USD', 'EUR'], true) ? $_POST['currency'] : 'PHP');
            save_setting($conn, 'date_format', in_array($_POST['date_format'] ?? '', ['M d, Y', 'Y-m-d', 'd/m/Y'], true) ? $_POST['date_format'] : 'M d, Y');
            save_setting($conn, 'time_format', in_array($_POST['time_format'] ?? '', ['12', '24'], true) ? $_POST['time_format'] : '12');
        }
    } elseif ($section === 'notifications') {
        foreach (['notif_low_stock', 'notif_inventory', 'notif_transactions', 'notif_announcements'] as $key) {
            save_setting($conn, $key, isset($_POST[$key]) ? '1' : '0');
        }
    } elseif ($section === 'security') {
        $timeout = in_array($_POST['session_timeout'] ?? '', ['15', '30', '60'], true) ? $_POST['session_timeout'] : '30';
        save_setting($conn, 'session_timeout', $timeout);
        save_setting($conn, 'two_factor', isset($_POST['two_factor']) ? '1' : '0');
    } elseif ($section === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $row = run_query($conn, "SELECT password_hash FROM users WHERE id = ?", "i", [(int)$_SESSION['user_id']])->fetch_assoc();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $flash = ['type' => 'error', 'msg' => 'Your current password is incorrect.'];
        } elseif (strlen($new) < 8) {
            $flash = ['type' => 'error', 'msg' => 'The new password must be at least 8 characters.'];
        } elseif ($new !== $confirm) {
            $flash = ['type' => 'error', 'msg' => 'The new passwords do not match.'];
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $uid = (int)$_SESSION['user_id'];
            $stmt->bind_param("si", $hash, $uid);
            $stmt->execute();
            $flash = ['type' => 'success', 'msg' => 'Password changed.'];
        }
    } elseif ($section === 'reset') {
        $conn->query("DELETE FROM settings");
        $back = 'general';
        $flash = ['type' => 'success', 'msg' => 'Settings reset to defaults.'];
    }

    $_SESSION['flash'] = $flash;
    header('Location: settings.php?tab=' . $back);
    exit;
}

// ---------- Load ----------
$cfg = $defaults;
foreach (run_query($conn, "SELECT setting_key, setting_value FROM settings")->fetch_all(MYSQLI_ASSOC) as $row) {
    if (array_key_exists($row['setting_key'], $defaults)) {
        $cfg[$row['setting_key']] = $row['setting_value'];
    }
}
$tab = array_key_exists($_GET['tab'] ?? '', SETTINGS_TABS) ? $_GET['tab'] : 'general';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$dbVersion = run_query($conn, "SELECT VERSION() AS v")->fetch_assoc()['v'];
$dbLabel = str_contains($dbVersion, 'MariaDB') ? 'MariaDB ' . explode('-', $dbVersion)[0] : 'MySQL ' . $dbVersion;
$serverLabel = explode(' ', $_SERVER['SERVER_SOFTWARE'] ?? 'Apache')[0];
$serverLabel = str_replace('/', ' ', $serverLabel);
$lastUpdated = date('M d, Y', filemtime(__FILE__));

function sw(string $name, string $cfgKey, array $cfg): string {
    $checked = $cfg[$cfgKey] === '1' ? 'checked' : '';
    return '<label class="switch"><input type="checkbox" name="' . $name . '" value="1" ' . $checked . '><span class="slider"></span></label>';
}
function opt(string $value, string $label, string $current): string {
    return '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e($label) . '</option>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings | Computer Parts Inventory</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash-body">
<div class="app">

  <?php render_sidebar('settings'); ?>

  <div class="main">
    <header class="topbar2">
      <button class="icon-btn menu-btn" type="button" aria-label="Menu"><?= icon('menu') ?></button>
      <div class="page-title">
        <div class="title-row"><?= icon('settings', 26) ?><h1>Settings</h1></div>
        <p>Manage system preferences, security, and application settings.</p>
      </div>
      <div class="top-right">
        <div class="bell"><?= icon('bell', 22) ?></div>
        <details class="userbox">
          <summary>
            <span class="avatar"><?= icon('users', 18) ?></span>
            <span><?= e($_SESSION['username']) ?></span>
            <?= icon('down', 16) ?>
          </summary>
          <a class="userbox-menu" href="logout.php"><?= icon('logout', 16) ?> Log out</a>
        </details>
      </div>
    </header>

    <main class="content">
      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>

      <nav class="tabs">
        <?php foreach (SETTINGS_TABS as $key => [$label, $ico]): ?>
          <a class="tab <?= $tab === $key ? 'active' : '' ?>" href="settings.php?tab=<?= $key ?>"><?= icon($ico, 20) ?> <?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($tab === 'general'): ?>
      <section class="grid-2">
        <div class="panel">
          <div class="panel-head"><h2><?= icon('monitor', 20) ?> System Information</h2></div>
          <div class="info-list">
            <div><span>Application</span><strong><?= e($cfg['system_name']) ?></strong></div>
            <div><span>Version</span><strong>1.0.0</strong></div>
            <div><span>Database</span><strong><?= e($dbLabel) ?></strong></div>
            <div><span>Server</span><strong><?= e($serverLabel) ?></strong></div>
            <div><span>PHP</span><strong><?= e(PHP_VERSION) ?></strong></div>
            <div><span>Last Updated</span><strong><?= e($lastUpdated) ?></strong></div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h2><?= icon('settings', 20) ?> General Settings</h2></div>
          <form method="post" class="dialog-form">
            <input type="hidden" name="section" value="general">
            <input type="hidden" name="tab" value="general">
            <label>System name
              <input name="system_name" maxlength="100" required value="<?= e($cfg['system_name']) ?>">
            </label>
            <label>Default currency
              <select name="currency">
                <?= opt('PHP', 'Philippine Peso (PHP)', $cfg['currency']) ?>
                <?= opt('USD', 'US Dollar (USD)', $cfg['currency']) ?>
                <?= opt('EUR', 'Euro (EUR)', $cfg['currency']) ?>
              </select>
            </label>
            <label>Date format
              <select name="date_format">
                <?= opt('M d, Y', date('M d, Y') . ' (example)', $cfg['date_format']) ?>
                <?= opt('Y-m-d', date('Y-m-d') . ' (ISO)', $cfg['date_format']) ?>
                <?= opt('d/m/Y', date('d/m/Y'), $cfg['date_format']) ?>
              </select>
            </label>
            <label>Time format
              <select name="time_format">
                <?= opt('12', '12-hour (AM/PM)', $cfg['time_format']) ?>
                <?= opt('24', '24-hour', $cfg['time_format']) ?>
              </select>
            </label>
            <div class="dialog-actions">
              <button class="btn-d primary" type="submit">Save Changes</button>
            </div>
          </form>
        </div>
      </section>

      <section class="panel" style="margin-top:18px">
        <div class="panel-head"><h2><?= icon('adjust', 20) ?> Reset</h2></div>
        <p class="muted">Restore every setting on this page to its default value.</p>
        <form method="post" onsubmit="return confirm('Reset all settings to defaults?');">
          <input type="hidden" name="section" value="reset">
          <button class="btn-d" type="submit"><?= icon('logout', 16) ?> Reset to Default</button>
        </form>
      </section>

      <?php elseif ($tab === 'appearance'): ?>
      <section class="panel">
        <div class="panel-head"><h2><?= icon('monitor', 20) ?> Appearance</h2></div>
        <p class="muted">Customize the look and feel of the system.</p>
        <div class="theme-options">
          <div class="theme-opt active">Dark (Default)</div>
          <div class="theme-opt" aria-disabled="true">Light <small>(coming soon)</small></div>
          <div class="theme-opt" aria-disabled="true">System <small>(coming soon)</small></div>
        </div>
      </section>

      <?php elseif ($tab === 'notifications'): ?>
      <section class="panel">
        <div class="panel-head"><h2><?= icon('bell', 20) ?> Notifications</h2></div>
        <p class="muted">Choose which system notifications you want to receive.</p>
        <form method="post">
          <input type="hidden" name="section" value="notifications">
          <input type="hidden" name="tab" value="notifications">
          <label class="setting-row"><span><strong>Low Stock Alerts</strong></span><?= sw('notif_low_stock', 'notif_low_stock', $cfg) ?></label>
          <label class="setting-row"><span><strong>Inventory Updates</strong></span><?= sw('notif_inventory', 'notif_inventory', $cfg) ?></label>
          <label class="setting-row"><span><strong>Transaction Notifications</strong></span><?= sw('notif_transactions', 'notif_transactions', $cfg) ?></label>
          <label class="setting-row"><span><strong>System Announcements</strong></span><?= sw('notif_announcements', 'notif_announcements', $cfg) ?></label>
          <div class="dialog-actions">
            <button class="btn-d primary" type="submit">Save Changes</button>
          </div>
        </form>
      </section>

      <?php elseif ($tab === 'security'): ?>
      <section class="grid-2">
        <div class="panel">
          <div class="panel-head"><h2><?= icon('adjust', 20) ?> Account Security</h2></div>
          <form method="post">
            <input type="hidden" name="section" value="security">
            <input type="hidden" name="tab" value="security">
            <label class="setting-row"><span><strong>Two-Factor Authentication (2FA)</strong></span><?= sw('two_factor', 'two_factor', $cfg) ?></label>
            <label class="setting-row"><span><strong>Session Timeout</strong></span>
              <select name="session_timeout">
                <?= opt('15', '15 Minutes', $cfg['session_timeout']) ?>
                <?= opt('30', '30 Minutes', $cfg['session_timeout']) ?>
                <?= opt('60', '60 Minutes', $cfg['session_timeout']) ?>
              </select>
            </label>
            <div class="dialog-actions">
              <button class="btn-d primary" type="submit">Save Changes</button>
            </div>
          </form>
        </div>

        <div class="panel">
          <div class="panel-head"><h2><?= icon('users', 20) ?> Change Password</h2></div>
          <form method="post" class="dialog-form">
            <input type="hidden" name="section" value="password">
            <input type="hidden" name="tab" value="security">
            <label>Current password
              <input type="password" name="current_password" required>
            </label>
            <label>New password
              <input type="password" name="new_password" minlength="8" required>
            </label>
            <label>Confirm new password
              <input type="password" name="confirm_password" minlength="8" required>
            </label>
            <div class="dialog-actions">
              <button class="btn-d primary" type="submit">Update Password</button>
            </div>
          </form>
        </div>
      </section>

      <?php else: ?>
      <section class="panel">
        <div class="panel-head"><h2><?= icon('download', 20) ?> Backup &amp; Restore</h2></div>
        <p class="muted">Keep your inventory data safe by creating backups.</p>
        <div class="actions">
          <button class="btn-d primary" type="button" disabled><?= icon('download', 16) ?> Create Backup</button>
          <button class="btn-d" type="button" disabled><?= icon('plus', 16) ?> Restore Backup</button>
        </div>
        <p class="muted">Backups are not set up in this version yet.</p>
      </section>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
