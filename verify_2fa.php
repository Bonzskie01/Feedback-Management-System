<?php
// verify_2fa.php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/totp_helper.php';

if (!isset($_SESSION['pending_admin_id']) || $_SESSION['pending_expiry'] < time()) {
    header("Location: index.php");
    exit;
}

$adminId = $_SESSION['pending_admin_id'];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Please refresh and try again.';
    } else {
        $identity = $_SESSION['pending_admin_username'] ?? ('admin:' . $adminId);
        $rate = login_rate_limit_status($conn, $identity, '2fa', 5, 900);

        if ($rate['limited']) {
            unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'],
                $_SESSION['pending_admin_full_name'], $_SESSION['pending_expiry']);
            header('Location: index.php');
            exit;
        }

        $code = trim($_POST['code'] ?? '');
        $result = pg_query_params($conn, "SELECT totp_secret, backup_codes FROM admins WHERE id = $1", [$adminId]);
        if (!$result || pg_num_rows($result) !== 1) {
            $error = 'Unable to verify the security code. Please try again.';
        } else {
            $admin = pg_fetch_assoc($result);
            $totpSecret = decrypt_sensitive_value($admin['totp_secret'] ?? '');
            $matchedCounter = $totpSecret !== ''
                ? TOTP::verifyCodeAndGetCounter($totpSecret, $code)
                : false;
            $verified = false;
            $usedBackupCode = false;
            $replayedCode = false;
            $verificationFailed = false;

            if ($matchedCounter !== false) {
                $claim = claim_admin_totp_counter($conn, $adminId, $matchedCounter);
                if ($claim === true) {
                    $verified = true;
                } elseif ($claim === false) {
                    $replayedCode = true;
                } else {
                    $verificationFailed = true;
                }
            } else {
                $newBackupJson = TOTP::verifyBackupCode($code, $admin['backup_codes']);
                if ($newBackupJson !== false) {
                    // Compare the old value so the same backup code cannot win twice.
                    $claim = pg_query_params($conn,
                        "UPDATE admins
                            SET backup_codes = $1
                          WHERE id = $2
                            AND backup_codes IS NOT DISTINCT FROM $3
                          RETURNING id",
                        [$newBackupJson, $adminId, $admin['backup_codes']]
                    );
                    if ($claim === false) {
                        $verificationFailed = true;
                    } else {
                        $verified = pg_num_rows($claim) === 1;
                        $usedBackupCode = $verified;
                        $replayedCode = !$verified;
                    }
                }
            }
            if ($verified) {
                record_login_attempt($conn, $identity, '2fa', true);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $_SESSION['pending_admin_id'];
                $_SESSION['admin_username'] = $_SESSION['pending_admin_username'] ?? '';
                $_SESSION['admin_full_name'] = $_SESSION['pending_admin_full_name'] ?? '';
                $_SESSION['auth_started_at'] = time();
                $_SESSION['last_activity_at'] = time();
                unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_username'],
                    $_SESSION['pending_admin_full_name'], $_SESSION['pending_expiry']);
                audit_log($conn, 'admin_login_success', 'admin', $adminId, ['method' => $usedBackupCode ? 'backup_code' : 'totp']);
                header('Location: admin/dashboard.php');
                exit;
            }

            record_login_attempt($conn, $identity, '2fa', false);
            if ($replayedCode) {
                audit_log($conn, 'admin_2fa_replay_rejected', 'admin', $adminId);
                $error = 'This authentication code has already been used. Wait for a new code or use an unused backup code.';
            } elseif ($verificationFailed) {
                $error = 'Unable to verify the security code. Please try again.';
            } else {
                $error = 'Invalid authentication code.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - 2FA Verification</title>
    <link rel="icon" type="image/png" href="<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255, 255, 255, 0.92);
            --bg-panel: rgba(248, 250, 255, 0.9);
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: rgba(0, 0, 0, 0.1);
            --accent: #3b82f6;
            --accent-primary: #1a237e;
            --topbar-bg: rgba(255, 255, 255, 0.85);
            --shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }
        [data-theme="dark"] {
            --bg-body: #060d1f;
            --bg-card: #0f1e4a;
            --bg-panel: rgba(20, 24, 36, 0.9);
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.1);
            --accent: #60a5fa;
            --accent-primary: #3b82f6;
            --topbar-bg: #0f1e4a;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-body); transition: background 0.3s; }
        .bg-layer {
            position: fixed; inset: 0;
            background: url('<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
        }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240, 244, 255, 0.7); }
        [data-theme="dark"] .bg-layer::before { background: rgba(6, 13, 31, 0.85); }
        .admin-wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; }
        .sidebar {
            width: 240px; background: #0f1e4a; display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; z-index: 100; height: 100%;
        }
        .sidebar-header { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header img { width: 34px; height: 34px; object-fit: contain; }
        .sidebar-header h2 { font-size: 14px; color: white; }
        .sidebar-nav { flex:1; padding: 14px 10px; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 10px; padding: 10px 13px;
            border-radius: 10px; text-decoration: none; color: rgba(255,255,255,0.7);
            font-size: 13px; font-weight: 600;
        }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 240px; flex: 1; }
        .topbar {
            padding: 14px 26px; background: var(--topbar-bg); backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 50;
        }
        /* FIX: heading color in dark mode */
        .topbar h1 {
            color: var(--text-primary);
        }
        .content-area { padding: 24px 26px; }
        .panel {
            background: var(--bg-card); border: 1px solid var(--border-color);
            backdrop-filter: blur(4px); border-radius: 14px; overflow: hidden;
            max-width: 500px; margin: 0 auto;
        }
        .panel-header { padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
        .panel-header h3 { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .panel-body { padding: 20px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary); }
        .form-group input {
            width: 100%; padding: 12px 14px; background: var(--bg-panel);
            border: 1px solid var(--border-color); border-radius: 10px;
            color: var(--text-primary); font-size: 14px; outline: none;
        }
        .btn-primary {
            background: var(--accent-primary); color: white; border: none;
            padding: 12px; border-radius: 10px; width: 100%; cursor: pointer;
            font-weight: 700; transition: 0.2s;
        }
        .btn-primary:hover { background: var(--accent); transform: translateY(-2px); }
        .error-message {
            background: rgba(239,68,68,0.1); border-left: 3px solid #ef4444;
            padding: 12px; border-radius: 8px; margin-bottom: 20px; color: #ef4444;
        }
        .back-link { display: inline-block; margin-top: 20px; text-align: center; width: 100%; color: var(--accent); text-decoration: none; font-size: 13px; }
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

<div class="bg-layer"></div>

<div class="admin-wrapper">
    <aside class="sidebar">
        <div class="sidebar-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <img src="<?php echo site_logo(); ?>" alt="Logo">
                <div><h2>Voting System</h2><small style="color:rgba(255,255,255,0.4);">Admin</small></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="#"><i class="fas fa-shield-alt"></i> 2FA Verify</a>
        </nav>
        <div class="sidebar-footer" style="padding:14px 18px; border-top:1px solid rgba(255,255,255,0.06); font-size:10px; color:rgba(255,255,255,0.25); text-align:center;">
            © <?php echo date('Y'); ?> <?php echo site_name(); ?>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <h1><i class="fas fa-key"></i> Two-Factor Authentication</h1>
            <div class="topbar-right">
                <button class="topbar-btn" onclick="toggleTheme()" style="background:transparent; border:1px solid var(--border-color); padding:6px 10px; border-radius:8px; cursor:pointer;">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
            </div>
        </header>

        <div class="content-area">
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-lock"></i> Verification Required</h3>
                </div>
                <div class="panel-body">
                    <?php if ($error): ?>
                        <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <p style="margin-bottom: 20px; color: var(--text-secondary);">Enter the 6-digit code from your authenticator app, or use one of your backup codes.</p>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label>Authentication Code</label>
                            <input type="text" name="code" placeholder="e.g., 123456" autofocus required>
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-check-circle"></i> Verify & Continue</button>
                    </form>
                    <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function toggleTheme() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('vms-theme', 'light');
    } else {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('vms-theme', 'dark');
    }
    updateIcon();
}
function updateIcon() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const icon = document.getElementById('themeIcon');
    if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
}
(function() {
    if (localStorage.getItem('vms-theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
    updateIcon();
})();
</script>
</body>
</html>