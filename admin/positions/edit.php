<?php
// admin/positions/edit.php

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    redirect('index.php?error=1');
}

$pos_sql = "SELECT p.*, e.title as election_title FROM positions p JOIN elections e ON p.election_id = e.id WHERE p.id = $1";
$pos_result = pg_query_params($conn, $pos_sql, array($id));
if (pg_num_rows($pos_result) === 0) {
    redirect('index.php?error=1');
}
$position = pg_fetch_assoc($pos_result);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $name = trim($_POST['name'] ?? '');
    $max_winners = intval($_POST['max_winners'] ?? 1);
    $display_order = intval($_POST['display_order'] ?? 0);

    if (!election_is_mutable($conn, (int)$position['election_id'])) {
        $error = 'Position changes are locked for this election.';
    } elseif (empty($name) || $max_winners < 1 || $max_winners > 20 || $display_order < 0) {
        $error = 'Please enter valid position details.';
    } else {
        $update_sql = "UPDATE positions SET name = $1, max_winners = $2, display_order = $3 WHERE id = $4";
        $result = pg_query_params($conn, $update_sql, array($name, $max_winners, $display_order, $id));

        if ($result) {
            audit_log($conn, 'position_updated', 'position', $id);
            redirect('index.php?updated=1');
        } else {
            $error = 'Failed to update position.';
        }
    }
}

