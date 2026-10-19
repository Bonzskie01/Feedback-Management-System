<?php
// admin/elections/vote_access.php
$root_path = dirname(__FILE__) . '/../..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0 || !ensure_election_access_columns($conn)) {
    redirect('index.php?error=1');
}

$election_result = pg_query_params($conn, "SELECT * FROM elections WHERE id = $1", [$id]);
if (!$election_result || pg_num_rows($election_result) === 0) {
    redirect('index.php?error=1');
}
$election = pg_fetch_assoc($election_result);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $mode = $_POST['voting_access_mode'] ?? 'all';
    $allowed = trim($_POST['allowed_grade_section'] ?? '');

    if (!in_array($mode, ['all', 'restricted'], true)) {
        $error = 'Invalid voting access mode.';
    } elseif ($mode === 'restricted' && $allowed === '') {
        $error = 'Please choose a grade or section.';
    } else {
        if ($mode === 'all') {
            $allowed = null;
        }

        $update = pg_query_params($conn, "
            UPDATE elections
               SET voting_access_mode = $1,
                   allowed_grade_section = $2,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = $3
        ", [$mode, $allowed, $id]);

        if ($update) {
            audit_log($conn, 'election_access_changed', 'election', $id, ['mode' => $mode, 'allowed' => $allowed]);
            redirect('index.php?access_updated=1');
        }

        $error = 'Unable to update voting access.';
    }
}

