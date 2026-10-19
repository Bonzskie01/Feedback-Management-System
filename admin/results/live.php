<?php
// admin/results/live.php
require __DIR__ . '/feedback_live.php';
exit;

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('./index.php');
}

// Get all elections for dropdown (excluding draft)
$elections_query = pg_query($conn, "SELECT id, title, status FROM elections WHERE status != 'draft' ORDER BY created_at DESC");

$selected_election_id = isset($_GET['election_id']) ? intval($_GET['election_id']) : 0;
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'winners';

// Auto-select an election if none selected
if ($selected_election_id === 0 && pg_num_rows($elections_query) > 0) {
    $auto = pg_query($conn, "SELECT id FROM elections WHERE status = 'ongoing' LIMIT 1");
    if (pg_num_rows($auto) === 0) {
        $auto = pg_query($conn, "SELECT id FROM elections WHERE status = 'closed' ORDER BY end_time DESC LIMIT 1");
    }
    if (pg_num_rows($auto) === 0) {
        $auto = pg_query($conn, "SELECT id FROM elections WHERE status = 'published' ORDER BY start_time ASC LIMIT 1");
    }
    if (pg_num_rows($auto) > 0) {
        $selected_election_id = pg_fetch_result($auto, 0, 'id');
    }
}

$election = null;
$positions = [];
$total_voters = 0;
$total_voted = 0;
$turnout_percentage = 0;
$total_votes_cast = 0;

if ($selected_election_id > 0) {
    $election_result = pg_query_params($conn, "SELECT * FROM elections WHERE id = $1", [$selected_election_id]);
    if (pg_num_rows($election_result) > 0) {
        $election = pg_fetch_assoc($election_result);
        
        // Get total unique voters who participated in this election
        $voted_result = pg_query_params($conn, 
            "SELECT COUNT(DISTINCT vt.voter_id) FROM voter_turnout vt WHERE vt.election_id = $1", 
            [$selected_election_id]);
        $total_voted = $voted_result ? (int) pg_fetch_result($voted_result, 0, 0) : 0;
        
        $positions_result = pg_query_params($conn, 
            "SELECT * FROM positions WHERE election_id = $1 ORDER BY display_order ASC", 
            [$selected_election_id]);
        
        while ($pos = pg_fetch_assoc($positions_result)) {
            $candidates_result = pg_query_params($conn, 
                "SELECT c.*, 
                    COALESCE((SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id AND v.position_id = c.position_id AND v.election_id = $1), 0) as vote_count
                 FROM candidates c 
                 WHERE c.position_id = $2 AND c.is_approved = true AND c.is_disqualified = false
                 ORDER BY vote_count DESC, c.full_name ASC",
                [$selected_election_id, $pos['id']]
            );
            
            $candidates = [];
            $total_votes_for_position = 0;
            
            while ($cand = pg_fetch_assoc($candidates_result)) {
                $candidates[] = $cand;
                $total_votes_for_position += $cand['vote_count'];
            }
            
            // Compute abstention for this position
            $abstained = max(0, $total_voted - $total_votes_for_position);
            $abstention_pct = $total_voted > 0 ? round(($abstained / $total_voted) * 100, 1) : 0;
            
            $positions[] = [
                'info' => $pos,
                'candidates' => $candidates,
                'total_votes' => $total_votes_for_position,
                'abstained' => $abstained,
                'abstention_pct' => $abstention_pct
            ];
        }
        
        // Statistics (global)
        $total_voters = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters"), 0, 0);
        $turnout_percentage = ($total_voters > 0) ? round(($total_voted / $total_voters) * 100, 1) : 0;
        
        $votes_cast_result = pg_query_params($conn,
            "SELECT COUNT(*) FROM votes WHERE election_id = $1",
            [$selected_election_id]);
        $total_votes_cast = $votes_cast_result ? (int) pg_fetch_result($votes_cast_result, 0, 0) : 0;
    }
}

