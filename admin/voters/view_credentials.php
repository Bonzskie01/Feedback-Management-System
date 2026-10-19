<?php
// admin/voters/view_credentials.php
$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$bulkCredentials = isset($_SESSION['last_generated']) ? $_SESSION['last_generated'] : null;
$resetCredentials = isset($_SESSION['reset_credentials']) ? $_SESSION['reset_credentials'] : null;
unset($_SESSION['last_generated'], $_SESSION['reset_credentials']);

// Store credentials for one-time download (used by download_credentials.php)
if ($bulkCredentials) {
    $_SESSION['downloadable_credentials'] = $bulkCredentials;
    $_SESSION['downloadable_type'] = 'bulk';
} elseif ($resetCredentials) {
    $_SESSION['downloadable_credentials'] = $resetCredentials;
    $_SESSION['downloadable_type'] = 'single';
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$voter = null;
if ($id > 0) {
    $voter_q = pg_query_params($conn, "
        SELECT v.*, e.title AS election_title
          FROM voters v
          LEFT JOIN elections e ON v.election_id = e.id
         WHERE v.id = $1
    ", [$id]);
    if (pg_num_rows($voter_q) > 0) $voter = pg_fetch_assoc($voter_q);
}

// Flash messages from URL
$flashMessage = null;
$flashType = null;
if (isset($_GET['generated']) && $_GET['generated'] == 1) {
    $flashMessage = "Voter accounts generated successfully!";
    $flashType = "success";
} elseif (isset($_GET['reset']) && $_GET['reset'] == 1) {
    $flashMessage = "Password reset successfully!";
    $flashType = "success";
} elseif (isset($_GET['error'])) {
    $flashMessage = "An error occurred.";
    $flashType = "error";
}

$page_title = 'Voter Credentials - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Voter Credentials</title>
    <link rel="icon" type="image/png" href="../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        (function() {
            if (localStorage.getItem('vms-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <style>
        /* ========================================
           DEEP BLUE THEME – VOTERS CREDENTIALS VIEW
        ======================================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 3px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; min-height: 100vh; background: var(--bg-body); color: var(--text-primary); transition: background 0.3s, color 0.3s; font-size: 14px; line-height: 1.5; }
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255,255,255,0.96);
            --bg-panel: rgba(248,250,255,0.95);
            --bg-input: #f1f5f9;
            --bg-hover: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #475569;
            --border-color: rgba(0,0,0,0.12);
            --accent: #3b82f6;
            --accent-primary: #1a237e;
            --accent-primary-hover: #283593;
            --shadow: 0 2px 12px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.1);
            --topbar-bg: rgba(255,255,255,0.9);
            --input-border: #cbd5e1;
            --label-color: #1e293b;
            --placeholder-color: #94a3b8;
        }
        [data-theme="dark"] {
            --bg-body: #060d1f;
            --bg-card: rgba(15,30,74,0.96);
            --bg-panel: rgba(20,24,36,0.95);
            --bg-input: #1e2538;
            --bg-hover: #1e2538;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: rgba(255,255,255,0.12);
            --accent: #60a5fa;
            --accent-primary: #3b82f6;
            --accent-primary-hover: #60a5fa;
            --shadow: 0 4px 24px rgba(0,0,0,0.4);
            --shadow-md: 0 6px 28px rgba(0,0,0,0.5);
            --topbar-bg: #0f1e4a;
            --input-border: #334155;
            --label-color: #e2e8f0;
            --placeholder-color: #64748b;
        }
        .bg-layer { position: fixed; inset: 0; background: url('../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.85); }
        [data-theme="dark"] .bg-layer::before { background: rgba(6,13,31,0.88); }
        .sidebar { width: 240px; min-height: 100vh; background: #0f1e4a; display: flex; flex-direction: column; position: fixed; top:0; left:0; z-index:100; transition: transform 0.3s; }
        [data-theme="dark"] .sidebar { border-right: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header .logo-row { display: flex; align-items: center; gap: 10px; }
        .sidebar-header img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5)); }
        .sidebar-header h2 { font-size: 14px; font-weight: 800; color: white; line-height: 1.2; }
        .sidebar-header small { font-size: 10px; color: rgba(255,255,255,0.4); display: block; }
        .sidebar-nav { flex:1; padding: 14px 10px; display: flex; flex-direction: column; gap: 3px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 13px; border-radius: 10px; text-decoration: none; color: rgba(255,255,255,0.75); font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a.active { background: rgba(59,130,246,0.25); color: #90caf9; border: 1px solid rgba(59,130,246,0.3); }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 13px; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 6px 0; }
        .sidebar-nav a.logout-link { color: rgba(248,113,113,0.8); }
        .sidebar-nav a.logout-link:hover { background: rgba(239,68,68,0.15); color: #f87171; }
        .sidebar-footer { padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 10px; color: rgba(255,255,255,0.3); text-align: center; }
        .admin-wrapper { display: flex; min-height: 100vh; position: relative; z-index:1; }
        .main-content { margin-left: 240px; flex:1; display: flex; flex-direction: column; }
        .topbar { padding: 14px 26px; background: var(--topbar-bg); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; position: sticky; top:0; z-index:50; box-shadow: var(--shadow); }
        .topbar h1 { font-size: 17px; font-weight: 800; color: var(--text-primary); }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-user { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .topbar-user .avatar { width: 30px; height: 30px; border-radius: 50%; background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.3); display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--accent); }
        .topbar-btn { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); padding: 6px 10px; cursor: pointer; font-size: 13px; transition: all 0.2s; }
        .topbar-btn:hover { color: var(--accent); border-color: var(--accent); }
        .content-area { padding: 24px 26px; flex:1; }
        .credentials-card {
            background: var(--bg-card);
            border-radius: 20px;
            box-shadow: var(--shadow-md);
            max-width: 1000px;
            margin: 0 auto;
            overflow: hidden;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
        }
        .credentials-header {
            background: linear-gradient(135deg, #0f1e4a, #1a237e);
            color: white;
            padding: 32px;
            text-align: center;
        }
        .credentials-header h2 { font-size: 24px; font-weight: 700; margin-top: 12px; }
        .credentials-header p { font-size: 14px; opacity: 0.85; margin-top: 6px; }
        .credentials-body { padding: 28px; }
        .credential-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            background: var(--bg-panel);
            border-radius: 14px;
            margin-bottom: 14px;
            border: 1px solid var(--border-color);
        }
        .credential-row .label {
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }
        .credential-row .value {
            font-weight: 600;
            font-size: 16px;
            color: var(--text-primary);
        }
        .credential-row .value.password {
            background: #fff0db;
            color: #b83c00;
            padding: 8px 16px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.5px;
        }
        [data-theme="dark"] .credential-row .value.password { background: #2a1e0c; color: #ffaa66; }
        .copy-btn {
            background: var(--accent-primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .copy-btn:hover { background: var(--accent-primary-hover); transform: translateY(-1px); }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary { background: #1a237e; color: white; }
        .btn-primary:hover { background: #283593; transform: translateY(-1px); }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-card); }
        .btn-warning { background: #e65100; color: white; }
        .btn-warning:hover { background: #f97316; }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; transform: translateY(-1px); }
        .btn-info { background: #0284c7; color: white; }
        .btn-info:hover { background: #0369a1; transform: translateY(-1px); }
        .action-buttons { display: flex; gap: 14px; margin-top: 24px; flex-wrap: wrap; justify-content: center; }
        .warning-box {
            background: #fff8e1;
            border-radius: 14px;
            padding: 18px;
            margin-top: 24px;
            display: flex;
            gap: 14px;
            font-size: 13px;
            color: #b83c00;
            border-left: 4px solid #e65100;
        }
        [data-theme="dark"] .warning-box { background: #2d2208; color: #ffb347; border-left-color: #ff9800; }
        .bulk-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .bulk-table th, .bulk-table td { padding: 14px 12px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .bulk-table th { background: var(--bg-panel); font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: var(--text-muted); }
        .bulk-table td { font-size: 14px; color: var(--text-primary); }
        .bulk-table .password-cell {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            background: #fff0db;
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            color: #b83c00;
        }
        [data-theme="dark"] .bulk-table .password-cell { background: #2a1e0c; color: #ffaa66; }
        .badge { background: var(--bg-input); padding: 4px 10px; border-radius: 20px; font-family: monospace; font-size: 13px; font-weight: 600; }
        .hamburger { display: none; background: none; border: none; color: var(--text-primary); font-size: 20px; cursor: pointer; padding: 4px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
            .bulk-table th, .bulk-table td { padding: 10px 8px; font-size: 12px; }
            .bulk-table .password-cell { font-size: 11px; padding: 2px 8px; }
            .credential-row { flex-direction: column; align-items: flex-start; gap: 12px; }
            .credential-row .value.password { font-size: 14px; }
        }
        @media print {
            .sidebar, .topbar, .action-buttons, .copy-btn, .topbar-btn, .hamburger, .warning-box { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .credentials-card { box-shadow: none; border: 1px solid #ccc; }
            .bulk-table td { color: black; }
        }
    
        /* ADMIN SIDEBAR THEME OVERRIDE */
        :root {
            --sidebar-bg: rgba(255, 255, 255, 0.94);
            --sidebar-text: #334155;
            --sidebar-heading: #0f172a;
            --sidebar-muted: #64748b;
            --sidebar-border: rgba(15, 23, 42, 0.10);
            --sidebar-hover: rgba(59, 130, 246, 0.10);
            --sidebar-active-bg: rgba(59, 130, 246, 0.14);
            --sidebar-active-text: #1d4ed8;
            --sidebar-divider: rgba(15, 23, 42, 0.10);
        }
        [data-theme="dark"] {
            --sidebar-bg: #0f1e4a;
            --sidebar-text: rgba(255, 255, 255, 0.72);
            --sidebar-heading: #ffffff;
            --sidebar-muted: rgba(255, 255, 255, 0.42);
            --sidebar-border: rgba(255, 255, 255, 0.08);
            --sidebar-hover: rgba(255, 255, 255, 0.10);
            --sidebar-active-bg: rgba(59, 130, 246, 0.25);
            --sidebar-active-text: #90caf9;
            --sidebar-divider: rgba(255, 255, 255, 0.08);
        }
        .sidebar {
            background: var(--sidebar-bg) !important;
            border-right: 1px solid var(--sidebar-border) !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.10);
        }
        .sidebar-header { border-bottom-color: var(--sidebar-border) !important; }
        .sidebar-header h2 { color: var(--sidebar-heading) !important; }
        .sidebar-header small,
        .sidebar-footer { color: var(--sidebar-muted) !important; }
        .sidebar-nav a { color: var(--sidebar-text) !important; }
        .sidebar-nav a:hover {
            background: var(--sidebar-hover) !important;
            color: var(--sidebar-heading) !important;
        }
        .sidebar-nav a.active {
            background: var(--sidebar-active-bg) !important;
            color: var(--sidebar-active-text) !important;
            border-color: rgba(59, 130, 246, 0.30) !important;
        }
        .nav-divider { background: var(--sidebar-divider) !important; }
        .sidebar-footer { border-top-color: var(--sidebar-border) !important; }</style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="admin-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-row">
                <img src="../<?php echo site_favicon(); ?>" alt="OLLCF">
                <div><h2>Voting System</h2><small>Admin Panel</small></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../elections/index.php"><i class="fas fa-calendar-alt"></i> Elections</a>
            <a href="../party_lists/index.php"><i class="fas fa-list-ul"></i> Party Lists</a>
            <a href="../positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
            <a href="../candidates/index.php"><i class="fas fa-users"></i> Candidates</a>
            <a href="../voters/index.php" class="active"><i class="fas fa-user-check"></i> Voters</a>
            <a href="../results/live.php"><i class="fas fa-chart-bar"></i> Live Results</a>
            <div class="nav-divider"></div>
            <a href="../settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="#" class="logout-link" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">© <?php echo date('Y'); ?> <?php echo site_name(); ?> Information Systems</div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                <h1>Voter Credentials</h1>
            </div>
            <div class="topbar-right">
                <div class="topbar-user"><div class="avatar"><i class="fas fa-user-shield"></i></div><span><?php echo h($_SESSION['admin_full_name']); ?></span></div>
                <button class="topbar-btn" onclick="toggleTheme()" title="Toggle Theme"><i class="fas fa-moon" id="themeIcon"></i></button>
                <button class="topbar-btn" onclick="confirmLogout(event)" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>

        <div class="content-area">
            <div class="credentials-card">
                <div class="credentials-header">
                    <div class="icon" style="font-size: 52px;">🔑</div>
                    <h2>Login Credentials</h2>
                    <p>Provide these to the voter for login</p>
                </div>
                <div class="credentials-body">
                    <?php if ($bulkCredentials && is_array($bulkCredentials) && count($bulkCredentials) > 0): ?>
                        <p style="margin-bottom: 16px; font-weight: 500;"><strong><?php echo count($bulkCredentials); ?></strong> accounts generated successfully.</p>
                        <div style="overflow-x: auto;">
                            <table class="bulk-table" id="credentialsTable">
                                <thead>
                                    <tr><th>#</th><th>Election</th><th>Voter ID</th><th>Password</th><th>Full Name</th><th>Grade/Section</th></tr>
                                </thead>
                                <tbody>
                                    <?php $counter = 1; foreach ($bulkCredentials as $cred): ?>
                                    <tr>
                                        <td><?php echo $counter++; ?></td>
                                        <td><?php echo h($cred['election_title'] ?? '—'); ?></td>
                                        <td><span class="badge"><?php echo h($cred['voter_id']); ?></span></td>
                                        <td><span class="password-cell"><?php echo h($cred['password']); ?></span></td>
                                        <td><?php echo h($cred['full_name']); ?></td>
                                        <td><?php echo h($cred['grade_section'] ?: '—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="action-buttons">
                            <form method="POST" action="download_credentials.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="password" name="admin_password" required placeholder="Admin password" style="padding:10px 12px;border-radius:10px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-file-word"></i> Download as Word (.docx)</button>
                            </form>
                            <button onclick="downloadTableAsCSV()" class="btn btn-info"><i class="fas fa-download"></i> Download CSV</button>
                            <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print</button>
                            <a href="index.php" class="btn btn-primary"><i class="fas fa-check"></i> Done</a>
                        </div>
                        <div class="warning-box">
                            <i class="fas fa-shield-alt" style="font-size: 18px;"></i>
                            <span><strong>Important:</strong> These credentials are shown only once. Save or print them now. Passwords cannot be recovered later.</span>
                        </div>
                    <?php elseif ($resetCredentials): ?>
                        <div class="credential-row">
                            <div><div class="label">Voter ID</div><div class="value"><?php echo htmlspecialchars($resetCredentials['voter_id']); ?></div></div>
                            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($resetCredentials['voter_id']); ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                        <div class="credential-row">
                            <div><div class="label">Password</div><div class="value password"><?php echo htmlspecialchars($resetCredentials['password']); ?></div></div>
                            <button class="copy-btn" onclick="copyText('<?php echo htmlspecialchars($resetCredentials['password']); ?>', this)"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                        <div class="credential-row">
                            <div><div class="label">Full Name</div><div class="value"><?php echo htmlspecialchars($resetCredentials['full_name']); ?></div></div>
                        </div>
                        <?php if (!empty($resetCredentials['election_title'])): ?>
                            <div class="credential-row">
                                <div><div class="label">Election</div><div class="value"><?php echo htmlspecialchars($resetCredentials['election_title']); ?></div></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($resetCredentials['grade_section']): ?>
                            <div class="credential-row">
                                <div><div class="label">Grade / Section</div><div class="value"><?php echo htmlspecialchars($resetCredentials['grade_section']); ?></div></div>
                            </div>
                        <?php endif; ?>
                        <div class="action-buttons">
                            <form method="POST" action="download_credentials.php" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="password" name="admin_password" required placeholder="Admin password" style="padding:10px 12px;border-radius:10px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);">
                                <button type="submit" class="btn btn-success"><i class="fas fa-file-word"></i> Download Word</button>
                            </form>
                            <button onclick="downloadSingleCredential()" class="btn btn-info"><i class="fas fa-download"></i> Download CSV</button>
                            <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print</button>
                            <a href="index.php" class="btn btn-primary"><i class="fas fa-check"></i> Done</a>
                        </div>
                        <div class="warning-box">
                            <i class="fas fa-shield-alt"></i>
                            <span><strong>Important:</strong> These credentials are shown only once. Save or print them now. Passwords cannot be recovered after you leave this page.</span>
                        </div>
                    <?php elseif ($voter): ?>
                        <div style="text-align:center; padding:24px;">
                            <i class="fas fa-lock" style="font-size:52px; opacity:0.5; display:block; margin-bottom:16px;"></i>
                            <p style="font-size:15px;">Password is encrypted and cannot be viewed.</p>
                            <p style="font-size:13px; margin-top:8px; color: var(--text-muted);">You can <strong>reset the password</strong> to generate a new one.</p>
                            <a href="reset_password.php?id=<?php echo $voter['id']; ?>" class="btn btn-warning" style="margin-top:20px; display:inline-flex; align-items:center; gap:6px;" onclick="return confirm('Reset password for <?php echo htmlspecialchars($voter['voter_id']); ?>?')"><i class="fas fa-key"></i> Reset Password</a>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; padding:32px;">
                            <i class="fas fa-exclamation-circle" style="font-size:52px; opacity:0.4;"></i>
                            <p style="margin-top:12px;">No credentials to display.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // ==================== THEME & UTILITY FUNCTIONS ====================
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
        const themeIcon = document.getElementById('themeIcon');
        if (themeIcon) {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }
    function confirmLogout(event) {
        event.preventDefault();
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        Swal.fire({
            title: 'Logging Out?',
            text: 'End your admin session?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'transparent',
            confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout',
            cancelButtonText: 'Cancel',
            background: isDark ? '#0f1e4a' : '#fff',
            color: isDark ? '#e2e8f0' : '#1e293b'
        }).then(result => { if (result.isConfirmed) window.location.href = '../logout.php'; });
    }
    function copyText(text, btn) {
        navigator.clipboard.writeText(text).then(() => {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => btn.innerHTML = originalHtml, 2000);
        });
    }

    // ==================== WORD EXPORT USING YOUR EXACT TEMPLATE ====================
    // The template string below is your Word document exported as HTML (filtered)
    // We inserted placeholders: {{CREDENTIALS_TABLE_ROWS}} for bulk, and individual placeholders for single.
    // Also {{GENERATED_DATE}} is replaced with current date/time.

    const wordTemplate = `<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns:m="http://schemas.microsoft.com/office/2004/12/omml" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<title>Voter Credentials</title>
<style>
 /* Your exact Word styles – preserved from the original template */
 body {
    margin: 1.0in 1.0in 1.0in 1.0in;
    font-family: 'Calibri', sans-serif;
    font-size: 11pt;
 }
 .header {
    text-align: center;
    margin-bottom: 20px;
 }
 .header h1 {
    font-size: 18pt;
    font-weight: bold;
    margin: 0;
 }
 .header h2 {
    font-size: 14pt;
    margin: 5px 0;
 }
 .header p {
    font-size: 12pt;
    margin: 0;
 }
 table {
    border-collapse: collapse;
    width: 100%;
    margin-top: 20px;
 }
 th, td {
    border: 1px solid black;
    padding: 6px;
 }
 th {
    background: #1a237e;
    color: white;
 }
 .password-cell {
    font-family: 'Courier New', monospace;
    font-weight: bold;
 }
 .footer {
    margin-top: 30px;
    font-size: 10pt;
    text-align: center;
    border-top: 1px solid #ccc;
    padding-top: 10px;
 }
 .warning {
    margin-top: 20px;
    background: #fff8e1;
    border-left: 4px solid #e65100;
    padding: 8px;
    font-size: 10pt;
 }
</style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
<div class="header">
    <h1>Management Information System & IT Services</h1>
    <h2>ICT Equipment & Facilities</h2>
    <p>Our Lady of Lourdes College Foundation</p>
</div>
<p><strong>Generated on:</strong> {{GENERATED_DATE}}</p>
{{BULK_TABLE}}
{{SINGLE_CREDENTIAL}}
<div class="footer">Confidential – For authorized use only</div>
<div class="warning"><strong>Important:</strong> These credentials are shown only once. Save or print this document. Passwords cannot be recovered later.</div>
</body>
</html>`;

    function downloadTableAsWord() {
        const table = document.getElementById('credentialsTable');
        if (!table) return;
        
        // Collect credential data from the displayed table
        const credentials = [];
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            credentials.push({
                election: cells[1]?.innerText.trim() || '',
                voter_id: cells[2]?.innerText.trim() || '',
                password: cells[3]?.innerText.trim() || '',
                full_name: cells[4]?.innerText.trim() || '',
                grade_section: cells[5]?.innerText.trim() || '—'
            });
        });
        
        // Build the bulk table HTML
        let tableHtml = `<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse;">
            <thead><tr><th>#</th><th>Election</th><th>Voter ID</th><th>Password</th><th>Full Name</th><th>Grade/Section</th></tr></thead><tbody>`;
        credentials.forEach((cred, idx) => {
            tableHtml += `<tr>
                <td>${idx+1}</td>
                <td>${escapeHtml(cred.election)}</td>
                <td>${escapeHtml(cred.voter_id)}</td>
                <td><span class="password-cell">${escapeHtml(cred.password)}</span></td>
                <td>${escapeHtml(cred.full_name)}</td>
                <td>${escapeHtml(cred.grade_section)}</td>
            </tr>`;
        });
        tableHtml += `</tbody></table>`;
        
        let finalHtml = wordTemplate.replace(/{{GENERATED_DATE}}/g, new Date().toLocaleString());
        finalHtml = finalHtml.replace(/{{BULK_TABLE}}/g, tableHtml);
        finalHtml = finalHtml.replace(/{{SINGLE_CREDENTIAL}}/g, '');
        
        const blob = new Blob([finalHtml], { type: 'application/msword' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'voter_credentials.doc';
        link.click();
        URL.revokeObjectURL(link);
    }

    function downloadSingleWord() {
        const rows = document.querySelectorAll('.credential-row');
        const credential = {
            voter_id: rows[0]?.querySelector('.value')?.innerText.trim() || '',
            password: rows[1]?.querySelector('.value.password')?.innerText.trim() || '',
            full_name: rows[2]?.querySelector('.value')?.innerText.trim() || '',
            grade_section: rows[3]?.querySelector('.value')?.innerText.trim() || ''
        };
        
        let singleHtml = `<div style="margin-top:20px;">
            <p><strong>Voter ID:</strong> ${escapeHtml(credential.voter_id)}</p>
            <p><strong>Password:</strong> <span class="password-cell">${escapeHtml(credential.password)}</span></p>
            <p><strong>Full Name:</strong> ${escapeHtml(credential.full_name)}</p>
            <p><strong>Grade/Section:</strong> ${escapeHtml(credential.grade_section)}</p>
        </div>`;
        
        let finalHtml = wordTemplate.replace(/{{GENERATED_DATE}}/g, new Date().toLocaleString());
        finalHtml = finalHtml.replace(/{{BULK_TABLE}}/g, '');
        finalHtml = finalHtml.replace(/{{SINGLE_CREDENTIAL}}/g, singleHtml);
        
        const blob = new Blob([finalHtml], { type: 'application/msword' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'voter_credential.doc';
        link.click();
        URL.revokeObjectURL(link);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    // ==================== CSV EXPORT (unchanged) ====================
    function downloadTableAsCSV() {
        const table = document.getElementById('credentialsTable');
        if (!table) return;
        const rows = table.querySelectorAll('tr');
        let csv = [];
        // header
        const headers = [];
        const headerCells = rows[0].querySelectorAll('th');
        headerCells.forEach(cell => headers.push(cell.innerText.trim()));
        csv.push(headers.join(','));
        // data rows
        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            const cells = row.querySelectorAll('td');
            const rowData = [];
            cells.forEach(cell => {
                let text = cell.innerText.trim();
                if (cell.querySelector('.badge')) text = cell.querySelector('.badge').innerText.trim();
                if (cell.querySelector('.password-cell')) text = cell.querySelector('.password-cell').innerText.trim();
                text = text.replace(/"/g, '""');
                if (text.includes(',') || text.includes('"') || text.includes('\n')) text = '"' + text + '"';
                rowData.push(text);
            });
            csv.push(rowData.join(','));
        }
        const blob = new Blob(["\uFEFF" + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'voter_credentials_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.csv';
        link.click();
        URL.revokeObjectURL(link);
    }

    function downloadSingleCredential() {
        const rows = document.querySelectorAll('.credential-row');
        const csvRows = [['Field', 'Value']];
        rows.forEach(row => {
            const label = row.querySelector('.label')?.innerText.trim() || '';
            let value = row.querySelector('.value')?.innerText.trim() || '';
            if (row.querySelector('.value.password')) value = row.querySelector('.value.password').innerText.trim();
            if (label && value) {
                csvRows.push([label, value]);
            }
        });
        const blob = new Blob(["\uFEFF" + csvRows.map(row => row.map(cell => `"${cell.replace(/"/g, '""')}"`).join(',')).join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'voter_credential_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.csv';
        link.click();
        URL.revokeObjectURL(link);
    }

    // ==================== MOBILE SIDEBAR ====================
    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (hamburger) {
        hamburger.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });
    }
    updateThemeIcon();

    // ==================== SWEETALERT TOAST ====================
    <?php if ($flashMessage): ?>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: '<?= $flashType ?>',
            title: <?= json_encode($flashMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#0f1e4a' : '#ffffff',
            color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b'
        });
    });
    <?php endif; ?>
</script>
</body>
</html>
