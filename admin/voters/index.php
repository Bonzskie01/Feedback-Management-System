<?php
// admin/voters/index.php

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

ensure_voters_election_id_column($conn);

// Pagination
$page      = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page  = 15;
$offset    = ($page - 1) * $per_page;

// Search & Filter
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Build WHERE
$where_clauses = [];
$params        = [];
$param_count   = 1;

if ($search) {
    $where_clauses[] = "(v.full_name ILIKE \$$param_count OR v.voter_id ILIKE \$$param_count)";
    $params[]        = "%$search%";
    $param_count++;
}

if ($status_filter && in_array($status_filter, ['active', 'voted'])) {
    $where_clauses[] = "v.status = \$$param_count";
    $params[]        = $status_filter;
    $param_count++;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Totals
$count_result  = pg_query_params($conn, "SELECT COUNT(*) FROM voters v $where_sql", $params);
$total_voters  = (int) pg_fetch_result($count_result, 0, 0);
$total_pages   = max(1, ceil($total_voters / $per_page));

$start_record  = $total_voters > 0 ? ($page - 1) * $per_page + 1 : 0;

// Voter rows
$query_params   = $params;
$query_params[] = $per_page;
$query_params[] = $offset;
$voters_sql     = "SELECT v.*, e.title AS election_title
                     FROM voters v
                     LEFT JOIN elections e ON v.election_id = e.id
                   $where_sql
                    ORDER BY v.generated_at DESC
                   LIMIT \$$param_count OFFSET \$" . ($param_count + 1);
$voters_result  = pg_query_params($conn, $voters_sql, $query_params);

// Stats (always from full table)
$total_all    = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters"), 0, 0);
$total_active = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters WHERE status = 'active'"), 0, 0);
$total_voted  = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters WHERE status = 'voted'"), 0, 0);
$turnout_pct  = $total_all > 0 ? round(($total_voted / $total_all) * 100, 1) : 0;

// Flash messages from URL parameters
$flashMessage = null;
$flashType = null;
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $flashMessage = "Voter(s) deleted successfully!";
    $flashType = "success";
} elseif (isset($_GET['error'])) {
    $flashMessage = "An error occurred. Please try again.";
    $flashType = "error";
} elseif (isset($_GET['reset']) && $_GET['reset'] == 1) {
    $flashMessage = "Password reset successfully!";
    $flashType = "success";
}

