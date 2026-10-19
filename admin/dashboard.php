<?php
// admin/dashboard.php
require __DIR__ . '/feedback_dashboard_vms.php';
exit;

$root_path = dirname(__FILE__) . '/..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../index.php');
}

// ── Statistics ──
$total_elections    = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM elections"), 0, 0);
$active_elections   = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM elections WHERE status='ongoing'"), 0, 0);
$completed_elections= pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM elections WHERE status='closed'"), 0, 0);
$total_voters       = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters"), 0, 0);
$voted_count        = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters WHERE status='voted'"), 0, 0);
$pending_voters     = $total_voters - $voted_count;
$turnout_percentage = ($total_voters > 0) ? round(($voted_count / $total_voters) * 100, 2) : 0;
$total_positions    = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM positions"), 0, 0);
$total_candidates   = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM candidates"), 0, 0);
$approved_candidates= pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM candidates WHERE is_approved=true AND is_disqualified=false"), 0, 0);

// ── Recent Elections ──
$recent_elections_query = pg_query($conn,
    "SELECT e.*,
        (SELECT COUNT(*) FROM positions WHERE election_id=e.id) as position_count,
        (SELECT COUNT(*) FROM candidates c JOIN positions p ON c.position_id=p.id WHERE p.election_id=e.id) as candidate_count,
        (SELECT COUNT(DISTINCT vt.voter_id) FROM voter_turnout vt WHERE vt.election_id=e.id) as voter_count
     FROM elections e ORDER BY e.created_at DESC LIMIT 5");

// ── Ongoing Election ──
$ongoing_query = pg_query($conn,
    "SELECT e.*,
        (SELECT COUNT(*) FROM positions WHERE election_id=e.id) as position_count,
        (SELECT COUNT(*) FROM candidates c JOIN positions p ON c.position_id=p.id WHERE p.election_id=e.id AND c.is_approved=true) as candidate_count,
        (SELECT COUNT(DISTINCT vt.voter_id) FROM voter_turnout vt WHERE vt.election_id=e.id) as voted_count
     FROM elections e WHERE e.status='ongoing' LIMIT 1");
$ongoing_election = pg_fetch_assoc($ongoing_query);

// ── Upcoming Election ──
$upcoming_query = pg_query($conn, "SELECT * FROM elections WHERE status='published' ORDER BY start_time ASC LIMIT 1");
$upcoming_election = pg_fetch_assoc($upcoming_query);

// ── Recent Votes ──
$recent_votes_query = pg_query($conn,
    "SELECT vt.*, v.full_name, v.voter_id as voter_code
     FROM voter_turnout vt JOIN voters v ON vt.voter_id=v.voter_id
     ORDER BY vt.voted_at DESC LIMIT 10");

$page_title = 'Admin Dashboard - Voting Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Admin Dashboard</title>
    <link rel="icon" type="image/png" href="../<?php echo site_favicon(); ?>">
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

        /* ========================================
           CSS VARIABLES
        ======================================== */
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

        /* ========================================
           BACKGROUND IMAGE (visible in both modes)
        ======================================== */
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
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(6, 13, 31, 0.85);
        }

        /* ========================================
           SIDEBAR - DEEP BLUE (never changes)
        ======================================== */
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
            border-right: 1px solid rgba(255, 255, 255, 0.08);
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
            line-height: 1.2;
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
            font-size: 13px;
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

        /* ========================================
           MAIN CONTENT LAYOUT
        ======================================== */
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

        /* ========================================
           TOPBAR (glassmorphism)
        ======================================== */
        .topbar {
            padding: 14px 26px;
            background: var(--topbar-bg);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: var(--shadow);
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .topbar h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.2;
            white-space: nowrap;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            justify-content: flex-end;
            min-width: 0;
            flex-wrap: nowrap;
        }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            min-width: 0;
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
            flex: 0 0 auto;
        }

        .topbar-user span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
            flex: 0 0 auto;
        }

        .topbar-btn:hover {
            color: var(--accent);
            border-color: var(--accent);
        }

        /* ========================================
           CONTENT AREA
        ======================================== */
        .content-area {
            padding: 24px 26px;
            flex: 1;
        }

        /* ========================================
           WELCOME BANNER
        ======================================== */
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

        /* ========================================
           STATUS ALERT
        ======================================== */
        .status-alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13.5px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
        }
        .status-alert.ongoing  { border-left: 3px solid #4ade80; color: var(--text-primary); }
        .status-alert.upcoming { border-left: 3px solid #fbbf24; color: var(--text-primary); }
        .status-alert.info     { border-left: 3px solid #60a5fa; color: var(--text-primary); }

        /* ========================================
           STATS GRID
        ======================================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 14px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform 0.2s, background 0.2s;
            border-top: 3px solid transparent;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            background: var(--bg-panel);
        }
        .stat-card.elections  { border-top-color: #3b82f6; }
        .stat-card.voters     { border-top-color: #22c55e; }
        .stat-card.votes      { border-top-color: #f97316; }
        .stat-card.turnout    { border-top-color: #ef4444; }
        .stat-card.positions  { border-top-color: #a855f7; }
        .stat-card.candidates { border-top-color: #06b6d4; }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: white; flex-shrink: 0;
        }
        .stat-card.elections  .stat-icon { background: rgba(59,130,246,0.8); }
        .stat-card.voters     .stat-icon { background: rgba(34,197,94,0.8); }
        .stat-card.votes      .stat-icon { background: rgba(249,115,22,0.8); }
        .stat-card.turnout    .stat-icon { background: rgba(239,68,68,0.8); }
        .stat-card.positions  .stat-icon { background: rgba(168,85,247,0.8); }
        .stat-card.candidates .stat-icon { background: rgba(6,182,212,0.8); }
        .stat-number {
            font-size: 26px;
            font-weight: 800;
            line-height: 1;
            color: var(--text-primary);
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
            font-weight: 600;
        }
        .stat-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* ========================================
           QUICK ACTIONS
        ======================================== */
        .quick-actions {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }
        .quick-actions h3 {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
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
        }
        .action-btn.primary   { background: rgba(59,130,246,0.2); border-color: rgba(59,130,246,0.4); color: #1e3a8a; }
        .action-btn.secondary { background: rgba(100,116,139,0.2); border-color: rgba(100,116,139,0.4); color: #1e293b; }
        .action-btn.success   { background: rgba(34,197,94,0.2);   border-color: rgba(34,197,94,0.4); color: #14532d; }
        .action-btn.info      { background: rgba(6,182,212,0.2);   border-color: rgba(6,182,212,0.4); color: #164e63; }
        [data-theme="dark"] .action-btn.primary   { color: #93c5fd; }
        [data-theme="dark"] .action-btn.secondary { color: #cbd5e1; }
        [data-theme="dark"] .action-btn.success   { color: #86efac; }
        [data-theme="dark"] .action-btn.info      { color: #67e8f9; }
        .action-btn:hover { transform: translateY(-2px); filter: brightness(1.05); }

        /* ========================================
           DASHBOARD GRID & PANELS
        ======================================== */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
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
        .panel-header a {
            font-size: 12px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        .panel-body { padding: 14px 20px; }
        .panel-footer {
            padding: 12px 20px;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }
        .panel-footer a {
            color: var(--accent);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        /* ========================================
           ELECTION ITEM & ACTIVITY ITEM
        ======================================== */
        .election-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .election-item:last-child { border-bottom: none; }
        .election-info h4 { font-size: 13.5px; margin-bottom: 3px; color: var(--text-primary); }
        .election-info p  { font-size: 11.5px; color: var(--text-muted); }
        .election-meta    { font-size: 11px; color: var(--text-muted); margin-top: 3px; }
        .election-meta span { margin-right: 10px; }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: rgba(34,197,94,0.15);
            border: 1px solid rgba(34,197,94,0.3);
            display: flex; align-items: center; justify-content: center;
            color: #15803d; font-size: 14px; flex-shrink: 0;
        }
        [data-theme="dark"] .activity-icon { color: #4ade80; }
        .activity-info { flex: 1; }
        .activity-info strong { font-size: 13px; color: var(--text-primary); }
        .activity-info small  { color: var(--text-muted); font-size: 11px; }
        .activity-time { font-size: 11px; color: var(--text-muted); text-align: right; }

        /* ========================================
           ONGOING DETAIL GRID
        ======================================== */
        .ongoing-detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
        }
        .ongoing-detail-item {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 10px;
            padding: 14px 16px;
        }
        [data-theme="dark"] .ongoing-detail-item {
            background: rgba(255, 255, 255, 0.06);
        }
        .ongoing-detail-item .od-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .ongoing-detail-item .od-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* ========================================
           BADGES
        ======================================== */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-ongoing   { background: rgba(34,197,94,0.15); color: #15803d; border: 1px solid rgba(34,197,94,0.3); }
        .badge-published { background: rgba(251,191,36,0.15); color: #b45309; border: 1px solid rgba(251,191,36,0.3); }
        .badge-draft     { background: rgba(148,163,184,0.15); color: #475569; border: 1px solid rgba(148,163,184,0.3); }
        .badge-closed    { background: rgba(239,68,68,0.15); color: #b91c1c; border: 1px solid rgba(239,68,68,0.3); }
        [data-theme="dark"] .badge-ongoing   { color: #4ade80; }
        [data-theme="dark"] .badge-published { color: #fbbf24; }
        [data-theme="dark"] .badge-draft     { color: #94a3b8; }
        [data-theme="dark"] .badge-closed    { color: #f87171; }

        /* ========================================
           STATUS DOT & ANIMATION
        ======================================== */
        .status-dot {
            width: 9px; height: 9px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .status-dot.ongoing   { background: #4ade80; animation: pulse 2s infinite; }
        .status-dot.published { background: #fbbf24; }
        .status-dot.closed    { background: #f87171; }
        @keyframes pulse {
            0%,100% { opacity: 1; }
            50%      { opacity: 0.4; }
        }

        /* ========================================
           EMPTY STATE
        ======================================== */
        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
        }
        .empty-state i { font-size: 40px; margin-bottom: 10px; display: block; }
        .empty-state p { font-size: 13px; }

        /* ========================================
           MOBILE RESPONSIVE
        ======================================== */
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
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .main-content .topbar {
                align-items: center !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                gap: 10px;
                padding: 12px 14px !important;
            }
            .main-content .topbar-left { gap: 10px; }
            .main-content .topbar h1 { font-size: 16px !important; }
            .main-content .topbar-right {
                width: auto !important;
                justify-content: flex-end !important;
                flex-wrap: nowrap !important;
                gap: 8px;
            }
            .main-content .topbar-user { flex: 1 1 auto; }
            .main-content .topbar-user .avatar {
                width: 32px;
                height: 32px;
            }
            .main-content .topbar-btn {
                width: 38px;
                height: 38px;
                padding: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .content-area { padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2,1fr); }
            .action-buttons { flex-direction: column; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .main-content .topbar-user span { display: none; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>

<div class="bg-layer"></div>

<div class="admin-wrapper">

    <!-- Sidebar -->
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
            <a href="dashboard.php" class="active">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="elections/index.php">
                <i class="fas fa-calendar-alt"></i> Elections
            </a>
            <a href="party_lists/index.php"><i class="fas fa-flag"></i> Party Lists</a>
            <a href="positions/index.php">
                <i class="fas fa-trophy"></i> Positions
            </a>
            <a href="candidates/index.php">
                <i class="fas fa-users"></i> Candidates
            </a>
            <a href="voters/index.php">
                <i class="fas fa-user-check"></i> Voters
            </a>
            <a href="results/live.php">
                <i class="fas fa-chart-bar"></i> Live Results
            </a>

            <div class="nav-divider"></div>
            <a href="settings.php">
                <i class="fas fa-cog"></i> 
                Settings
            </a>
            <a href="profile.php"><i class="fas fa-shield-alt"></i>2FA</a>
            <a href="#" class="logout-link" onclick="confirmLogout(event)">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            © <?php echo date('Y'); ?> <?php echo site_name(); ?> Information Systems
        </div>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <main class="main-content">

        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburgerBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <h1><i class="fas fa-tachometer-alt" style="font-size:15px;opacity:0.6;margin-right:6px;"></i> Dashboard</h1>
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

        <!-- Content -->
        <div class="content-area">

            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div>
                    <h2><i class="fas fa-hand-sparkles"></i> Welcome back, <?php echo h($_SESSION['admin_full_name']); ?>!</h2>
                    <p><?php echo date('l, F j, Y'); ?> &nbsp;·&nbsp; Voting Management System Admin Panel</p>
                </div>
                <div class="banner-icon"><i class="fas fa-shield-halved"></i></div>
            </div>

            <!-- Status Alert -->
            <?php if ($ongoing_election): ?>
                <div class="status-alert ongoing">
                    <i class="fas fa-circle" style="animation:pulse 2s infinite;"></i>
                    <div>
                        <strong>Election In Progress:</strong>
                        <?php echo h($ongoing_election['title']); ?>
                        &nbsp;·&nbsp; Started: <?php echo date('M d, Y h:i A', strtotime($ongoing_election['start_time'])); ?>
                    </div>
                </div>
            <?php elseif ($upcoming_election): ?>
                <div class="status-alert upcoming">
                    <i class="fas fa-clock"></i>
                    <div>
                        <strong>Upcoming Election:</strong>
                        <?php echo h($upcoming_election['title']); ?>
                        &nbsp;·&nbsp; Starts: <?php echo date('M d, Y h:i A', strtotime($upcoming_election['start_time'])); ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="status-alert info">
                    <i class="fas fa-info-circle"></i>
                    <div><strong>No active elections.</strong> Create a new election to get started.</div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card elections">
                    <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_elections; ?></div>
                        <div class="stat-label">Total Elections</div>
                        <div class="stat-sub"><?php echo $active_elections; ?> active · <?php echo $completed_elections; ?> completed</div>
                    </div>
                </div>
                <div class="stat-card voters">
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_voters; ?></div>
                        <div class="stat-label">Registered Voters</div>
                        <div class="stat-sub"><?php echo $pending_voters; ?> eligible to vote</div>
                    </div>
                </div>
                <div class="stat-card votes">
                    <div class="stat-icon"><i class="fas fa-vote-yea"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $voted_count; ?></div>
                        <div class="stat-label">Votes Cast</div>
                        <div class="stat-sub"><?php echo $pending_voters; ?> remaining</div>
                    </div>
                </div>
                <div class="stat-card turnout">
                    <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $turnout_percentage; ?>%</div>
                        <div class="stat-label">Voter Turnout</div>
                        <div class="stat-sub"><?php echo $voted_count; ?> of <?php echo $total_voters; ?> voters</div>
                    </div>
                </div>
                <div class="stat-card positions">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_positions; ?></div>
                        <div class="stat-label">Positions</div>
                        <div class="stat-sub">Across all elections</div>
                    </div>
                </div>
                <div class="stat-card candidates">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="stat-number"><?php echo $total_candidates; ?></div>
                        <div class="stat-label">Candidates</div>
                        <div class="stat-sub"><?php echo $approved_candidates; ?> approved</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="action-buttons">
                    <a href="elections/create.php" class="action-btn primary">
                        <i class="fas fa-plus-circle"></i> Create Election
                    </a>
                    <a href="voters/generate.php" class="action-btn secondary">
                        <i class="fas fa-user-plus"></i> Generate Voters
                    </a>
                    <a href="candidates/create.php" class="action-btn info">
                        <i class="fas fa-user-tie"></i> Add Candidate
                    </a>
                    <a href="results/live.php" class="action-btn success">
                        <i class="fas fa-chart-pie"></i> Live Results
                    </a>
                </div>
            </div>

            <!-- Two-Column Grid -->
            <div class="dashboard-grid">

                <!-- Recent Elections -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-history"></i> Recent Elections</h3>
                        <a href="elections/index.php">View All →</a>
                    </div>
                    <div class="panel-body">
                        <?php if ($recent_elections_query && pg_num_rows($recent_elections_query) > 0): ?>
                            <?php while ($election = pg_fetch_assoc($recent_elections_query)): ?>
                            <div class="election-item">
                                <div class="election-info">
                                    <h4><?php echo h($election['title']); ?></h4>
                                    <p><?php echo date('M d, Y', strtotime($election['start_time'])); ?> – <?php echo date('M d, Y', strtotime($election['end_time'])); ?></p>
                                    <div class="election-meta">
                                        <span><i class="fas fa-trophy"></i> <?php echo $election['position_count']; ?> positions</span>
                                        <span><i class="fas fa-users"></i> <?php echo $election['candidate_count']; ?> candidates</span>
                                    </div>
                                </div>
                                <span class="badge badge-<?php echo $election['status']; ?>"><?php echo ucfirst($election['status']); ?></span>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-xmark"></i>
                                <p>No elections created yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($recent_elections_query && pg_num_rows($recent_elections_query) > 0): ?>
                    <div class="panel-footer">
                        <a href="elections/index.php">View All Elections <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Voting Activity -->
                <div class="panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-clock-rotate-left"></i> Recent Activity</h3>
                    </div>
                    <div class="panel-body">
                        <?php if ($recent_votes_query && pg_num_rows($recent_votes_query) > 0): ?>
                            <?php while ($activity = pg_fetch_assoc($recent_votes_query)): ?>
                            <div class="activity-item">
                                <div class="activity-icon"><i class="fas fa-check"></i></div>
                                <div class="activity-info">
                                    <strong><?php echo h($activity['full_name']); ?></strong><br>
                                    <small>ID: <?php echo h($activity['voter_code']); ?></small>
                                </div>
                                <div class="activity-time">
                                    <?php $vt = strtotime($activity['voted_at']); echo date('M d, h:i A', $vt); ?><br>
                                    <small><?php echo timeAgo($vt); ?></small>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No voting activity yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Ongoing Election Details -->
            <?php if ($ongoing_election): ?>
            <div class="panel" style="margin-bottom:24px;">
                <div class="panel-header">
                    <h3><span class="status-dot ongoing"></span> Ongoing Election Details</h3>
                </div>
                <div class="panel-body">
                    <div class="ongoing-detail-grid">
                        <div class="ongoing-detail-item">
                            <div class="od-label">Election</div>
                            <div class="od-value"><?php echo h($ongoing_election['title']); ?></div>
                        </div>
                        <div class="ongoing-detail-item">
                            <div class="od-label">Start</div>
                            <div class="od-value"><?php echo date('M d, Y h:i A', strtotime($ongoing_election['start_time'])); ?></div>
                        </div>
                        <div class="ongoing-detail-item">
                            <div class="od-label">End</div>
                            <div class="od-value"><?php echo date('M d, Y h:i A', strtotime($ongoing_election['end_time'])); ?></div>
                        </div>
                        <div class="ongoing-detail-item">
                            <div class="od-label">Positions</div>
                            <div class="od-value"><?php echo $ongoing_election['position_count']; ?></div>
                        </div>
                        <div class="ongoing-detail-item">
                            <div class="od-label">Candidates</div>
                            <div class="od-value"><?php echo $ongoing_election['candidate_count']; ?></div>
                        </div>
                        <div class="ongoing-detail-item">
                            <div class="od-label">Votes Cast</div>
                            <div class="od-value"><?php echo $ongoing_election['voted_count']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- end content-area -->
    </main>
</div>

<script>
// ========================================
// THEME TOGGLE FUNCTION
// ========================================
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
    if (themeIcon) {
        themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
}

// ========================================
// LOGOUT CONFIRMATION
// ========================================
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

// ========================================
// AUTO-DISMISS NOTIFICATIONS (if any)
// ========================================
function setupAutoDismissAlerts() {
    const alerts = document.querySelectorAll('.alert-success, .alert-error');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
}

// ========================================
// MOBILE SIDEBAR TOGGLE
// ========================================
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

// ========================================
// INITIALIZE
// ========================================
document.addEventListener('DOMContentLoaded', () => {
    updateThemeIcon();
    setupAutoDismissAlerts();
});
</script>

</body>
</html>
