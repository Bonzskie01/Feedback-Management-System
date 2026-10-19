<?php
// admin/elections/index.php

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

pg_query($conn, "UPDATE elections SET status = 'closed' WHERE status IN ('ongoing', 'paused') AND end_time < NOW()");
ensure_election_access_columns($conn);

$elections_sql = "SELECT e.*,
                    (SELECT COUNT(*) FROM positions WHERE election_id = e.id) as position_count,
                    (SELECT COUNT(*) FROM candidates c JOIN positions p ON c.position_id = p.id WHERE p.election_id = e.id) as candidate_count,
                    (SELECT COUNT(DISTINCT vt.voter_id) FROM voter_turnout vt WHERE vt.election_id = e.id) as voter_count
                 FROM elections e ORDER BY e.created_at DESC";
$elections_result = pg_query($conn, $elections_sql);

// Flash messages from URL parameters
$flashMessage = null;
$flashType = null;
if (isset($_GET['created']) && $_GET['created'] == 1) {
    $flashMessage = "Election created successfully!";
    $flashType = "success";
} elseif (isset($_GET['updated']) && $_GET['updated'] == 1) {
    $flashMessage = "Election updated successfully!";
    $flashType = "success";
} elseif (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $flashMessage = ($_GET['mode'] ?? '') === 'purge'
        ? "Closed test election and all related records purged successfully!"
        : "Draft election deleted successfully!";
    $flashType = "success";
} elseif (isset($_GET['status_updated']) && $_GET['status_updated'] == 1) {
    $flashMessage = "Election status updated successfully!";
    $flashType = "success";
} elseif (isset($_GET['access_updated']) && $_GET['access_updated'] == 1) {
    $flashMessage = "Voting access updated successfully!";
    $flashType = "success";
} elseif (isset($_GET['error'])) {
    $errorMessages = [
        'security_token' => 'Security token expired. Refresh the page and try again.',
        'invalid_password' => 'Incorrect admin password. Deletion denied.',
        'rate_limited' => 'Too many failed confirmations. Try again later.',
        'election_locked' => 'Only draft or closed elections can be deleted.',
        'confirmation_failed' => 'Closed-election purge confirmation did not match.',
        'not_found' => 'Election not found.',
        'delete_failed' => 'Election purge failed and no partial deletion was committed.',
    ];
    $flashMessage = $errorMessages[$_GET['error']] ?? "An error occurred. Please try again.";
    $flashType = "error";
}