$page_title = 'Election Results - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Election Results</title>
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
           DEEP BLUE THEME (same as original)
        ======================================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
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
        }
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('../../<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
        }
        :root .bg-layer::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(240,244,255,0.7);
        }
        [data-theme="dark"] .bg-layer::before {
            background: rgba(6,13,31,0.85);
        }
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
        .toolbar { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; backdrop-filter: blur(4px); }
        .toolbar-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .filter-select { padding: 10px 32px 10px 16px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 13px; cursor: pointer; }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #1a237e; color: white; }
        .btn-primary:hover { background: #283593; transform: translateY(-1px); }
        .btn-live { background: #dc2626; color: white; }
        .btn-live:hover { background: #b91c1c; transform: translateY(-1px); }
        .results-header-card { background: linear-gradient(135deg, #1a237e, #283593); color: white; padding: 25px 30px; border-radius: 16px; margin-bottom: 25px; box-shadow: var(--shadow-md); }
        .results-header-card h2 { font-size: 22px; display: flex; align-items: center; gap: 10px; }
        .tab-buttons { display: flex; gap: 10px; margin-bottom: 25px; background: var(--bg-card); padding: 6px; border-radius: 12px; width: fit-content; border: 1px solid var(--border-color); }
        .tab-btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; background: transparent; color: var(--text-secondary); text-decoration: none; }
        .tab-btn.active { background: #1a237e; color: white; }
        .results-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .result-stat-mini { background: var(--bg-card); padding: 20px; border-radius: 12px; text-align: center; border: 1px solid var(--border-color); }
        .result-stat-mini .value { font-size: 32px; font-weight: 700; color: var(--accent-primary); }
        .result-stat-mini .label { font-size: 11px; color: var(--text-secondary); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.8px; }
        .position-result-card { background: var(--bg-card); border-radius: 16px; margin-bottom: 25px; overflow: hidden; border: 1px solid var(--border-color); }
        .position-result-header { padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .position-result-header h3 { font-size: 18px; display: flex; align-items: center; gap: 10px; }
        .winner-badge-count { background: #fff3e0; color: #e65100; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        [data-theme="dark"] .winner-badge-count { background: #3e2000; color: #ffb74d; }
        .winners-banner { background: linear-gradient(135deg, #fff8e1, #ffecb3); padding: 18px 22px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; border-bottom: 1px solid #ffd54f; }
        [data-theme="dark"] .winners-banner { background: linear-gradient(135deg, #3e2000, #5d3800); border-color: #7a4a00; }
        .winners-title { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 15px; color: #e65100; }
        [data-theme="dark"] .winners-title { color: #ffb74d; }
        .winner-chip { display: flex; align-items: center; gap: 10px; background: white; padding: 8px 16px; border-radius: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        [data-theme="dark"] .winner-chip { background: #1e293b; }
        .winner-photo { width: 36px; height: 36px; border-radius: 50%; background: #e0e0e0; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .winner-photo img { width: 100%; height: 100%; object-fit: cover; }
        .winner-info .winner-name { font-weight: 600; font-size: 14px; }
        .winner-info .winner-votes { font-size: 11px; color: #666; }
        [data-theme="dark"] .winner-info .winner-votes { color: #aaa; }
        .candidate-row { display: flex; align-items: center; padding: 14px 22px; border-bottom: 1px solid var(--border-color); gap: 15px; }
        .candidate-row:last-child { border-bottom: none; }
        .candidate-rank { font-size: 18px; font-weight: 700; min-width: 35px; text-align: center; }
        .candidate-rank.winner { color: #f9a825; }
        .candidate-photo-sm { width: 42px; height: 42px; border-radius: 50%; background: #e0e0e0; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .candidate-photo-sm img { width: 100%; height: 100%; object-fit: cover; }
        .candidate-info { flex: 1; }
        .candidate-info .name { font-weight: 600; font-size: 14px; }
        .candidate-info .partylist { font-size: 11px; color: var(--text-muted); }
        .progress-bar-wrapper { width: 120px; height: 6px; background: var(--bg-input); border-radius: 10px; overflow: hidden; margin-top: 6px; }
        .progress-bar-fill { height: 100%; border-radius: 10px; transition: width 0.3s; }
        .progress-bar-fill.gold { background: linear-gradient(90deg, #f9a825, #ffd54f); }
        .progress-bar-fill.blue { background: #1a237e; }
        .progress-bar-fill.green { background: #2e7d32; }
        .progress-bar-fill.orange { background: #e65100; }
        .candidate-votes { text-align: right; min-width: 100px; }
        .candidate-votes .vote-count { font-size: 18px; font-weight: 700; color: var(--accent-primary); }
        .candidate-votes .vote-label { font-size: 10px; color: var(--text-muted); }
        .abstain-row { background: var(--bg-panel); margin-top: 0; border-top: 1px solid var(--border-color); color: var(--text-secondary); font-size: 13px; }
        .live-dot { width: 10px; height: 10px; background: #4caf50; border-radius: 50%; display: inline-block; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.8); } 100% { opacity: 1; transform: scale(1); } }
        .no-data { text-align: center; padding: 40px; color: var(--text-muted); }
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 48px; opacity: 0.4; margin-bottom: 15px; display: block; }
        .hamburger { display: none; background: none; border: none; color: var(--text-primary); font-size: 20px; cursor: pointer; padding: 4px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
            .candidate-row { flex-wrap: wrap; }
            .candidate-votes { text-align: left; margin-left: 50px; }
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
            <a href="../voters/index.php"><i class="fas fa-user-check"></i> Voters</a>
            <a href="live.php" class="active"><i class="fas fa-chart-bar"></i> Live Results</a>
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
                <h1><i class="fas fa-chart-bar" style="font-size: 14px; opacity: 0.7; margin-right: 4px;"></i> Election Results</h1>
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
            <!-- Toolbar with filter and Live View button -->
            <div class="toolbar">
                <div class="toolbar-row">
                    <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <label style="font-weight: 600;">Election:</label>
                        <select name="election_id" class="filter-select">
                            <?php 
                            $elections_dropdown = pg_query($conn, "SELECT id, title, status FROM elections WHERE status != 'draft' ORDER BY created_at DESC");
                            while ($el = pg_fetch_assoc($elections_dropdown)): 
                            ?>
                                <option value="<?php echo $el['id']; ?>" <?php echo $selected_election_id == $el['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($el['title']); ?> (<?php echo ucfirst($el['status']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                    </form>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if ($selected_election_id > 0): ?>
                            <a href="live_view.php?election_id=<?php echo $selected_election_id; ?>" target="_blank" class="btn btn-live"><i class="fas fa-external-link-alt"></i> Open Live View</a>
                            <a href="live_view.php?election_id=<?php echo $selected_election_id; ?>&mask=1" target="_blank" class="btn btn-primary"><i class="fas fa-user-secret"></i> Masked Live View</a>
                        <?php endif; ?>
                        <?php if ($election && $election['status'] === 'ongoing'): ?>
                            <span style="display: flex; align-items: center; gap: 8px;">
                                <span class="live-dot"></span>
                                <span style="font-weight: 600; color: #2e7d32;">LIVE</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($election): ?>
                <!-- Header -->
                <div class="results-header-card">
                    <h2><i class="fas fa-trophy"></i> <?php echo h($election['title']); ?></h2>
                    <p style="opacity: 0.9; font-size: 13px; margin-top: 8px;">
                        Status: <strong><?php echo ucfirst($election['status']); ?></strong> &nbsp;|&nbsp;
                        <?php echo date('M d, Y h:i A', strtotime($election['start_time'])); ?> — 
                        <?php echo date('M d, Y h:i A', strtotime($election['end_time'])); ?>
                    </p>
                </div>

                <!-- Tab Buttons -->
                <div class="tab-buttons">
                    <a href="?election_id=<?php echo $selected_election_id; ?>&view=winners" 
                       class="tab-btn <?php echo $view_mode === 'winners' ? 'active' : ''; ?>">
                        <i class="fas fa-trophy"></i> Winners
                    </a>
                    <a href="?election_id=<?php echo $selected_election_id; ?>&view=all" 
                       class="tab-btn <?php echo $view_mode === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i> All Results
                    </a>
                </div>

                <!-- Stats -->
                <div class="results-stats">
                    <div class="result-stat-mini">
                        <div class="value"><?php echo number_format($total_voters); ?></div>
                        <div class="label">Registered Voters</div>
                    </div>
                    <div class="result-stat-mini">
                        <div class="value"><?php echo number_format($total_voted); ?></div>
                        <div class="label">Votes Cast</div>
                    </div>
                    <div class="result-stat-mini">
                        <div class="value"><?php echo $turnout_percentage; ?>%</div>
                        <div class="label">Turnout</div>
                    </div>
                    <div class="result-stat-mini">
                        <div class="value"><?php echo number_format($total_votes_cast); ?></div>
                        <div class="label">Total Ballots</div>
                    </div>
                </div>

                <!-- Results Content -->
                <?php if (count($positions) > 0): ?>
                    <?php foreach ($positions as $position_data): 
                        $pos = $position_data['info'];
                        $candidates = $position_data['candidates'];
                        $total_votes_for_pos = $position_data['total_votes'];
                        $abstained = $position_data['abstained'];
                        $abstention_pct = $position_data['abstention_pct'];
                        $max_votes = count($candidates) > 0 ? $candidates[0]['vote_count'] : 0;
                    ?>
                        <div class="position-result-card">
                            <div class="position-result-header">
                                <h3><i class="fas fa-trophy" style="color: #f9a825;"></i> <?php echo h($pos['name']); ?></h3>
                                <div style="display: flex; gap: 12px;">
                                    <span class="winner-badge-count">
                                        <?php echo $pos['max_winners']; ?> Winner<?php echo $pos['max_winners'] > 1 ? 's' : ''; ?>
                                    </span>
                                    <span style="font-size: 12px; color: var(--text-muted);">
                                        Total: <?php echo number_format($total_votes_for_pos); ?> votes
                                    </span>
                                </div>
                            </div>

                            <?php if ($view_mode === 'winners'): ?>
                                <?php 
                                $winners = array_slice($candidates, 0, $pos['max_winners']);
                                $has_winner = count($winners) > 0 && $winners[0]['vote_count'] > 0;
                                ?>
                                <?php if ($has_winner): ?>
                                    <div class="winners-banner">
                                        <div class="winners-title">
                                            <span style="font-size: 22px;">🏆</span>
                                            <?php echo count($winners) > 1 ? 'WINNERS' : 'WINNER'; ?>
                                        </div>
                                        <?php foreach ($winners as $winner): 
                                            $pct = $total_votes_for_pos > 0 ? round(($winner['vote_count'] / $total_votes_for_pos) * 100, 1) : 0;
                                        ?>
                                            <div class="winner-chip">
                                                <div class="winner-photo">
                                                    <?php if ($winner['photo_path'] && file_exists('../../' . $winner['photo_path'])): ?>
                                                        <img src="../../<?php echo h($winner['photo_path']); ?>" alt="<?php echo h($winner['full_name']); ?>">
                                                    <?php else: ?>
                                                        <i class="fas fa-user"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="winner-info">
                                                    <div class="winner-name"><?php echo h($winner['full_name']); ?></div>
                                                    <div class="winner-votes">
                                                        <?php echo number_format($winner['vote_count']); ?> votes (<?php echo $pct; ?>%)
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-data">No votes cast yet for this position.</div>
                                <?php endif; ?>
                                <!-- Abstain row for winners tab -->
                                <div class="candidate-row abstain-row">
                                    <div class="candidate-rank"><i class="fas fa-user-slash"></i></div>
                                    <div class="candidate-info">
                                        <div class="name">Abstained</div>
                                        <div class="partylist">Did not vote for this position</div>
                                    </div>
                                    <div class="candidate-votes">
                                        <div class="vote-count"><?php echo number_format($abstained); ?></div>
                                        <div class="vote-label">voters (<?php echo $abstention_pct; ?>%)</div>
                                    </div>
                                </div>

                            <?php else: // All results view ?>
                                <?php if (count($candidates) > 0): ?>
                                    <?php foreach ($candidates as $index => $candidate): 
                                        $percentage = $total_votes_for_pos > 0 ? round(($candidate['vote_count'] / $total_votes_for_pos) * 100, 1) : 0;
                                        $bar_width = $max_votes > 0 ? round(($candidate['vote_count'] / $max_votes) * 100, 1) : 0;
                                        $is_winner = $index < $pos['max_winners'] && $candidate['vote_count'] > 0;
                                        $bar_color = $is_winner ? 'gold' : (['blue', 'green', 'orange', 'purple'][$index % 4] ?? 'blue');
                                    ?>
                                        <div class="candidate-row">
                                            <div class="candidate-rank <?php echo $is_winner ? 'winner' : ''; ?>">
                                                <?php echo $is_winner ? '🏆' : ($index + 1); ?>
                                            </div>
                                            <div class="candidate-photo-sm">
                                                <?php if ($candidate['photo_path'] && file_exists('../../' . $candidate['photo_path'])): ?>
                                                    <img src="../../<?php echo h($candidate['photo_path']); ?>">
                                                <?php else: ?>
                                                    <i class="fas fa-user"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="candidate-info">
                                                <div class="name"><?php echo h($candidate['full_name']); ?></div>
                                                <?php if ($candidate['partylist']): ?>
                                                    <div class="partylist"><?php echo h($candidate['partylist']); ?></div>
                                                <?php endif; ?>
                                                <div class="progress-bar-wrapper">
                                                    <div class="progress-bar-fill <?php echo $bar_color; ?>" style="width: <?php echo $bar_width; ?>%;"></div>
                                                </div>
                                            </div>
                                            <div class="candidate-votes">
                                                <div class="vote-count"><?php echo number_format($candidate['vote_count']); ?></div>
                                                <div class="vote-label">votes (<?php echo $percentage; ?>%)</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <!-- Abstain row for all results -->
                                    <div class="candidate-row abstain-row">
                                        <div class="candidate-rank"><i class="fas fa-user-slash"></i></div>
                                        <div class="candidate-info">
                                            <div class="name">Abstained</div>
                                            <div class="partylist">Did not vote for this position</div>
                                        </div>
                                        <div class="candidate-votes">
                                            <div class="vote-count"><?php echo number_format($abstained); ?></div>
                                            <div class="vote-label">voters (<?php echo $abstention_pct; ?>%)</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-data">No candidates for this position.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="position-result-card">
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No positions found</h3>
                            <p>This election has no positions configured yet.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Auto-refresh for ongoing elections -->
                <?php if ($election['status'] === 'ongoing'): ?>
                    <div style="text-align: center; color: var(--text-muted); font-size: 12px; margin-top: 20px; padding: 10px;">
                        <span class="live-dot"></span> Auto-refreshes every 30 seconds
                    </div>
                    <script>
                        setTimeout(function() { location.reload(); }, 30000);
                    </script>
                <?php endif; ?>

            <?php else: ?>
                <div class="position-result-card">
                    <div class="empty-state">
                        <i class="fas fa-chart-bar"></i>
                        <h3>Select an Election</h3>
                        <p>Choose an election from the dropdown above to view its results.</p>
                    </div>
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
        hamburger.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }
    updateThemeIcon();
</script>
</body>
</html>
