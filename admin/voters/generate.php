<?php
// admin/voters/generate.php
$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$generated = [];
$error     = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token_is_valid($token) {
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_token_is_valid($_POST['csrf_token'] ?? '')) {
    $error = 'Security token expired. Please refresh the page and try again.';
}

if (!ensure_voters_election_id_column($conn)) {
    $error = 'Unable to prepare election-specific voter accounts. Please check the database permissions.';
}

$elections = [];
$elections_q = pg_query($conn, "
    SELECT id, title, status, start_time, end_time
      FROM elections
     WHERE status <> 'closed'
     ORDER BY start_time DESC, id DESC
");
if ($elections_q) {
    while ($row = pg_fetch_assoc($elections_q)) {
        $elections[] = $row;
    }
}

function selected_election($conn, $election_id) {
    if ($election_id <= 0) {
        return null;
    }

    $result = pg_query_params($conn, "
        SELECT id, title, status
          FROM elections
         WHERE id = $1
           AND status NOT IN ('closed', 'ongoing', 'paused')
    ", [$election_id]);

    return ($result && pg_num_rows($result) > 0) ? pg_fetch_assoc($result) : null;
}


function generate_secure_voter_password($length = 14) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function admin_password_is_valid($conn, $admin_id, $password) {
    if (empty($password)) {
        return false;
    }

    $result = pg_query_params($conn, "SELECT password_hash FROM admins WHERE id = $1", [$admin_id]);
    if (!$result || pg_num_rows($result) === 0) {
        return false;
    }

    $admin = pg_fetch_assoc($result);
    return password_verify($password, $admin['password_hash']);
}
// Manual / bulk generate
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'single') {
    $prefix        = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['prefix'] ?? 'VOTER')));
    $count         = intval($_POST['count'] ?? 1);
    $grade_section = trim($_POST['grade_section'] ?? '');
    $election_id   = intval($_POST['election_id'] ?? 0);
    $admin_password = $_POST['admin_password'] ?? '';
    $election      = selected_election($conn, $election_id);

    if (empty($prefix)) $prefix = 'VOTER';
    if (!$election) {
        $error = 'Account generation is locked after voting starts. Select a draft or published election.';
    } elseif (!admin_password_is_valid($conn, $_SESSION['admin_id'], $admin_password)) {
        $error = 'Admin password confirmation is required to generate voter accounts.';
    } elseif ($count < 1 || $count > 100) {
        $error = 'Please enter a valid count between 1 and 100.';
    } else {
        $last_q    = pg_query_params($conn,
            "SELECT voter_id FROM voters WHERE voter_id LIKE $1 ORDER BY id DESC LIMIT 1",
            [$prefix . '-%']
        );
        $start_num = 1;
        if ($last_q && pg_num_rows($last_q) > 0) {
            $last_id   = pg_fetch_result($last_q, 0, 'voter_id');
            $parts     = explode('-', $last_id);
            $start_num = intval(end($parts)) + 1;
        }

        for ($i = 0; $i < $count; $i++) {
            $voter_id    = $prefix . '-' . str_pad($start_num + $i, 4, '0', STR_PAD_LEFT);
            $raw_pass    = generate_secure_voter_password();
            $hashed      = password_hash($raw_pass, PASSWORD_DEFAULT);
            $full_name   = 'Voter ' . ($start_num + $i);

            $exists = pg_query_params($conn, "SELECT id FROM voters WHERE voter_id = $1", [$voter_id]);
            if (pg_num_rows($exists) === 0) {
                pg_query_params($conn,
                    "INSERT INTO voters (voter_id, password_hash, full_name, grade_section, election_id) VALUES ($1, $2, $3, $4, $5)",
                    [$voter_id, $hashed, $full_name, $grade_section, $election_id]
                );
                $generated[] = [
                    'voter_id' => $voter_id,
                    'password' => $raw_pass,
                    'full_name' => $full_name,
                    'grade_section' => $grade_section,
                    'election_id' => $election_id,
                    'election_title' => $election['title'],
                ];
            }
        }

        if (!empty($generated)) {
            audit_log($conn, 'voters_generated', 'election', $election_id, ['count' => count($generated), 'method' => 'manual']);
            $_SESSION['last_generated']  = $generated;
            redirect('view_credentials.php?generated=1');
        } else {
            $error = 'No accounts generated — all voter IDs already exist.';
        }
    }
}