$page_title = 'Elections Management - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Elections Dashboard</title>
    <link rel="icon" type="image/png" href="../../<?php echo site_favicon(); ?>">
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
           GLOBAL STYLES - DEEP BLUE SIDEBAR + VISIBLE BACKGROUND
        ======================================== */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 3px; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
        }
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255,255,255,0.92);
            --bg-panel: rgba(248,250,255,0.9);
            --bg-input: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: rgba(0,0,0,0.1);
            --accent: #3b82f6;
            --accent-primary: #1a237e;
            --accent-primary-hover: #283593;
            --shadow: 0 2px 12px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.1);
            --topbar-bg: rgba(255,255,255,0.85);
            --input-border: #cbd5e1;
            --table-head: rgba(241,245,251,0.9);
            --table-row-hover: rgba(245,247,255,0.8);
            --label-color: #334155;
            --placeholder-color: #94a3b8;
        }
        [data-theme="dark"] {
            --bg-body: #060d1f;
            --bg-card: #0f1e4a;
            --bg-panel: rgba(20,24,36,0.9);
            --bg-input: #252a3a;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: rgba(255,255,255,0.1);
            --accent: #60a5fa;
            --accent-primary: #3b82f6;
            --accent-primary-hover: #60a5fa;
            --shadow: 0 4px 24px rgba(0,0,0,0.4);
            --shadow-md: 0 6px 28px rgba(0,0,0,0.5);
            --topbar-bg: #0f1e4a;
            --input-border: #3d435a;
            --table-head: #0f1e4a;
            --table-row-hover: rgba(30,36,56,0.8);
            --label-color: #cbd5e1;
            --placeholder-color: #64748b;
        }
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('../../<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
            transition: opacity 0.3s;
        }
        :root .bg-layer::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(240,244,255,0.7);
        }
        [data-theme="dark"] .bg-layer::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(6,13,31,0.85);
        }
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
        [data-theme="dark"] .sidebar {
            border-right: 1px solid rgba(255,255,255,0.08);
        }
        .sidebar-header {
            padding: 22px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
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
            filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5));
        }
        .sidebar-header h2 {
            font-size: 14px;
            font-weight: 800;
            color: white;
            line-height: 1.2;
        }
        .sidebar-header small {
            font-size: 10px;
            color: rgba(255,255,255,0.4);
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
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .sidebar-nav a.active {
            background: rgba(59,130,246,0.25);
            color: #90caf9;
            border: 1px solid rgba(59,130,246,0.3);
        }
        .sidebar-nav a i {
            width: 18px;
            text-align: center;
            font-size: 13px;
        }
        .nav-divider {
            height: 1px;
            background: rgba(255,255,255,0.08);
            margin: 6px 0;
        }
        .sidebar-nav a.logout-link {
            color: rgba(248,113,113,0.8);
        }
        .sidebar-nav a.logout-link:hover {
            background: rgba(239,68,68,0.15);
            color: #f87171;
        }
        .sidebar-footer {
            padding: 14px 18px;
            border-top: 1px solid rgba(255,255,255,0.06);
            font-size: 10px;
            color: rgba(255,255,255,0.25);
            text-align: center;
        }
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
            background: rgba(59,130,246,0.15);
            border: 1px solid rgba(59,130,246,0.3);
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
        .content-area {
            padding: 24px 26px;
            flex: 1;
        }
        .page-hdr {
            margin-bottom: 20px;
        }
        .page-hdr h2 {
            font-size: 20px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
        }
        .page-hdr p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .toolbar {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 18px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(4px);
        }
        .toolbar-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .data-table-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--shadow);
            backdrop-filter: blur(4px);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .data-table thead th {
            background: var(--table-head);
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }
        .data-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background 0.15s;
        }
        .data-table tbody tr:hover {
            background: var(--table-row-hover);
        }
        .data-table tbody td {
            padding: 12px 16px;
            color: var(--text-primary);
            vertical-align: middle;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 16px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
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
        .btn-warn { background: #d97706; color: white; }
        .btn-warn:hover { background: #b45309; transform: translateY(-1px); }
        .btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 7px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-ongoing { background: rgba(34,197,94,0.15); color: #16a34a; border: 1px solid rgba(34,197,94,0.3); }
        .badge-published { background: rgba(251,191,36,0.15); color: #d97706; border: 1px solid rgba(251,191,36,0.3); }
        .badge-draft { background: rgba(148,163,184,0.15); color: #64748b; border: 1px solid rgba(148,163,184,0.3); }
        .badge-paused { background: rgba(249,115,22,0.15); color: #ea580c; border: 1px solid rgba(249,115,22,0.3); }
        .badge-closed { background: rgba(239,68,68,0.15); color: #dc2626; border: 1px solid rgba(239,68,68,0.3); }
        [data-theme="dark"] .badge-ongoing { color: #4ade80; }
        [data-theme="dark"] .badge-published { color: #fbbf24; }
        [data-theme="dark"] .badge-draft { color: #94a3b8; }
        [data-theme="dark"] .badge-paused { color: #fb923c; }
        [data-theme="dark"] .badge-closed { color: #f87171; }
        .empty-state {
            text-align: center;
            padding: 48px 24px;
        }
        .empty-state > i {
            font-size: 44px;
            color: var(--text-muted);
            margin-bottom: 14px;
            display: block;
            opacity: 0.5;
        }
        .empty-state h3 { font-size: 17px; margin-bottom: 8px; color: var(--text-primary); }
        .empty-state p { font-size: 13px; color: var(--text-muted); margin-bottom: 18px; }
        .hamburger {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 20px;
            cursor: pointer;
            padding: 4px;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 99;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
            .data-table thead th, .data-table tbody td { padding: 10px 12px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/responsive.css">
</head>
<body>
    <div class="bg-layer"></div>

    <div class="admin-wrapper">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo-row">
                    <img src="../../<?php echo site_logo(); ?>" alt="Logo">
                    <div>
                        <h2>Voting System</h2>
                        <small>Admin Panel</small>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="index.php" class="active"><i class="fas fa-calendar-alt"></i> Elections</a>
                <a href="../party_lists/index.php"><i class="fas fa-flag"></i> Party Lists</a>
                <a href="../positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
                <a href="../candidates/index.php"><i class="fas fa-users"></i> Candidates</a>
                <a href="../voters/index.php"><i class="fas fa-user-check"></i> Voters</a>
                <a href="../results/live.php"><i class="fas fa-chart-bar"></i> Live Results</a>
                <div class="nav-divider"></div>
                <a href="../settings.php"><i class="fas fa-cog"></i> Settings</a>
                <a href="../profile.php"><i class="fas fa-shield-alt"></i>2FA</a>
                <a href="#" class="logout-link" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>

            <div class="sidebar-footer">
                &copy; <?php echo date('Y'); ?> OLLCF Information Systems
            </div>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <header class="topbar">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                    <h1><i class="fas fa-calendar-alt" style="font-size: 14px; opacity: 0.7; margin-right: 4px;"></i> Elections</h1>
                </div>
                <div class="topbar-right">
                    <div class="topbar-user">
                        <div class="avatar"><i class="fas fa-user-shield"></i></div>
                        <span><?php echo h($_SESSION['admin_full_name']); ?></span>
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
                <!-- PAGE HEADER -->
                <div class="page-hdr">
                    <h2><i class="fas fa-calendar-alt"></i> Elections Management</h2>
                    <p>Create and manage elections, set schedules, and control voting periods</p>
                </div>

                <!-- TOOLBAR -->
                <div class="toolbar">
                    <div class="toolbar-row">
                        <span class="toolbar-label">
                            <strong><?php echo pg_num_rows($elections_result); ?></strong> elections total
                        </span>
                        <a href="create.php" class="btn btn-success">
                            <i class="fas fa-plus-circle"></i> Create Election
                        </a>
                    </div>
                </div>

                <!-- DATA TABLE -->
                <div class="data-table-panel">
                    <?php if ($elections_result && pg_num_rows($elections_result) > 0): ?>
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Schedule</th>
                                        <th>Positions</th>
                                        <th>Candidates</th>
                                        <th>Voters</th>
                                        <th>Voting Access</th>
                                        <th>Status</th>
                                        <th style="text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($election = pg_fetch_assoc($elections_result)): ?>
                                        <?php
                                        $statusIcons = [
                                            'draft' => '📝',
                                            'published' => '📢',
                                            'ongoing' => '🟢',
                                            'paused' => '⏸️',
                                            'closed' => '🔴'
                                        ];
                                        ?>
                                        <tr>
                                            <td style="min-width: 180px;">
                                                <strong><?php echo h($election['title']); ?></strong>
                                                <?php if ($election['description']): ?>
                                                    <br>
                                                    <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                                                        <?php echo h(substr($election['description'], 0, 55)) . (strlen($election['description']) > 55 ? '…' : ''); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 12px; white-space: nowrap;">
                                                <div>
                                                    <i class="fas fa-play-circle" style="color: #16a34a; font-size: 11px; width: 14px;"></i>
                                                    <?php echo date('M d, Y h:i A', strtotime($election['start_time'])); ?>
                                                </div>
                                                <div style="margin-top: 4px;">
                                                    <i class="fas fa-stop-circle" style="color: #dc2626; font-size: 11px; width: 14px;"></i>
                                                    <?php echo date('M d, Y h:i A', strtotime($election['end_time'])); ?>
                                                </div>
                                            </td>
                                            <td><?php echo $election['position_count']; ?></td>
                                            <td><?php echo $election['candidate_count']; ?></td>
                                            <td><?php echo $election['voter_count']; ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo ($election['voting_access_mode'] ?? 'all') === 'restricted' ? 'published' : 'ongoing'; ?>">
                                                    <?php echo h(election_access_label($election)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php $s = $election['status']; ?>
                                                <span class="badge badge-<?php echo $s; ?>">
                                                    <?php echo $statusIcons[$s] ?? ''; ?> <?php echo ucfirst($s); ?>
                                                </span>
                                            </td>
                                            <td style="text-align: center;">
                                                <div style="display: flex; gap: 5px; justify-content: center; flex-wrap: wrap;">
                                                    <?php if ($s === 'draft'): ?>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=publish"
                                                           class="btn btn-info btn-sm"
                                                           title="Publish"
                                                           onclick="return swalConfirm(event, 'Publish Election?', 'Make this election visible to voters?', 'publish', 'question')">
                                                            <i class="fas fa-paper-plane"></i>
                                                        </a>
                                                    <?php elseif ($s === 'published'): ?>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=start"
                                                           class="btn btn-success btn-sm"
                                                           title="Start"
                                                           onclick="return swalConfirm(event, 'Start Election?', 'Start voting now?', 'start', 'question')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                    <?php elseif ($s === 'ongoing'): ?>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=pause"
                                                           class="btn btn-warn btn-sm"
                                                           title="Pause"
                                                           onclick="return swalConfirm(event, 'Pause Election?', 'Temporarily pause voting?', 'pause', 'warning')">
                                                            <i class="fas fa-pause"></i>
                                                        </a>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=close"
                                                           class="btn btn-danger btn-sm"
                                                           title="Close"
                                                           onclick="return swalConfirm(event, 'Close Election?', 'End voting permanently?', 'close', 'warning')">
                                                            <i class="fas fa-stop"></i>
                                                        </a>
                                                    <?php elseif ($s === 'paused'): ?>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=resume"
                                                           class="btn btn-success btn-sm"
                                                           title="Resume"
                                                           onclick="return swalConfirm(event, 'Resume Election?', 'Resume voting?', 'resume', 'question')">
                                                            <i class="fas fa-play"></i>
                                                        </a>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=close"
                                                           class="btn btn-danger btn-sm"
                                                           title="Close"
                                                           onclick="return swalConfirm(event, 'Close Election?', 'End voting permanently?', 'close', 'warning')">
                                                            <i class="fas fa-stop"></i>
                                                        </a>
                                                    <?php elseif ($s === 'closed'): ?>
                                                        <a href="update_status.php?id=<?php echo $election['id']; ?>&action=reopen"
                                                           class="btn btn-info btn-sm"
                                                           title="Reopen"
                                                           onclick="return swalConfirm(event, 'Reopen Election?', 'End time will be extended 24hrs if already passed.', 'reopen', 'question')">
                                                            <i class="fas fa-rotate-left"></i> Reopen
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (in_array($s, ['published', 'ongoing', 'paused'])): ?>
                                                        <a href="vote_access.php?id=<?php echo $election['id']; ?>" class="btn btn-info btn-sm" title="Voting Access">
                                                            <i class="fas fa-user-clock"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="edit.php?id=<?php echo $election['id']; ?>" class="btn btn-secondary btn-sm" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if (in_array($s, ['draft', 'closed'], true)): ?>
                                                        <form method="POST" action="delete.php" class="secure-delete-form"
                                                              data-status="<?php echo h($s); ?>"
                                                              data-title="<?php echo h($election['title']); ?>"
                                                              style="display:inline-flex; margin:0;">
                                                            <input type="hidden" name="id" value="<?php echo (int)$election['id']; ?>">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="admin_password" value="">
                                                            <input type="hidden" name="confirmation_title" value="">
                                                            <input type="hidden" name="confirmation_phrase" value="">
                                                            <input type="hidden" name="backup_confirmed" value="0">
                                                            <button type="submit" class="btn btn-danger btn-sm"
                                                                    title="<?php echo $s === 'closed' ? 'Purge Closed Election' : 'Delete Draft Election'; ?>">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <h3>No elections created yet</h3>
                            <p>Create your first election to get started.</p>
                            <a href="create.php" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Create Election
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleTheme() {
            const html = document.documentElement;
            const isDark = html.getAttribute('data-theme') === 'dark';
            if (isDark) {
                html.removeAttribute('data-theme');
                localStorage.setItem('vms-theme', 'light');
            } else {
                html.setAttribute('data-theme', 'dark');
                localStorage.setItem('vms-theme', 'dark');
            }
            updateThemeIcon();
        }

        function updateThemeIcon() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const themeIcon = document.getElementById('themeIcon');
            if (themeIcon) {
                themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        function swalConfirm(event, title, text, action, icon) {
            event.preventDefault();
            const href = event.currentTarget.href;
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

            let confirmButtonColor = '#1a237e';
            if (action === 'delete' || action === 'close') {
                confirmButtonColor = '#dc2626';
            } else if (action === 'pause') {
                confirmButtonColor = '#d97706';
            }

            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                showCancelButton: true,
                confirmButtonColor: confirmButtonColor,
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-check"></i> Yes, proceed',
                cancelButtonText: '<i class="fas fa-times"></i> Cancel',
                background: isDark ? '#0f1e4a' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#1e293b',
                backdrop: 'rgba(0, 0, 0, 0.4)'
            }).then(result => {
                if (result.isConfirmed) {
                    window.location.href = href;
                }
            });

            return false;
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
                    window.location.href = '../logout.php';
                }
            });
        }

        const hamburger = document.getElementById('hamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (hamburger) {
            hamburger.addEventListener('click', function() {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('show');
            });
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
            });
        }

        updateThemeIcon();

        // SweetAlert toast for flash messages
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

        async function promptAdminPasswordAndSubmit(form) {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const status = form.dataset.status || '';
            const electionTitle = form.dataset.title || '';
            const dialogTheme = {
                background: isDark ? '#0f1e4a' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#1e293b'
            };

            const passwordResult = await Swal.fire({
                title: status === 'closed' ? 'Purge Closed Election' : 'Delete Draft Election',
                text: 'Enter your admin password to continue.',
                icon: 'warning',
                input: 'password',
                inputPlaceholder: 'Your admin password',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Continue',
                cancelButtonText: 'Cancel',
                ...dialogTheme,
                inputAttributes: { autocapitalize: 'off', autocomplete: 'current-password' },
                inputValidator: value => value ? undefined : 'Admin password is required.'
            });
            if (!passwordResult.isConfirmed) return false;

            form.querySelector('input[name="admin_password"]').value = passwordResult.value;

            if (status === 'closed') {
                const titleResult = await Swal.fire({
                    title: 'Type Election Title',
                    text: 'Enter the exact title: ' + electionTitle,
                    icon: 'warning',
                    input: 'text',
                    inputPlaceholder: electionTitle,
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Continue',
                    ...dialogTheme,
                    inputValidator: value => value === electionTitle
                        ? undefined
                        : 'The election title does not match.'
                });
                if (!titleResult.isConfirmed) return false;

                const phraseResult = await Swal.fire({
                    title: 'Final Purge Phrase',
                    text: 'Type DELETE CLOSED ELECTION exactly.',
                    icon: 'warning',
                    input: 'text',
                    inputPlaceholder: 'DELETE CLOSED ELECTION',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Continue',
                    ...dialogTheme,
                    inputValidator: value => value === 'DELETE CLOSED ELECTION'
                        ? undefined
                        : 'The confirmation phrase does not match.'
                });
                if (!phraseResult.isConfirmed) return false;

                const backupResult = await Swal.fire({
                    title: 'Backup Confirmation',
                    text: 'This permanently removes voters, turnout, votes, candidates, positions, party lists, and the election.',
                    icon: 'error',
                    input: 'checkbox',
                    inputValue: 0,
                    inputPlaceholder: 'I created and disconnected a verified offline backup.',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Purge Permanently',
                    ...dialogTheme,
                    inputValidator: value => value ? undefined : 'You must confirm the offline backup.'
                });
                if (!backupResult.isConfirmed) return false;

                form.querySelector('input[name="confirmation_title"]').value = titleResult.value;
                form.querySelector('input[name="confirmation_phrase"]').value = phraseResult.value;
                form.querySelector('input[name="backup_confirmed"]').value = '1';
            }

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
