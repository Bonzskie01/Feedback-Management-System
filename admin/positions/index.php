<?php
// admin/positions/index.php

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$election_id = isset($_GET['election_id']) ? intval($_GET['election_id']) : 0;
$elections_list = pg_query($conn, "SELECT id, title, status FROM elections ORDER BY created_at DESC");

if ($election_id > 0) {
    $positions_result = pg_query_params($conn,
        "SELECT p.*, e.title as election_title, (SELECT COUNT(*) FROM candidates WHERE position_id = p.id) as candidate_count
         FROM positions p
         JOIN elections e ON p.election_id = e.id
         WHERE p.election_id = $1
         ORDER BY p.display_order ASC",
        [$election_id]
    );
} else {
    $positions_result = pg_query($conn,
        "SELECT p.*, e.title as election_title, (SELECT COUNT(*) FROM candidates WHERE position_id = p.id) as candidate_count
         FROM positions p
         JOIN elections e ON p.election_id = e.id
         ORDER BY e.created_at DESC, p.display_order ASC"
    );
}

// Flash messages from URL parameters
$flashMessage = null;
$flashType = null;
if (isset($_GET['created']) && $_GET['created'] == 1) {
    $flashMessage = "Position created successfully!";
    $flashType = "success";
} elseif (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $flashMessage = "Position updated successfully!";
    $flashType = "success";
} elseif (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $flashMessage = "Position deleted successfully!";
    $flashType = "success";
} elseif (isset($_GET['error'])) {
    $flashMessage = "An error occurred. Please try again.";
    $flashType = "error";
}