$sections = [];
$sections_result = pg_query_params($conn, "
    SELECT DISTINCT grade_section
      FROM voters
     WHERE election_id = $1
       AND grade_section IS NOT NULL
       AND BTRIM(grade_section) <> ''
     ORDER BY grade_section
", [$id]);

if ($sections_result) {
    while ($row = pg_fetch_assoc($sections_result)) {
        $section = trim($row['grade_section']);
        if ($section === '') {
            continue;
        }

        if (preg_match('/grade\s*([0-9]+)/i', $section, $match)) {
            $sections['Grade ' . $match[1]] = 'Grade ' . $match[1];
        }

        $sections[$section] = $section;
    }
}

ksort($sections, SORT_NATURAL | SORT_FLAG_CASE);

$current_mode = $_POST['voting_access_mode'] ?? ($election['voting_access_mode'] ?? 'all');
$current_allowed = $_POST['allowed_grade_section'] ?? ($election['allowed_grade_section'] ?? '');
$total_voters_result = pg_query_params($conn, "SELECT COUNT(*) FROM voters WHERE election_id = $1", [$id]);
$blank_section_result = pg_query_params($conn, "
    SELECT COUNT(*)
      FROM voters
     WHERE election_id = $1
       AND (grade_section IS NULL OR BTRIM(grade_section) = '')
", [$id]);
$total_voters = $total_voters_result ? (int) pg_fetch_result($total_voters_result, 0, 0) : 0;
$blank_section_voters = $blank_section_result ? (int) pg_fetch_result($blank_section_result, 0, 0) : 0;
$section_count = count($sections);
$page_title = 'Voting Access - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Voting Access</title>
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
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 3px; }
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
            --label-color: #334155;
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
            --label-color: #cbd5e1;
        }
        body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
        }
        .bg-layer { position: fixed; inset: 0; background: url('../../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; transition: opacity 0.3s; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(6,13,31,0.85); }
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
        [data-theme="dark"] .sidebar { border-right: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header .logo-row { display: flex; align-items: center; gap: 10px; }
        .sidebar-header img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5)); }
        .sidebar-header h2 { font-size: 14px; font-weight: 800; color: white; line-height: 1.2; }
        .sidebar-header small { font-size: 10px; color: rgba(255,255,255,0.4); display: block; }
        .sidebar-nav { flex: 1; padding: 14px 10px; display: flex; flex-direction: column; gap: 3px; }
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
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a.active { background: rgba(59,130,246,0.25); color: #90caf9; border: 1px solid rgba(59,130,246,0.3); }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 13px; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 6px 0; }
        .sidebar-nav a.logout-link { color: rgba(248,113,113,0.8); }
        .sidebar-nav a.logout-link:hover { background: rgba(239,68,68,0.15); color: #f87171; }
        .sidebar-footer { padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 10px; color: rgba(255,255,255,0.25); text-align: center; }
        .admin-wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; }
        .main-content { margin-left: 240px; flex: 1; display: flex; flex-direction: column; min-width: 0; }
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
        .topbar h1 { font-size: 17px; font-weight: 800; color: var(--text-primary); }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-user { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
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
        .topbar-btn:hover { color: var(--accent); border-color: var(--accent); }
        .content-area { padding: 24px 26px; flex: 1; }
        .page-hdr { margin-bottom: 20px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .page-hdr h2 { font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 8px; color: var(--text-primary); }
        .page-hdr p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; max-width: 980px; }
        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(59,130,246,0.14);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(59,130,246,0.18);
        }
        .stat-number { font-size: 18px; font-weight: 800; color: var(--text-primary); }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .access-panel { max-width: 980px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; box-shadow: var(--shadow); overflow: hidden; backdrop-filter: blur(4px); }
        .access-header { padding: 18px 22px; border-bottom: 1px solid var(--border-color); background: var(--bg-panel); display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .access-header h3 { font-size: 16px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .access-header p { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
        .access-body { padding: 22px; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(260px, 0.9fr); gap: 22px; }
        .form-stack { display: grid; gap: 18px; }
        .field { display: grid; gap: 8px; }
        label { font-weight: 800; font-size: 13px; color: var(--label-color); }
        select { width: 100%; padding: 12px 14px; border-radius: 9px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font: inherit; outline: none; }
        select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.12); }
        .hint { color: var(--text-muted); font-size: 12px; line-height: 1.6; }
        .side-note { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; align-self: start; }
        .side-note h4 { font-size: 13px; color: var(--text-primary); margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .rule-list { display: grid; gap: 10px; }
        .rule-item { display: flex; gap: 9px; color: var(--text-secondary); font-size: 12px; line-height: 1.5; }
        .rule-item i { color: var(--accent); margin-top: 2px; width: 14px; }
        .alert { padding: 12px 14px; border-radius: 9px; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #dc2626; font-size: 13px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; padding-top: 4px; }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; font-family: inherit; transition: all 0.2s; }
        .btn-primary { background: #1a237e; color: white; }
        .btn-primary:hover { background: #283593; transform: translateY(-1px); }
        .btn-success { background: #15803d; color: white; }
        .btn-success:hover { background: #166534; transform: translateY(-1px); }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-card); }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-ongoing { background: rgba(34,197,94,0.15); color: #16a34a; border: 1px solid rgba(34,197,94,0.3); }
        .badge-published { background: rgba(251,191,36,0.15); color: #d97706; border: 1px solid rgba(251,191,36,0.3); }
        [data-theme="dark"] .badge-ongoing { color: #4ade80; }
        [data-theme="dark"] .badge-published { color: #fbbf24; }
        .hamburger { display: none; background: none; border: none; color: var(--text-primary); font-size: 20px; cursor: pointer; padding: 4px; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
            .topbar { padding: 14px 16px; }
            .topbar-user span { display: none; }
            .stats-grid { grid-template-columns: 1fr; }
            .access-body { grid-template-columns: 1fr; padding: 18px; }
            .page-hdr { align-items: stretch; }
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

        <main class="main-content">
            <header class="topbar">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                    <h1><i class="fas fa-user-clock" style="font-size: 14px; opacity: 0.7; margin-right: 4px;"></i> Voting Access</h1>
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
                <div class="page-hdr">
                    <div>
                        <h2><i class="fas fa-user-clock"></i> Voting Access Control</h2>
                        <p><?php echo h($election['title']); ?> - choose who can vote at this stage.</p>
                    </div>
                    <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Elections</a>
                </div>

                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($total_voters); ?></div>
                            <div class="stat-label">Assigned voters</div>
                        </div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($section_count); ?></div>
                            <div class="stat-label">Grade/section options</div>
                        </div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-icon"><i class="fas fa-unlock"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($blank_section_voters); ?></div>
                            <div class="stat-label">No-section voters auto-allowed</div>
                        </div>
                    </div>
                </div>

                <form method="POST" class="access-panel">
                            <?php echo csrf_field(); ?>
                    <div class="access-header">
                        <div>
                            <h3><i class="fas fa-sliders-h"></i> Who can vote now?</h3>
                            <p>Open voting for everyone, or limit voting to one grade/section first.</p>
                        </div>
                        <span class="badge badge-<?php echo $current_mode === 'restricted' ? 'published' : 'ongoing'; ?>">
                            <?php echo $current_mode === 'restricted' && $current_allowed !== '' ? h($current_allowed) . ' only' : 'All voters'; ?>
                        </span>
                    </div>

                    <div class="access-body">
                        <div class="form-stack">
                            <?php if ($error): ?>
                                <div class="alert"><?php echo h($error); ?></div>
                            <?php endif; ?>

                            <div class="field">
                                <label for="voting_access_mode">Access Mode</label>
                                <select name="voting_access_mode" id="voting_access_mode" onchange="toggleSectionPicker()">
                                    <option value="all" <?php echo $current_mode === 'all' ? 'selected' : ''; ?>>All voters can vote</option>
                                    <option value="restricted" <?php echo $current_mode === 'restricted' ? 'selected' : ''; ?>>Selected grade/section only</option>
                                </select>
                            </div>

                            <div class="field" id="sectionPicker">
                                <label for="allowed_grade_section">Grade / Section</label>
                                <select name="allowed_grade_section" id="allowed_grade_section">
                                    <option value="">Choose grade or section</option>
                                    <?php foreach ($sections as $section): ?>
                                        <option value="<?php echo h($section); ?>" <?php echo $current_allowed === $section ? 'selected' : ''; ?>>
                                            <?php echo h($section); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">Choosing "Grade 7" allows sections that start with Grade 7, like "Grade 7-A".</div>
                            </div>

                            <div class="actions">
                                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Access</button>
                                <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                            </div>
                        </div>

                        <aside class="side-note">
                            <h4><i class="fas fa-circle-info"></i> Access rules</h4>
                            <div class="rule-list">
                                <div class="rule-item"><i class="fas fa-check"></i><span>All voters lets every active account for this election log in and vote.</span></div>
                                <div class="rule-item"><i class="fas fa-check"></i><span>Selected grade/section blocks other voters until their group is opened.</span></div>
                                <div class="rule-item"><i class="fas fa-check"></i><span>Accounts with no grade/section stay allowed automatically.</span></div>
                            </div>
                        </aside>
                    </div>
                </form>
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
                    window.location.href = '../../logout.php';
                }
            });
        }

        function toggleSectionPicker() {
            const mode = document.getElementById('voting_access_mode').value;
            document.getElementById('sectionPicker').style.display = mode === 'restricted' ? 'grid' : 'none';
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
        toggleSectionPicker();
    </script>
</body>
</html>