// CSV import
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'csv') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['csv_prefix'] ?? 'VOTER')));
        $election_id = intval($_POST['csv_election_id'] ?? 0);
        $admin_password = $_POST['csv_admin_password'] ?? '';
        $election = selected_election($conn, $election_id);
        if (empty($prefix)) $prefix = 'VOTER';

        if (!$election) {
            $error = 'Account generation is locked after voting starts. Select a draft or published election.';
        } elseif (!admin_password_is_valid($conn, $_SESSION['admin_id'], $admin_password)) {
            $error = 'Admin password confirmation is required to import voter accounts.';
        } else {
        $last_q    = pg_query_params($conn,
            "SELECT voter_id FROM voters WHERE voter_id LIKE $1 ORDER BY id DESC LIMIT 1",
            [$prefix . '-%']
        );
        $start_num = 1;
        if ($last_q && pg_num_rows($last_q) > 0) {
            $last_id   = pg_fetch_result($last_q, 0, 'voter_id');
            $parts     = explode('-', $last_id);
            $start_num = intval(end($parts)) + 1;
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $row_count = 0;
        if ($handle) {
            fgetcsv($handle); // skip header
            while (($row = fgetcsv($handle)) !== false) {
                if (empty(trim($row[0] ?? ''))) continue;
                $full_name     = trim($row[0]);
                $grade_section = isset($row[1]) ? trim($row[1]) : '';
                $voter_id      = $prefix . '-' . str_pad($start_num, 4, '0', STR_PAD_LEFT);
                $raw_pass    = generate_secure_voter_password();

                $exists = pg_query_params($conn, "SELECT id FROM voters WHERE voter_id = $1", [$voter_id]);
                if (pg_num_rows($exists) === 0) {
                    pg_query_params($conn,
                        "INSERT INTO voters (voter_id, password_hash, full_name, grade_section, election_id) VALUES ($1, $2, $3, $4, $5)",
                        [$voter_id, password_hash($raw_pass, PASSWORD_DEFAULT), $full_name, $grade_section, $election_id]
                    );
                    $generated[] = [
                        'voter_id' => $voter_id,
                        'password' => $raw_pass,
                        'full_name' => $full_name,
                        'grade_section' => $grade_section,
                        'election_id' => $election_id,
                        'election_title' => $election['title'],
                    ];
                    $start_num++;
                    $row_count++;
                }
            }
            fclose($handle);
        }

        if ($row_count > 0) {
            audit_log($conn, 'voters_generated', 'election', $election_id, ['count' => count($generated), 'method' => 'csv']);
            $_SESSION['last_generated'] = $generated;
            redirect('view_credentials.php?generated=1');
        } else {
            $error = 'No valid rows found in the CSV file.';
        }
        }
    } else {
        $error = 'Please select a valid CSV file to upload.';
    }
}