$page_title = 'Positions Management - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Candidates Position Management</title>
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
        /* ========================================
           GLOBAL STYLES - DEEP BLUE SIDEBAR + VISIBLE BACKGROUND
           (same as original index.php – keep all CSS)
        ======================================== */
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
            --input-border: #cbd5e1; --table-head: rgba(241,245,251,0.9); --table-row-hover: rgba(245,247,255,0.8);
            --label-color: #334155; --placeholder-color: #94a3b8;
        }
        [data-theme="dark"] {
            --bg-body: #060d1f; --bg-card: #0f1e4a; --bg-panel: rgba(20,24,36,0.9); --bg-input: #252a3a;
            --bg-hover: #252a3a; --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: rgba(255,255,255,0.1); --accent: #60a5fa; --accent-primary: #3b82f6;
            --accent-primary-hover: #60a5fa; --shadow: 0 4px 24px rgba(0,0,0,0.4); --shadow-md: 0 6px 28px rgba(0,0,0,0.5);
            --topbar-bg: #0f1e4a; --input-border: #3d435a; --table-head: #0f1e4a; --table-row-hover: rgba(30,36,56,0.8);
            --label-color: #cbd5e1; --placeholder-color: #64748b;
        }
        .bg-layer { position: fixed; inset: 0; background: url('../../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; transition: opacity 0.3s; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(6,13,31,0.85); }
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
        .admin-wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; }
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
        .page-hdr h2 i { color: var(--accent); }
        .page-hdr p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        /* Removed .alert styles since no inline alerts */
        .toolbar { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; box-shadow: var(--shadow); backdrop-filter: blur(4px); }
        .toolbar-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .filter-select { padding: 10px 32px 10px 16px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 13px; font-family: inherit; font-weight: 500; cursor: pointer; outline: none; transition: all 0.2s ease; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; background-size: 14px; }
        [data-theme="dark"] .filter-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); }
        .filter-select:hover { border-color: var(--accent); background-color: var(--bg-hover); }
        .filter-select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.2); }
        .filter-select option { background: var(--bg-card); color: var(--text-primary); padding: 8px; }
        [data-theme="dark"] .filter-select option { background: #1a1f2e; color: #f1f5f9; }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; font-family: inherit; transition: all 0.2s; }
        .btn-primary { background: #1a237e; color: white; }
        .btn-primary:hover { background: #283593; transform: translateY(-1px); }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; transform: translateY(-1px); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-card); }
        .btn-info { background: #0284c7; color: white; }
        .btn-info:hover { background: #0369a1; transform: translateY(-1px); }
        .btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 7px; }
        .data-table-panel { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); backdrop-filter: blur(4px); }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .data-table thead th { background: var(--table-head); padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: var(--text-muted); border-bottom: 1px solid var(--border-color); }
        .data-table tbody tr { border-bottom: 1px solid var(--border-color); transition: background 0.15s; }
        .data-table tbody tr:hover { background: var(--table-row-hover); }
        .data-table tbody td { padding: 12px 16px; color: var(--text-primary); vertical-align: middle; }
        .empty-state { text-align: center; padding: 48px 24px; }
        .empty-state i { font-size: 44px; color: var(--text-muted); margin-bottom: 14px; display: block; opacity: 0.5; }
        .empty-state h3 { font-size: 17px; margin-bottom: 8px; color: var(--text-primary); }
        .empty-state p { font-size: 13px; color: var(--text-muted); margin-bottom: 18px; }
        .hamburger { display: none; background: none; border: none; color: var(--text-primary); font-size: 20px; cursor: pointer; padding: 4px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
            .toolbar-row { flex-direction: column; align-items: stretch; }
            .filter-select { width: 100%; }
            .btn-success { justify-content: center; }
            .data-table thead th, .data-table tbody td { padding: 10px 12px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
    <div class="bg-layer"></div>

    <div class="admin-wrapper">
        <!-- SIDEBAR (same as original) -->
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

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="topbar">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                    <h1><i class="fas fa-trophy" style="font-size: 14px; opacity: 0.6; margin-right: 4px;"></i> Positions</h1>
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
                <!-- PAGE HEADER -->
                <div class="page-hdr">
                    <h2><i class="fas fa-trophy"></i> Positions Management</h2>
                    <p>Manage positions for each election</p>
                </div>

                <!-- TOOLBAR -->
                <div class="toolbar">
                    <div class="toolbar-row">
                        <form method="GET" action="">
                            <select name="election_id" class="filter-select" onchange="this.form.submit()">
                                <option value="">All Elections</option>
                                <?php while ($el = pg_fetch_assoc($elections_list)): ?>
                                    <option value="<?php echo $el['id']; ?>" <?php echo $election_id == $el['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($el['title']); ?> (<?php echo ucfirst($el['status']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </form>
                        <a href="create.php<?php echo $election_id > 0 ? '?election_id=' . $election_id : ''; ?>" class="btn btn-success">
                            <i class="fas fa-plus-circle"></i> Add Position
                        </a>
                    </div>
                </div>

                <!-- DATA TABLE -->
                <div class="data-table-panel">
                    <?php if ($positions_result && pg_num_rows($positions_result) > 0): ?>
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr><th>#</th><th>Position Name</th><th>Election</th><th>Max Winners</th><th>Order</th><th>Candidates</th><th style="text-align: center;">Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php $num = 1; while ($position = pg_fetch_assoc($positions_result)): ?>
                                        <tr>
                                            <td style="color: var(--text-muted);"><?php echo $num++; ?></td>
                                            <td><strong><?php echo h($position['name']); ?></strong></td>
                                            <td style="color: var(--text-secondary);"><?php echo h($position['election_title']); ?></td>
                                            <td><?php echo $position['max_winners']; ?></td>
                                            <td><?php echo $position['display_order']; ?></td>
                                            <td><?php echo $position['candidate_count']; ?></td>
                                            <td style="text-align: center;">
                                                <div style="display: flex; gap: 5px; justify-content: center;">
                                                    <a href="../candidates/index.php?position_id=<?php echo $position['id']; ?>" class="btn btn-info btn-sm" title="View Candidates"><i class="fas fa-users"></i></a>
                                                    <a href="edit.php?id=<?php echo $position['id']; ?>" class="btn btn-secondary btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                                    <form method="POST" action="delete.php" class="secure-delete-form" style="display:inline-flex; margin:0;">
                                                        <input type="hidden" name="id" value="<?php echo (int)$position['id']; ?>">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="admin_password" value="">
                                                        <button type="submit" class="btn btn-danger btn-sm" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-trophy"></i>
                            <h3>No positions found</h3>
                            <p>Select an election and add positions for it.</p>
                            <a href="create.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> Add Position</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleTheme() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
            localStorage.setItem('vms-theme', isDark ? 'light' : 'dark');
            updateThemeIcon();
        }
        function updateThemeIcon() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const themeIcon = document.getElementById('themeIcon');
            if (themeIcon) themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
        function swalConfirm(event, title, text, action, icon) {
            event.preventDefault();
            const href = event.currentTarget.href;
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            let confirmButtonColor = '#1a237e';
            if (action === 'delete' || action === 'close') confirmButtonColor = '#dc2626';
            else if (action === 'pause') confirmButtonColor = '#d97706';
            Swal.fire({
                title: title, text: text, icon: icon, showCancelButton: true,
                confirmButtonColor: confirmButtonColor, cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-check"></i> Yes, proceed',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel',
                background: isDark ? '#0f1e4a' : '#ffffff', color: isDark ? '#f1f5f9' : '#1e293b',
                backdrop: 'rgba(0,0,0,0.4)'
            }).then(result => { if (result.isConfirmed) window.location.href = href; });
            return false;
        }
        function confirmLogout(event) {
            event.preventDefault();
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            Swal.fire({
                title: '<i class="fas fa-sign-out-alt"></i> Logging Out?',
                text: 'Are you sure you want to end your admin session?', icon: 'question',
                showCancelButton: true, confirmButtonColor: '#ef4444',
                confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel',
                background: isDark ? '#0f1e4a' : '#ffffff', color: isDark ? '#f1f5f9' : '#1e293b'
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

        // SweetAlert toast for flash messages
        <?php if ($flashMessage): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                toast: true, position: 'top-end', icon: '<?= $flashType ?>',
                title: <?= json_encode($flashMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, showConfirmButton: false,
                timer: 3000, timerProgressBar: true,
                background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#0f1e4a' : '#ffffff',
                color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b'
            });
        });
        <?php endif; ?>

        async function promptAdminPasswordAndSubmit(form) {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const result = await Swal.fire({
                title: 'Confirm Delete',
                text: 'Enter your admin password to continue.',
                icon: 'warning',
                input: 'password',
                inputPlaceholder: 'Your admin password',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel',
                background: isDark ? '#0f1e4a' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#1e293b',
                inputAttributes: { autocapitalize: 'off', autocomplete: 'off' }
            });
            if (!result.value) return false;
            form.querySelector('input[name="admin_password"]').value = result.value;
            form.submit();
            return false;
        }

        document.querySelectorAll('.secure-delete-form').forEach(form => {
            form.addEventListener('submit', event => {
                event.preventDefault();
                promptAdminPasswordAndSubmit(form);
            });
        });

    </script>
</body>
</html>