$page_title = 'Voters Management - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Voters Management</title>
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
           DEEP BLUE THEME - VOTERS MODULE
        ======================================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 3px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; min-height: 100vh; background: var(--bg-body); color: var(--text-primary); transition: background 0.3s, color 0.3s; }
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255,255,255,0.92);
            --bg-panel: rgba(248,250,255,0.9);
            --bg-input: #f1f5f9;
            --bg-hover: #f1f5f9;
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
            --bg-hover: #252a3a;
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
        .bg-layer { position: fixed; inset: 0; background: url('../../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { background: rgba(6,13,31,0.85); }
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
        .page-hdr p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--bg-card); border: 1px solid var(--border-color); backdrop-filter: blur(4px); border-radius: 14px; padding: 20px; display: flex; align-items: center; gap: 14px; transition: transform 0.2s; border-top: 3px solid transparent; }
        .stat-card.blue { border-top-color: #3b82f6; }
        .stat-card.green { border-top-color: #22c55e; }
        .stat-card.orange { border-top-color: #f97316; }
        .stat-card.purple { border-top-color: #a855f7; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: white; flex-shrink: 0; }
        .stat-card.blue .stat-icon { background: rgba(59,130,246,0.8); }
        .stat-card.green .stat-icon { background: rgba(34,197,94,0.8); }
        .stat-card.orange .stat-icon { background: rgba(249,115,22,0.8); }
        .stat-card.purple .stat-icon { background: rgba(168,85,247,0.8); }
        .stat-number { font-size: 26px; font-weight: 800; line-height: 1; color: var(--text-primary); }
        .stat-label { font-size: 12px; color: var(--text-secondary); margin-top: 4px; font-weight: 600; }
        .toolbar { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; backdrop-filter: blur(4px); }
        .toolbar-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .search-input-wrapper { position: relative; display: inline-flex; align-items: center; }
        .search-input-wrapper i { position: absolute; left: 12px; color: var(--text-muted); }
        .search-input-wrapper input { padding: 10px 12px 10px 34px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 13px; width: 240px; }
        .filter-select { padding: 10px 32px 10px 16px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 13px; cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; background-size: 14px; }
        [data-theme="dark"] .filter-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); }
        .filter-select:hover, .search-input-wrapper input:hover { border-color: var(--accent); }
        .filter-select:focus, .search-input-wrapper input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.2); }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
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
        .data-table-panel { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; backdrop-filter: blur(4px); margin-bottom: 20px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .data-table thead th { background: var(--table-head); padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: var(--text-muted); border-bottom: 1px solid var(--border-color); }
        .data-table tbody tr { border-bottom: 1px solid var(--border-color); transition: background 0.15s; }
        .data-table tbody tr:hover { background: var(--table-row-hover); }
        .data-table tbody td { padding: 12px 16px; color: var(--text-primary); vertical-align: middle; }
        .code-badge { background: var(--bg-panel); padding: 4px 10px; border-radius: 6px; font-family: monospace; font-size: 13px; font-weight: 600; color: var(--accent-primary); }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status-badge.voted { background: rgba(239,68,68,0.15); color: #dc2626; }
        .status-badge.active { background: rgba(34,197,94,0.15); color: #16a34a; }
        .status-dot-sm { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .status-dot-sm.red { background: #dc2626; }
        .status-dot-sm.green { background: #16a34a; }
        [data-theme="dark"] .status-badge.voted { color: #f87171; }
        [data-theme="dark"] .status-badge.active { color: #4ade80; }
        .table-footer { padding: 12px 20px; border-top: 1px solid var(--border-color); text-align: center; font-size: 12px; color: var(--text-muted); }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
        .page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 8px; border-radius: 8px; background: var(--bg-card); border: 1px solid var(--border-color); color: var(--text-primary); text-decoration: none; font-size: 14px; transition: all 0.2s; }
        .page-link.active { background: var(--accent-primary); border-color: var(--accent-primary); color: white; }
        .page-link.disabled { opacity: 0.5; pointer-events: none; }
        .page-link:hover:not(.disabled):not(.active) { background: var(--bg-hover); border-color: var(--accent); }
        .page-ellipsis { display: inline-flex; align-items: center; padding: 0 4px; color: var(--text-muted); }
        .empty-state { text-align: center; padding: 48px 24px; }
        .empty-state i { font-size: 44px; color: var(--text-muted); margin-bottom: 14px; display: block; opacity: 0.5; }
        .empty-state h3 { font-size: 17px; margin-bottom: 8px; color: var(--text-primary); }
        .empty-state p { font-size: 13px; color: var(--text-muted); margin-bottom: 18px; }
        .action-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: var(--bg-panel); color: var(--text-secondary); transition: all 0.2s; text-decoration: none; }
        .action-icon-btn:hover { background: var(--bg-hover); color: var(--accent); }
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
            .search-input-wrapper input { width: 100%; }
            .filter-select { width: 100%; }
            .data-table thead th, .data-table tbody td { padding: 10px 12px; }
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
            <a href="../party_lists/index.php"><i class="fas fa-list-ul"></i> Party Lists</a>
            <a href="../positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
            <a href="../candidates/index.php"><i class="fas fa-users"></i> Candidates</a>
            <a href="index.php" class="active"><i class="fas fa-user-check"></i> Voters</a>
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
                <h1><i class="fas fa-user-check" style="font-size: 14px; opacity: 0.7; margin-right: 4px;"></i> Voters</h1>
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
            <div class="page-hdr">
                <h2><i class="fas fa-user-check"></i> Voter Accounts</h2>
                <p>Manage all registered voters and their voting status</p>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div><div class="stat-number"><?php echo number_format($total_all); ?></div><div class="stat-label">Total Voters</div></div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    <div><div class="stat-number"><?php echo number_format($total_active); ?></div><div class="stat-label">Active (Can Vote)</div></div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-icon"><i class="fas fa-vote-yea"></i></div>
                    <div><div class="stat-number"><?php echo number_format($total_voted); ?></div><div class="stat-label">Already Voted</div></div>
                </div>
                <div class="stat-card purple">
                    <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    <div><div class="stat-number"><?php echo $turnout_pct; ?>%</div><div class="stat-label">Voter Turnout</div></div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-row">
                    <form method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <div class="search-input-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Search name or voter ID…" value="<?php echo h($search); ?>">
                        </div>
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="voted" <?php echo $status_filter === 'voted' ? 'selected' : ''; ?>>Voted</option>
                        </select>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                        <?php if ($search || $status_filter): ?>
                            <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" id="bulkDeleteBtn" class="btn btn-danger" style="background:#dc2626;">
                            <i class="fas fa-trash-alt"></i> Delete Selected
                        </button>
                        <a href="upload_template.php" class="btn btn-secondary">
                            <i class="fas fa-file-upload"></i> Upload Custom Template
                        </a>
                        <a href="generate.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> Generate Accounts</a>
                        <form method="POST" action="export.php" style="display:inline-flex; gap:8px; margin:0; align-items:center;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="search" value="<?php echo h($search); ?>">
                            <input type="hidden" name="status" value="<?php echo h($status_filter); ?>">
                            <input type="password" name="admin_password" required placeholder="Admin password" style="width:150px;padding:9px 10px;border-radius:8px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);">
                            <button type="submit" class="btn btn-secondary"><i class="fas fa-file-csv"></i> Export CSV</button>
                        </form>
                        <form method="POST" action="export_docx.php" style="display:inline-flex; gap:8px; margin:0; align-items:center;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="search" value="<?php echo h($search); ?>">
                            <input type="hidden" name="status" value="<?php echo h($status_filter); ?>">
                            <input type="password" name="admin_password" required placeholder="Admin password" style="width:150px;padding:9px 10px;border-radius:8px;border:1px solid var(--border-color);background:var(--bg-input);color:var(--text-primary);">
                            <button type="submit" class="btn btn-info"><i class="fas fa-file-word"></i> Export Word (.docx)</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="data-table-panel">
                <?php if ($total_voters > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"><input type="checkbox" id="selectAll"></th>
                                    <th>#</th>
                                    <th>Voter ID</th>
                                    <th>Election</th>
                                    <th>Full Name</th>
                                    <th>Grade / Section</th>
                                    <th>Status</th>
                                    <th>Voted At</th>
                                    <th>Generated</th>
                                    <th style="text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $row_num = $start_record; while ($voter = pg_fetch_assoc($voters_result)): $is_voted = $voter['status'] === 'voted'; ?>
                                <tr>
                                    <td><input type="checkbox" class="voter-checkbox" data-id="<?php echo $voter['id']; ?>" data-status="<?php echo $voter['status']; ?>"></td>
                                    <td style="color:var(--text-muted);"><?php echo $row_num++; ?></td>
                                    <td><span class="code-badge"><?php echo h($voter['voter_id']); ?></span></td>
                                    <td><?php echo h($voter['election_title'] ?: 'Unassigned'); ?></td>
                                    <td><strong><?php echo h($voter['full_name']); ?></strong></td>
                                    <td><?php echo h($voter['grade_section'] ?: '—'); ?></td>
                                    <td><?php if ($is_voted): ?><span class="status-badge voted"><span class="status-dot-sm red"></span> Voted</span><?php else: ?><span class="status-badge active"><span class="status-dot-sm green"></span> Active</span><?php endif; ?></td>
                                    <td style="font-size:13px; white-space:nowrap;"><?php echo $voter['voted_at'] ? date('M j, Y g:i A', strtotime($voter['voted_at'])) : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                                    <td style="font-size:13px;"><?php echo $voter['generated_at'] ? date('M j, Y', strtotime($voter['generated_at'])) : '—'; ?></td>
                                    <td style="text-align:center;">
                                        <div style="display: flex; gap: 4px; justify-content: center;">
                                            <a href="reset_password.php?id=<?php echo (int)$voter['id']; ?>" class="action-icon-btn" style="color:#e65100;" title="Reset Password" onclick="return swalConfirm(event, 'Reset Password?', 'A new password will be generated for this voter.', 'reset', 'question')">
                                                <i class="fas fa-key"></i>
                                            </a>
                                            <a href="#" class="action-icon-btn delete-voter-btn" data-id="<?php echo (int)$voter['id']; ?>" data-status="<?php echo $voter['status']; ?>" title="Delete Voter">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span><?php echo number_format($total_voters); ?> voter<?php echo $total_voters !== 1 ? 's' : ''; ?> total</span>
                        <span><?php echo $total_active; ?> active · <?php echo $total_voted; ?> voted</span>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <?php if ($search || $status_filter): ?>
                            <h3>No voters match your filter</h3>
                            <p>Try adjusting your search or clearing the filter.</p>
                            <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear Filters</a>
                        <?php else: ?>
                            <h3>No voters registered yet</h3>
                            <p>Generate voter accounts to get started.</p>
                            <a href="generate.php" class="btn btn-success"><i class="fas fa-plus-circle"></i> Generate Accounts</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>
                    <?php
                    $show_start = max(1, $page-2);
                    $show_end   = min($total_pages, $page+2);
                    if ($show_start > 1) {
                        echo '<a href="?page=1&search='.urlencode($search).'&status='.urlencode($status_filter).'" class="page-link">1</a>';
                        if ($show_start > 2) echo '<span class="page-ellipsis">…</span>';
                    }
                    for ($p=$show_start; $p<=$show_end; $p++) {
                        echo '<a href="?page='.$p.'&search='.urlencode($search).'&status='.urlencode($status_filter).'" class="page-link '.($p==$page?'active':'').'">'.$p.'</a>';
                    }
                    if ($show_end < $total_pages) {
                        if ($show_end < $total_pages-1) echo '<span class="page-ellipsis">…</span>';
                        echo '<a href="?page='.$total_pages.'&search='.urlencode($search).'&status='.urlencode($status_filter).'" class="page-link">'.$total_pages.'</a>';
                    }
                    if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
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

    function swalConfirm(event, title, text, action, icon) {
        event.preventDefault();
        const href = event.currentTarget.href;
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        let confirmButtonColor = '#1a237e';
        if (action === 'delete' || action === 'close') confirmButtonColor = '#dc2626';
        else if (action === 'pause') confirmButtonColor = '#d97706';
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
            backdrop: 'rgba(0,0,0,0.4)'
        }).then(result => {
            if (result.isConfirmed) window.location.href = href;
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
            backdrop: 'rgba(0,0,0,0.4)'
        }).then(result => {
            if (result.isConfirmed) window.location.href = '../logout.php';
        });
    }

    // Single delete with password prompt for voted voters
    document.querySelectorAll('.delete-voter-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const voterId = btn.dataset.id;
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';

            const { value: password } = await Swal.fire({
                title: 'Delete Voter',
                text: 'Enter your admin password to delete this voter account.',
                icon: 'warning',
                input: 'password',
                inputPlaceholder: 'Your admin password',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Delete Anyway',
                cancelButtonText: 'Cancel',
                background: isDark ? '#0f1e4a' : '#fff',
                color: isDark ? '#f1f5f9' : '#1e293b',
                inputAttributes: { autocapitalize: 'off', autocomplete: 'off' }
            });
            if (!password) return;

            Swal.fire({ title: 'Processing...', text: 'Deleting voter and their votes', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            try {
                const formData = new FormData();
                formData.append('id', voterId);
                formData.append('admin_password', password);
                formData.append('csrf_token', '<?php echo csrf_token(); ?>');
                const response = await fetch('delete.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    Swal.fire('Deleted!', result.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', result.message, 'error');
                }
            } catch (err) {
                console.error(err);
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            }
        });
    });

    // Bulk delete functionality
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const selectAllCheckbox = document.getElementById('selectAll');
    const voterCheckboxes = () => document.querySelectorAll('.voter-checkbox');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', (e) => {
            voterCheckboxes().forEach(cb => cb.checked = e.target.checked);
        });
    }

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', async () => {
            const selected = [];
            voterCheckboxes().forEach(cb => {
                if (cb.checked) selected.push({ id: cb.dataset.id, status: cb.dataset.status });
            });
            if (selected.length === 0) {
                Swal.fire('No selection', 'Please select at least one voter to delete.', 'warning');
                return;
            }
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            let password = null;
            {
                const result = await Swal.fire({
                    title: 'Delete Selected Voters',
                    text: 'Enter your admin password to delete the selected voter accounts.',
                    icon: 'warning',
                    input: 'password',
                    inputPlaceholder: 'Your admin password',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Delete Anyway',
                    cancelButtonText: 'Cancel',
                    background: isDark ? '#0f1e4a' : '#fff',
                    color: isDark ? '#f1f5f9' : '#1e293b',
                    inputAttributes: { autocapitalize: 'off', autocomplete: 'off' }
                });
                if (!result.value) return;
                password = result.value;
            }

            Swal.fire({ title: 'Processing...', text: 'Deleting selected voters', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            try {
                const formData = new FormData();
                formData.append('ids', JSON.stringify(selected.map(v => v.id)));
                formData.append('admin_password', password);
                formData.append('csrf_token', '<?php echo csrf_token(); ?>');
                const response = await fetch('bulk_delete.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    Swal.fire('Deleted!', result.message, 'success').then(() => window.location.href = 'index.php?deleted=1');
                } else {
                    Swal.fire('Error', result.message, 'error');
                }
            } catch (err) {
                console.error(err);
                Swal.fire('Error', 'Network error or server problem: ' + err.message, 'error');
            }
        });
    }

    // Mobile sidebar toggle
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