$page_title = 'Generate Voter Accounts - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Generate Voters</title>
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
        /* Deep blue theme – same as in index.php (abbreviated for brevity) */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg-body); color: var(--text-primary); }
        :root { --bg-body: #f0f4ff; --bg-card: rgba(255,255,255,0.92); --bg-panel: rgba(248,250,255,0.9); --bg-input: #f1f5f9; --text-primary: #1e293b; --text-secondary: #475569; --text-muted: #64748b; --border-color: rgba(0,0,0,0.1); --accent: #3b82f6; --accent-primary: #1a237e; --accent-primary-hover: #283593; --shadow: 0 2px 12px rgba(0,0,0,0.08); --topbar-bg: rgba(255,255,255,0.85); --input-border: #cbd5e1; --label-color: #334155; }
        [data-theme="dark"] { --bg-body: #060d1f; --bg-card: #0f1e4a; --bg-panel: rgba(20,24,36,0.9); --bg-input: #252a3a; --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8; --border-color: rgba(255,255,255,0.1); --accent: #60a5fa; --accent-primary: #3b82f6; --accent-primary-hover: #60a5fa; --topbar-bg: #0f1e4a; --input-border: #3d435a; --label-color: #cbd5e1; }
        .bg-layer { position: fixed; inset: 0; background: url('../../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { background: rgba(6,13,31,0.85); }
        .sidebar { width: 240px; min-height: 100vh; background: #0f1e4a; display: flex; flex-direction: column; position: fixed; top:0; left:0; z-index:100; transition: transform 0.3s; }
        .sidebar-header { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header .logo-row { display: flex; align-items: center; gap: 10px; }
        .sidebar-header img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5)); }
        .sidebar-header h2 { font-size: 14px; font-weight: 800; color: white; line-height: 1.2; }
        .sidebar-header small { font-size: 10px; color: rgba(255,255,255,0.4); display: block; }
        .sidebar-nav { flex:1; padding: 14px 10px; display: flex; flex-direction: column; gap: 3px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 13px; border-radius: 10px; text-decoration: none; color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a.active { background: rgba(59,130,246,0.25); color: #90caf9; border: 1px solid rgba(59,130,246,0.3); }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 13px; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 6px 0; }
        .sidebar-nav a.logout-link { color: rgba(248,113,113,0.8); }
        .sidebar-nav a.logout-link:hover { background: rgba(239,68,68,0.15); color: #f87171; }
        .sidebar-footer { padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 10px; color: rgba(255,255,255,0.25); text-align: center; }
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
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; font-size: 13.5px; background: var(--bg-card); border: 1px solid var(--border-color); }
        .alert-error { border-left: 3px solid #ef4444; }
        .page-hdr { margin-bottom: 20px; }
        .page-hdr h2 { font-size: 20px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .gen-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 860px) { .gen-grid { grid-template-columns: 1fr; } }
        .card-panel { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; backdrop-filter: blur(4px); }
        .card-panel-header { padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 14px; }
        .card-panel-header h3 { font-size: 16px; margin-bottom: 2px; }
        .card-panel-body { padding: 22px; }
        .card-icon { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #fff; flex-shrink: 0; }
        .card-icon.indigo { background: linear-gradient(135deg, #1a237e, #3949ab); }
        .card-icon.teal   { background: linear-gradient(135deg, #0277bd, #039be5); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: var(--label-color); }
        .form-group input, .form-group select { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 14px; transition: all 0.2s; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .btn-submit { width: 100%; padding: 13px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s; }
        .btn-submit.manual { background: var(--accent-primary); color: #fff; }
        .btn-submit.csv    { background: #0284c7; color: #fff; }
        .btn-submit:hover { transform: translateY(-1px); opacity: 0.9; }
        .drop-zone { border: 2px dashed var(--border-color); border-radius: 10px; padding: 28px; text-align: center; cursor: pointer; transition: all 0.25s; }
        .drop-zone:hover { border-color: var(--accent); background: rgba(2,119,189,0.05); }
        .csv-hint { background: var(--bg-panel); border-radius: 10px; padding: 14px 16px; margin-top: 16px; font-size: 13px; }
        .csv-hint code { display: block; background: var(--bg-input); padding: 10px 14px; border-radius: 6px; font-size: 12px; white-space: pre; overflow-x: auto; margin-top: 8px; }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 7px; }
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
                <img src="../../assets/images/OLLCF_LOGO.png" alt="OLLCF">
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
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                <h1>Generate Voter Accounts</h1>
            </div>
            <div class="topbar-right">
                <div class="topbar-user"><div class="avatar"><i class="fas fa-user-shield"></i></div><span><?php echo h($_SESSION['admin_full_name']); ?></span></div>
                <button class="topbar-btn" onclick="toggleTheme()" title="Toggle Theme"><i class="fas fa-moon" id="themeIcon"></i></button>
                <button class="topbar-btn" onclick="confirmLogout(event)" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>

        <div class="content-area">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="page-hdr">
                <h2><i class="fas fa-plus-circle"></i> Generate Voter Accounts</h2>
                <p>Create individual voter credentials or bulk‑import from a CSV file</p>
            </div>

            <div class="gen-grid">
                <!-- Manual generate -->
                <div class="card-panel">
                    <div class="card-panel-header">
                        <div class="card-icon indigo"><i class="fas fa-magic"></i></div>
                        <div><h3>Manual Generate</h3><span style="font-size:12px;color:var(--text-muted);">Bulk‑generate up to 100 accounts</span></div>
                    </div>
                    <div class="card-panel-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="single">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <div class="form-group">
                                <label>Election <span style="color:#c62828;">*</span></label>
                                <select name="election_id" required>
                                    <option value="">Select election</option>
                                    <?php foreach ($elections as $election): ?>
                                        <?php $is_locked = in_array($election['status'], ['ongoing', 'paused', 'closed'], true); ?>
                                        <option value="<?php echo (int) $election['id']; ?>" <?php echo (isset($_POST['election_id']) && intval($_POST['election_id']) === (int) $election['id']) ? 'selected' : ''; ?> <?php echo $is_locked ? 'disabled' : ''; ?>>
                                            <?php echo h($election['title']); ?> (<?php echo h(ucfirst($election['status'])); ?><?php echo $is_locked ? ' - locked' : ''; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color:var(--text-muted);display:block;margin-top:4px;">These login credentials will work only for the selected election. Ongoing elections are locked for account generation.</small>
                            </div>
                            <div class="form-group">
                                <label>Voter ID Prefix</label>
                                <input type="text" name="prefix" value="VOTER" maxlength="10" placeholder="e.g. VOTER, STU, G12A" style="text-transform:uppercase;">
                                <small style="color:var(--text-muted);display:block;margin-top:4px;">IDs will be formatted as <strong>PREFIX-0001</strong>, <strong>PREFIX-0002</strong>…</small>
                            </div>
                            <div class="form-group">
                                <label>Number of Accounts <span style="color:#c62828;">*</span></label>
                                <input type="number" name="count" value="1" min="1" max="100" required>
                            </div>
                            <div class="form-group">
                                <label>Grade / Section <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                                <input type="text" name="grade_section" placeholder="e.g. Grade 12-A">
                            </div>
                            <div class="form-group">
                                <label>Confirm Admin Password <span style="color:#c62828;">*</span></label>
                                <input type="password" name="admin_password" autocomplete="current-password" required placeholder="Enter your admin password">
                                <small style="color:var(--text-muted);display:block;margin-top:4px;">Security check required before creating voter accounts.</small>
                            </div>
                            <button type="submit" class="btn-submit manual"><i class="fas fa-magic"></i> Generate Accounts</button>
                        </form>
                    </div>
                </div>

                <!-- CSV import -->
                <div class="card-panel">
                    <div class="card-panel-header">
                        <div class="card-icon teal"><i class="fas fa-file-csv"></i></div>
                        <div><h3>Import from CSV</h3><span style="font-size:12px;color:var(--text-muted);">Bulk‑import voters from a spreadsheet</span></div>
                    </div>
                    <div class="card-panel-body">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="csv">
                            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                            <div class="form-group">
                                <label>Election <span style="color:#c62828;">*</span></label>
                                <select name="csv_election_id" required>
                                    <option value="">Select election</option>
                                    <?php foreach ($elections as $election): ?>
                                        <?php $is_locked = in_array($election['status'], ['ongoing', 'paused', 'closed'], true); ?>
                                        <option value="<?php echo (int) $election['id']; ?>" <?php echo (isset($_POST['csv_election_id']) && intval($_POST['csv_election_id']) === (int) $election['id']) ? 'selected' : ''; ?> <?php echo $is_locked ? 'disabled' : ''; ?>>
                                            <?php echo h($election['title']); ?> (<?php echo h(ucfirst($election['status'])); ?><?php echo $is_locked ? ' - locked' : ''; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color:var(--text-muted);display:block;margin-top:4px;">Imported accounts will be valid only for this election. Ongoing elections are locked for account generation.</small>
                            </div>
                            <div class="form-group">
                                <label>Voter ID Prefix</label>
                                <input type="text" name="csv_prefix" value="VOTER" maxlength="10" style="text-transform:uppercase;">
                            </div>
                            <div class="form-group">
                                <label>CSV File</label>
                                <div class="drop-zone" id="dropZone" onclick="document.getElementById('csvFile').click()">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Click to browse or drag & drop</p>
                                    <span class="fname" id="fName">No file chosen</span>
                                    <input type="file" name="csv_file" id="csvFile" accept=".csv" required style="display:none;" onchange="onFile(this)">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm Admin Password <span style="color:#c62828;">*</span></label>
                                <input type="password" name="csv_admin_password" autocomplete="current-password" required placeholder="Enter your admin password">
                                <small style="color:var(--text-muted);display:block;margin-top:4px;">Security check required before importing voter accounts.</small>
                            </div>
                            <button type="submit" class="btn-submit csv" style="margin-bottom:16px;"><i class="fas fa-upload"></i> Upload & Generate</button>
                        </form>
                        <div class="csv-hint">
                            <h5 style="margin-bottom:8px;"><i class="fas fa-info-circle" style="margin-right:6px;"></i> CSV Format</h5>
                            <code>Full Name, Grade/Section
Juan Dela Cruz, Grade 12-A
Maria Santos, Grade 12-B
Pedro Reyes, Grade 11-A</code>
                        </div>
                    </div>
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
    function onFile(input) {
        document.getElementById('fName').textContent = input.files[0]?.name ?? 'No file chosen';
    }
    const dz = document.getElementById('dropZone');
    if (dz) {
        ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
        ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); }));
        dz.addEventListener('drop', e => {
            e.preventDefault();
            const fi = document.getElementById('csvFile');
            fi.files = e.dataTransfer.files;
            onFile(fi);
        });
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