$page_title = 'Edit Position - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Edit Positions</title>
    <link rel="icon" type="image/png" href="../../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
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
        /* Same global deep blue CSS as create.php – keep all styles */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 3px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; min-height: 100vh; background: var(--bg-body); color: var(--text-primary); transition: background 0.3s, color 0.3s; }
        :root {
            --bg-body: #f0f4ff; --bg-card: rgba(255,255,255,0.92); --bg-panel: rgba(248,250,255,0.9);
            --bg-input: #f1f5f9; --bg-hover: #f1f5f9; --text-primary: #1e293b; --text-secondary: #475569;
            --text-muted: #64748b; --border-color: rgba(0,0,0,0.1); --accent: #3b82f6;
            --accent-primary: #1a237e; --accent-primary-hover: #283593; --shadow: 0 2px 12px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.1); --topbar-bg: rgba(255,255,255,0.85);
            --input-border: #cbd5e1; --label-color: #334155; --placeholder-color: #94a3b8;
        }
        [data-theme="dark"] {
            --bg-body: #060d1f; --bg-card: #0f1e4a; --bg-panel: rgba(20,24,36,0.9); --bg-input: #252a3a;
            --bg-hover: #252a3a; --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: rgba(255,255,255,0.1); --accent: #60a5fa; --accent-primary: #3b82f6;
            --accent-primary-hover: #60a5fa; --shadow: 0 4px 24px rgba(0,0,0,0.4); --shadow-md: 0 6px 28px rgba(0,0,0,0.5);
            --topbar-bg: #0f1e4a; --input-border: #3d435a; --label-color: #cbd5e1; --placeholder-color: #64748b;
        }
        .bg-layer { position: fixed; inset: 0; background: url('../../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(6,13,31,0.85); }
        .admin-wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; }
        .sidebar { width: 240px; min-height: 100vh; background: #0f1e4a; display: flex; flex-direction: column; position: fixed; top: 0; left: 0; z-index: 100; transition: transform 0.3s; }
        [data-theme="dark"] .sidebar { border-right: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header .logo-row { display: flex; align-items: center; gap: 10px; }
        .sidebar-header img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5)); }
        .sidebar-header h2 { font-size: 14px; font-weight: 800; color: white; line-height: 1.2; }
        .sidebar-header small { font-size: 10px; color: rgba(255,255,255,0.4); display: block; }
        .sidebar-nav { flex: 1; padding: 14px 10px; display: flex; flex-direction: column; gap: 3px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 13px; border-radius: 10px; text-decoration: none; color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a.active { background: rgba(59,130,246,0.25); color: #90caf9; border: 1px solid rgba(59,130,246,0.3); }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 13px; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 6px 0; }
        .sidebar-nav a.logout-link { color: rgba(248,113,113,0.8); }
        .sidebar-nav a.logout-link:hover { background: rgba(239,68,68,0.15); color: #f87171; }
        .sidebar-footer { padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 10px; color: rgba(255,255,255,0.25); text-align: center; }
        .main-content { margin-left: 240px; flex: 1; display: flex; flex-direction: column; }
        .topbar { padding: 14px 26px; background: var(--topbar-bg); backdrop-filter: blur(12px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; box-shadow: var(--shadow); }
        .topbar h1 { font-size: 17px; font-weight: 800; color: var(--text-primary); }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-user { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .topbar-user .avatar { width: 30px; height: 30px; border-radius: 50%; background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.3); display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--accent); }
        .topbar-btn { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); padding: 6px 10px; cursor: pointer; font-size: 13px; transition: all 0.2s; }
        .topbar-btn:hover { color: var(--accent); border-color: var(--accent); }
        .content-area { padding: 24px 26px; flex: 1; }
        .page-hdr { margin-bottom: 20px; }
        .page-hdr h2 { font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 8px; color: var(--text-primary); }
        .page-hdr p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; font-size: 13.5px; background: var(--bg-card); border: 1px solid var(--border-color); }
        .alert-error { border-left: 3px solid #ef4444; }
        .form-section { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); backdrop-filter: blur(4px); }
        .form-section-header { padding: 18px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 12px; }
        .form-section-header h3 { font-size: 16px; color: var(--text-primary); }
        .form-section-body { padding: 24px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: var(--label-color); }
        .form-group input { width: 100%; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 14px; font-family: inherit; transition: all 0.2s; }
        .form-group input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .form-group small { display: block; margin-top: 6px; font-size: 12px; color: var(--text-muted); }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; font-family: inherit; transition: all 0.2s; }
        .btn-primary { background: #1a237e; color: white; }
        .btn-primary:hover { background: #283593; transform: translateY(-1px); }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-card); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--accent); text-decoration: none; font-size: 13px; font-weight: 500; margin-bottom: 12px; transition: all 0.2s; }
        .back-link:hover { gap: 10px; color: var(--accent-primary); }
        .hamburger { display: none; background: none; border: none; color: var(--text-primary); font-size: 20px; cursor: pointer; padding: 4px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="admin-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-row">
                <img src="../../<?php echo site_favicon(); ?>" alt="OLLCF">
                <div><h2>Voting System</h2><small>Admin Panel</small></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../elections/index.php"><i class="fas fa-calendar-alt"></i> Elections</a>
            <a href="../party_lists/index.php"><i class="fas fa-flag"></i> Party Lists</a>
            <a href="index.php" class="active"><i class="fas fa-trophy"></i> Positions</a>
            <a href="../candidates/index.php"><i class="fas fa-users"></i> Candidates</a>
            <a href="../voters/index.php"><i class="fas fa-user-check"></i> Voters</a>
            <a href="../results/live.php"><i class="fas fa-chart-bar"></i> Live Results</a>
            <div class="nav-divider"></div>
            <a href="../settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="../profile.php"><i class="fas fa-shield-alt"></i>2FA</a>
            <a href="#" class="logout-link" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">© <?php echo date('Y'); ?> <?php echo site_name(); ?> Information Systems</div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <h1><i class="fas fa-edit" style="font-size: 14px; opacity: 0.7; margin-right: 4px;"></i> Edit Position</h1>
            </div>
            <div class="topbar-right">
                <div class="topbar-user">
                    <div class="avatar"><i class="fas fa-user-shield"></i></div>
                    <span><?php echo h($_SESSION['admin_full_name']); ?></span>
                </div>
                <button class="topbar-btn" onclick="toggleTheme()" title="Toggle Theme"><i class="fas fa-moon" id="themeIcon"></i></button>
                <button class="topbar-btn" onclick="confirmLogout(event)" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>

        <div class="content-area">
            <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Positions</a>

            <div class="page-hdr">
                <h2><i class="fas fa-edit"></i> Edit Position</h2>
                <p>Update position details</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="form-section">
                <div class="form-section-header">
                    <div><i class="fas fa-trophy" style="color: var(--accent);"></i></div>
                    <div><h3><?php echo h($position['name']); ?></h3><small><?php echo h($position['election_title']); ?></small></div>
                </div>
                <div class="form-section-body">
                    <form method="POST" action="">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label>Position Name *</label>
                            <input type="text" name="name" required value="<?php echo h($position['name']); ?>" placeholder="e.g., President, Vice President">
                        </div>

                        <div class="form-group">
                            <label>Max Winners</label>
                            <input type="number" name="max_winners" value="<?php echo $position['max_winners']; ?>" min="1" required>
                            <small>How many candidates can win? (1 for President, 8 for Senators)</small>
                        </div>

                        <div class="form-group">
                            <label>Display Order</label>
                            <input type="number" name="display_order" value="<?php echo $position['display_order']; ?>" min="0">
                            <small>Lower number = shown first on the ballot</small>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Position</button>
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
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
            title: 'Logging Out?', text: 'End your admin session?', icon: 'question',
            showCancelButton: true, confirmButtonColor: '#ef4444', cancelButtonColor: 'transparent',
            confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout', cancelButtonText: 'Cancel',
            background: isDark ? '#0f1e4a' : '#fff', color: isDark ? '#f1f5f9' : '#1e293b'
        }).then(result => { if (result.isConfirmed) window.location.href = '../logout.php'; });
    }
    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (hamburger) {
        hamburger.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
        overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });
    }
    updateThemeIcon();
</script>
</body>
</html>