<?php
// admin/profile.php
require __DIR__ . '/feedback_profile.php';
exit;
$root_path = dirname(__FILE__) . '/..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';
require_once $root_path . '/includes/totp_helper.php';

if (!is_admin_logged_in()) {
    redirect('../index.php');
}

$adminId = $_SESSION['admin_id'];

// Fetch admin data
$query = "SELECT username, password_hash, totp_secret, totp_enabled, backup_codes FROM admins WHERE id = $1";
$result = pg_query_params($conn, $query, [$adminId]);
if (!$result) die('Unable to load profile. Please try again.');
$admin = pg_fetch_assoc($result);
$totpSecret = decrypt_sensitive_value($admin['totp_secret'] ?? '');

if ($totpSecret === '') {
    $totpSecret = TOTP::generateSecret();
    $result = pg_query_params($conn, "UPDATE admins SET totp_secret = $1 WHERE id = $2",
        [encrypt_sensitive_value($totpSecret), $adminId]);
    if (!$result) die('Unable to update profile security settings. Please try again.');
} elseif (!str_starts_with((string)$admin['totp_secret'], 'enc:v1:')) {
    pg_query_params($conn, "UPDATE admins SET totp_secret = $1 WHERE id = $2",
        [encrypt_sensitive_value($totpSecret), $adminId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enable_2fa'])) {
    require_valid_csrf();
    $passwordValid = verify_admin_password($conn, $adminId, $_POST['admin_password'] ?? '');
    $matchedCounter = TOTP::verifyCodeAndGetCounter($totpSecret, trim($_POST['totp_code'] ?? ''));
    $codeValid = $matchedCounter !== false;

    if ($passwordValid && $codeValid) {
        $counterClaim = claim_admin_totp_counter($conn, $adminId, $matchedCounter);
        if ($counterClaim === true) {
            $backupCodes = TOTP::generateBackupCodes();
            $hashedBackups = TOTP::hashBackupCodes($backupCodes);
            $result = pg_query_params($conn,
                "UPDATE admins SET totp_enabled = TRUE, backup_codes = $1 WHERE id = $2",
                [$hashedBackups, $adminId]);
            if (!$result) die('Unable to update profile security settings. Please try again.');
            session_regenerate_id(true);
            audit_log($conn, 'admin_2fa_enabled', 'admin', $adminId);
            $_SESSION['flash_backup_codes'] = $backupCodes;
            header('Location: profile.php?2fa_enabled=1');
            exit;
        }
        $error = $counterClaim === false
            ? 'This authenticator code has already been used. Wait for a new code.'
            : 'Unable to verify the authenticator code. Please try again.';
    } else {
        $error = 'Current password and a valid authenticator code are required.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_2fa'])) {
    require_valid_csrf();
    $passwordValid = verify_admin_password($conn, $adminId, $_POST['admin_password'] ?? '');
    $matchedCounter = TOTP::verifyCodeAndGetCounter($totpSecret, trim($_POST['totp_code'] ?? ''));
    $codeValid = $matchedCounter !== false;

    if ($passwordValid && $codeValid) {
        $counterClaim = claim_admin_totp_counter($conn, $adminId, $matchedCounter);
        if ($counterClaim === true) {
            $result = pg_query_params($conn,
                "UPDATE admins SET totp_enabled = FALSE, totp_secret = NULL, backup_codes = NULL WHERE id = $1",
                [$adminId]);
            if (!$result) die('Unable to update profile security settings. Please try again.');
            session_regenerate_id(true);
            audit_log($conn, 'admin_2fa_disabled', 'admin', $adminId);
            $_SESSION['flash'] = 'Two-factor authentication disabled.';
            header('Location: profile.php');
            exit;
        }
        $error = $counterClaim === false
            ? 'This authenticator code has already been used. Wait for a new code.'
            : 'Unable to verify the authenticator code. Please try again.';
    } else {
        $error = 'Current password and authenticator code are required to disable 2FA.';
    }
}
$page_title = 'Admin Profile - 2FA Settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Admin Profile</title>
    <link rel="icon" type="image/png" href="../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ========================================
           DEEP BLUE THEME - DASHBOARD (matching positions/index)
        ======================================== */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        *::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        *::-webkit-scrollbar-track {
            background: transparent;
        }
        *::-webkit-scrollbar-thumb {
            background: rgba(128, 128, 128, 0.4);
            border-radius: 3px;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
        }

        /* CSS VARIABLES */
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255, 255, 255, 0.92);
            --bg-panel: rgba(248, 250, 255, 0.9);
            --bg-input: #f1f5f9;
            --bg-hover: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: rgba(0, 0, 0, 0.1);
            --accent: #3b82f6;
            --accent-primary: #1a237e;
            --accent-primary-hover: #283593;
            --shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.1);
            --topbar-bg: rgba(255, 255, 255, 0.85);
            --input-border: #cbd5e1;
            --table-head: rgba(241, 245, 251, 0.9);
            --table-row-hover: rgba(245, 247, 255, 0.8);
            --label-color: #334155;
            --placeholder-color: #94a3b8;
        }

        [data-theme="dark"] {
            --bg-body: #060d1f;
            --bg-card: #0f1e4a;
            --bg-panel: rgba(20, 24, 36, 0.9);
            --bg-input: #252a3a;
            --bg-hover: #252a3a;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.1);
            --accent: #60a5fa;
            --accent-primary: #3b82f6;
            --accent-primary-hover: #60a5fa;
            --shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
            --shadow-md: 0 6px 28px rgba(0, 0, 0, 0.5);
            --topbar-bg: #0f1e4a;
            --input-border: #3d435a;
            --table-head: #0f1e4a;
            --table-row-hover: rgba(30, 36, 56, 0.8);
            --label-color: #cbd5e1;
            --placeholder-color: #64748b;
        }

        /* BACKGROUND IMAGE */
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('../<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
            transition: opacity 0.3s;
        }
        :root .bg-layer::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(240, 244, 255, 0.7);
        }
        [data-theme="dark"] .bg-layer::before {
            background: rgba(6, 13, 31, 0.85);
        }

        /* SIDEBAR */
        .sidebar {
            width: 240px;
            min-height: 100vh;
            background: #0f1e4a;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            transition: transform 0.3s;
        }
        .sidebar-header {
            padding: 22px 20px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .sidebar-header .logo-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-header img {
            width: 34px;
            height: 34px;
            object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.5));
        }
        .sidebar-header h2 {
            font-size: 14px;
            font-weight: 800;
            color: white;
        }
        .sidebar-header small {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.4);
            display: block;
        }
        .sidebar-nav {
            flex: 1;
            padding: 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 13px;
            border-radius: 10px;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.7);
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .sidebar-nav a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .sidebar-nav a.active {
            background: rgba(59, 130, 246, 0.25);
            color: #90caf9;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }
        .sidebar-nav a i {
            width: 18px;
            text-align: center;
        }
        .nav-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
            margin: 6px 0;
        }
        .sidebar-nav a.logout-link {
            color: rgba(248, 113, 113, 0.8);
        }
        .sidebar-nav a.logout-link:hover {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }
        .sidebar-footer {
            padding: 14px 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 10px;
            color: rgba(255, 255, 255, 0.25);
            text-align: center;
        }

        /* MAIN CONTENT */
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }
        .main-content {
            margin-left: 240px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* TOPBAR */
        .topbar {
            padding: 14px 26px;
            background: var(--topbar-bg);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: var(--shadow);
        }
        .topbar h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .topbar-user {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .topbar-user .avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            color: var(--accent);
        }
        .topbar-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .topbar-btn:hover {
            color: var(--accent);
            border-color: var(--accent);
        }

        /* CONTENT AREA */
        .content-area {
            padding: 24px 26px;
            flex: 1;
        }
        .welcome-banner {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .welcome-banner h2 {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 4px;
            color: var(--text-primary);
        }
        .welcome-banner p {
            font-size: 13px;
            color: var(--text-muted);
        }
        .welcome-banner .banner-icon {
            font-size: 42px;
            opacity: 0.5;
            color: var(--accent);
        }

        /* PANEL */
        .panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 14px;
            overflow: hidden;
        }
        .panel-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .panel-header h3 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .panel-body {
            padding: 20px;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text-secondary);
        }
        .form-group input {
            width: 100%;
            padding: 12px 14px;
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 14px;
            outline: none;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            transition: all 0.2s;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .action-btn.primary {
            background: rgba(59, 130, 246, 0.2);
            border-color: rgba(59, 130, 246, 0.4);
            color: #1e3a8a;
        }
        [data-theme="dark"] .action-btn.primary {
            color: #93c5fd;
        }

        /* MOBILE */
        .hamburger {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 20px;
            cursor: pointer;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>

<div class="bg-layer"></div>

<div class="admin-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-row">
                <img src="../<?php echo site_logo(); ?>" alt="Logo">
                <div>
                    <h2>Voting System</h2>
                    <small>Admin Panel</small>
                </div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="elections/index.php"><i class="fas fa-calendar-alt"></i> Elections</a>
            <a href="index.php" class="active"><i class="fas fa-list-ul"></i> Party Lists</a>
            <a href="positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
            <a href="candidates/index.php"><i class="fas fa-users"></i> Candidates</a>
            <a href="voters/index.php"><i class="fas fa-user-check"></i> Voters</a>
            <a href="results/live.php"><i class="fas fa-chart-bar"></i> Live Results</a>
            <div class="nav-divider"></div>
            <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="profile.php" class="active"><i class="fas fa-shield-alt"></i>2FA</a>
            <a href="#" class="logout-link" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">
            © <?php echo date('Y'); ?> <?php echo site_name(); ?> Information Systems
        </div>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <h1><i class="fas fa-user-shield"></i> Profile & Security</h1>
            </div>
            <div class="topbar-right">
                <div class="topbar-user">
                    <div class="avatar"><i class="fas fa-user-shield"></i></div>
                    <span>Welcome, <strong><?php echo h($_SESSION['admin_full_name']); ?></strong></span>
                </div>
                <button class="topbar-btn" onclick="toggleTheme()" title="Toggle Theme">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
                <button class="topbar-btn" onclick="confirmLogout(event)" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        </header>

        <div class="content-area">
            <div class="welcome-banner">
                <div>
                    <h2><i class="fas fa-user-lock"></i> Account Security</h2>
                    <p>Manage your two‑factor authentication settings and backup codes.</p>
                </div>
                <div class="banner-icon"><i class="fas fa-shield-halved"></i></div>
            </div>

            <div class="panel" style="margin-bottom:20px;">
                <div class="panel-header">
                    <h3><i class="fas fa-key"></i> Two‑Factor Authentication (2FA)</h3>
                </div>
                <div class="panel-body">
                    <?php if (isset($_SESSION['flash_backup_codes'])): ?>
                        <div style="background:#fff3cd; padding:15px; margin-bottom:20px; border-radius:12px;">
                            <strong>📋 Save these backup codes!</strong> Store them safely. Each code can be used only once.<br><br>
                            <?php foreach ($_SESSION['flash_backup_codes'] as $code): ?>
                                <code style="background:#000; padding:4px 10px; margin:3px; display:inline-block;"><?= htmlspecialchars($code) ?></code>
                            <?php endforeach; ?>
                            <?php unset($_SESSION['flash_backup_codes']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['flash'])): ?>
                        <div style="background:#d1ecf1; padding:10px; margin-bottom:20px; border-radius:10px;"><?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div style="background:#fee2e2; color:#991b1b; padding:12px; margin-bottom:20px; border-radius:8px;">
                            <?php echo h($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($admin['totp_enabled'] !== 't' && $admin['totp_enabled'] !== true): ?>
                        <p>Two-factor authentication adds an extra layer of security to your account.</p>
                        <p>Add a setup key manually in Google Authenticator, Microsoft Authenticator, or Authy.</p>
                        <div style="padding:14px; margin:18px 0; background:var(--bg-panel); border:1px solid var(--border-color); border-radius:8px;">
                            <div><strong>Account:</strong> <?php echo h($admin['username']); ?></div>
                            <div style="margin-top:8px;"><strong>Setup key:</strong> <code><?php echo h($totpSecret); ?></code></div>
                            <div style="margin-top:8px;"><strong>Type:</strong> Time based</div>
                        </div>
                        <form method="post" autocomplete="off">
                            <?php echo csrf_field(); ?>
                            <div class="form-group">
                                <label>Current admin password</label>
                                <input type="password" name="admin_password" autocomplete="current-password" required>
                            </div>
                            <div class="form-group">
                                <label>6-digit authenticator code</label>
                                <input type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                            </div>
                            <button type="submit" name="enable_2fa" class="action-btn primary" style="background:var(--accent-primary); color:white; border:none;">Verify & Enable 2FA</button>
                        </form>
                    <?php else: ?>
                        <p><strong>Two-factor authentication is enabled.</strong></p>
                        <p style="margin-top:8px;">Your current password and authenticator code are required to disable it.</p>
                        <form method="post" autocomplete="off" style="margin-top:18px;" onsubmit="return confirm('Disabling 2FA will reduce account security. Continue?');">
                            <?php echo csrf_field(); ?>
                            <div class="form-group">
                                <label>Current admin password</label>
                                <input type="password" name="admin_password" autocomplete="current-password" required>
                            </div>
                            <div class="form-group">
                                <label>6-digit authenticator code</label>
                                <input type="text" name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                            </div>
                            <button type="submit" name="disable_2fa" class="action-btn" style="background:#dc2626; color:white; border:none;">Disable 2FA</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-info-circle"></i> Account Information</h3>
                </div>
                <div class="panel-body">
                    <p><strong>Username:</strong> <?php echo h($admin['username']); ?></p>
                    <p><strong>Admin ID:</strong> <?php echo $adminId; ?></p>
                    <p><strong>Role:</strong> Administrator</p>
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
    updateThemeIcon();
}
function updateThemeIcon() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
}

function confirmLogout(event) {
    event.preventDefault();
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    
    Swal.fire({
        title: '<i class="fas fa-sign-out-alt"></i> Logging Out?',
        text: 'Are you sure you want to end your admin session?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout',
        cancelButtonText: '<i class="fas fa-times"></i> Cancel',
        background: isDark ? '#0f1e4a' : '#ffffff',
        color: isDark ? '#f1f5f9' : '#1e293b',
        backdrop: 'rgba(0, 0, 0, 0.4)'
    }).then(result => {
        if (result.isConfirmed) {
            window.location.href = 'logout.php';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateThemeIcon();
    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (hamburger) {
        hamburger.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }
});
(function() {
    if (localStorage.getItem('vms-theme') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
    updateThemeIcon();
})();
</script>

</body>
</html>
