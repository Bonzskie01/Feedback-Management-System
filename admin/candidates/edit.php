<?php
// admin/candidates/edit.php

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('./index.php');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    redirect('index.php?error=1');
}

$candidate_result = pg_query_params($conn, "SELECT c.*, p.name as position_name, p.election_id, e.title as election_title 
                                             FROM candidates c 
                                             JOIN positions p ON c.position_id = p.id 
                                             JOIN elections e ON p.election_id = e.id 
                                             WHERE c.id = $1", array($id));
if (pg_num_rows($candidate_result) === 0) {
    redirect('index.php?error=1');
}
$candidate = pg_fetch_assoc($candidate_result);

$error = '';

function save_candidate_photo($root_path, $existing_path = '') {
    return save_secure_candidate_photo_upload($root_path, $_FILES['photo'] ?? null, $existing_path);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $position_id = intval($_POST['position_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $party_list_id = !empty($_POST['party_list_id']) ? intval($_POST['party_list_id']) : null;
    $platform = trim($_POST['platform'] ?? '');
    $is_approved = isset($_POST['is_approved']) ? 'true' : 'false';
    $is_disqualified = isset($_POST['is_disqualified']) ? 'true' : 'false';
    if ($is_approved === 'true' && $is_disqualified === 'true') {
        $is_approved = 'false';
    }

    $positionElectionId = position_election_id($conn, $position_id);
    if (!election_is_mutable($conn, (int)$candidate['election_id'])) {
        $error = 'Candidate changes are locked for this election.';
    } elseif ($position_id <= 0 || $positionElectionId !== (int)$candidate['election_id']) {
        $error = 'The selected position does not belong to this election.';
    } elseif (!party_list_matches_election($conn, $party_list_id, (int)$candidate['election_id'])) {
        $error = 'The selected party list does not belong to this election.';
    } elseif (empty($full_name)) {
        $error = 'Full name is required.';
    } else {
        try {
            $photo_path = save_candidate_photo($root_path, $candidate['photo_path']);
            $update_sql = "UPDATE candidates SET position_id = $1, full_name = $2, party_list_id = $3, platform = $4, photo_path = $5, is_approved = $6, is_disqualified = $7 WHERE id = $8";
            $result = pg_query_params($conn, $update_sql, array($position_id, $full_name, $party_list_id, $platform, $photo_path, $is_approved, $is_disqualified, $id));
            if ($result) {
                audit_log($conn, 'candidate_updated', 'candidate', $id, [
                    'position_from' => (int)$candidate['position_id'],
                    'position_to' => $position_id
                ]);
                redirect('index.php?updated=1');
            }
            $error = 'Failed to update candidate.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$page_title = 'Edit Candidate - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Edit Candidates</title>
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
        /* Same deep blue theme as create.php – full style block (same as create.php) */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; }
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
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; font-size: 13.5px; background: var(--bg-card); border: 1px solid var(--border-color); }
        .alert-error { border-left: 3px solid #ef4444; }
        .form-section { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); backdrop-filter: blur(4px); }
        .form-section-header { padding: 18px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 12px; }
        .form-section-header h3 { font-size: 16px; color: var(--text-primary); }
        .form-section-header p { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
        .form-section-body { padding: 24px; }
        .icon-circle { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; background: linear-gradient(135deg, #e65100, #ff9800); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: var(--label-color); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 14px; font-family: inherit; transition: all 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .form-group input::placeholder, .form-group textarea::placeholder { color: var(--placeholder-color); }
        .form-group small { display: block; margin-top: 5px; font-size: 11px; color: var(--text-muted); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
        .photo-preview { width: 100px; height: 100px; border-radius: 12px; background: var(--bg-panel); display: flex; align-items: center; justify-content: center; overflow: hidden; margin-top: 10px; border: 2px dashed var(--border-color); }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .photo-preview i { font-size: 36px; color: var(--text-muted); }
        .checkbox-section { display: flex; gap: 20px; margin-top: 10px; padding: 15px; background: var(--bg-panel); border-radius: 10px; border: 1px solid var(--border-color); }
        .checkbox-group { margin-bottom: 0; flex: 1; }
        .checkbox-group label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; margin-bottom: 0; }
        .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent); }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--bg-card); }
        .btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 7px; }
        .btn-save { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: var(--accent-primary); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-save:hover { background: var(--accent-primary-hover); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(249,115,22,0.3); }
        .btn-cancel { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: #546e7a; color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-cancel:hover { background: #455a64; }
        [data-theme="dark"] .btn-cancel { background: #334155; }
        [data-theme="dark"] .btn-cancel:hover { background: #475569; }
        .info-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .info-badge.election { background: #1a2c3e; color: #90caf9; }
        .info-badge.position { background: #2a1a3e; color: #ce93d8; }
        [data-theme="dark"] .info-badge.election { background: #1a2c3e; color: #90caf9; }
        [data-theme="dark"] .info-badge.position { background: #2a1a3e; color: #ce93d8; }
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
                <img src="../../<?php echo site_logo(); ?>" alt="Logo">
                <div><h2>Voting System</h2><small>Admin Panel</small></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../elections/index.php"><i class="fas fa-calendar-alt"></i> Elections</a>
            <a href="../party_lists/index.php"><i class="fas fa-flag"></i> Party Lists</a>
            <a href="../positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
            <a href="index.php" class="active"><i class="fas fa-users"></i> Candidates</a>
            <a href="../voters/index.php"><i class="fas fa-user-check"></i> Voters</a>
            <a href="../results/live.php"><i class="fas fa-chart-bar"></i> Live Results</a>
            <div class="nav-divider"></div>
            <a href="../settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="../profile.php"><i class="fas fa-shield-alt"></i>2FA</a>
            <a href="#" class="logout-link" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">© <?php echo date('Y'); ?> OLLCF Information Systems</div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Candidates</a>
                <h1>Edit Candidate</h1>
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
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?></div>
            <?php endif; ?>

            <div class="form-section">
                <div class="form-section-header">
                    <div class="icon-circle"><i class="fas fa-edit"></i></div>
                    <div>
                        <h3>Edit: <?php echo h($candidate['full_name']); ?></h3>
                        <div style="display: flex; gap: 8px; margin-top: 6px;">
                            <span class="info-badge election"><i class="fas fa-calendar-alt"></i> <?php echo h($candidate['election_title']); ?></span>
                            <span class="info-badge position"><i class="fas fa-trophy"></i> <?php echo h($candidate['position_name']); ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-section-body">
                    <form method="POST" action="" enctype="multipart/form-data" id="editForm">
                        <?php echo csrf_field(); ?>

                        <div class="form-group">
                            <label>Full Name <span style="color: #c62828;">*</span></label>
                            <input type="text" name="full_name" required value="<?php echo h($_POST['full_name'] ?? $candidate['full_name']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Position <span style="color: #c62828;">*</span></label>
                            <select name="position_id" class="filter-select" required>
                                <option value="">-- Select Position --</option>
                                <?php
                                $positions_result = pg_query_params($conn,
                                    "SELECT id, name FROM positions WHERE election_id = $1 ORDER BY display_order, id",
                                    [(int)$candidate['election_id']]);
                                $selected_position_id = isset($_POST['position_id'])
                                    ? (int)$_POST['position_id']
                                    : (int)$candidate['position_id'];
                                while ($position = pg_fetch_assoc($positions_result)):
                                    $selected = ($selected_position_id === (int)$position['id']) ? 'selected' : '';
                                ?>
                                    <option value="<?= (int)$position['id'] ?>" <?= $selected ?>><?= h($position['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Party List</label>
                                <select name="party_list_id" class="filter-select">
                                    <option value="">-- No Party List --</option>
                                    <?php
                                    $party_lists_result = pg_query_params($conn,
                                        "SELECT id, name FROM party_lists WHERE election_id = $1 ORDER BY name, id",
                                        [(int)$candidate['election_id']]);
                                    while ($pl = pg_fetch_assoc($party_lists_result)):
                                        $selected_party_list_id = $_SERVER['REQUEST_METHOD'] === 'POST'
                                            ? $party_list_id
                                            : $candidate['party_list_id'];
                                        $selected = ($selected_party_list_id !== null && (int)$selected_party_list_id === (int)$pl['id']) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $pl['id'] ?>" <?= $selected ?>><?= h($pl['name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Photo</label>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewPhoto(this)">
                                <small>Leave empty to keep current photo</small>
                                <div class="photo-preview" id="photoPreview">
                                    <?php if ($candidate['photo_path'] && file_exists('../../' . $candidate['photo_path'])): ?>
                                        <img src="../../<?php echo h($candidate['photo_path']); ?>" alt="Current photo">
                                    <?php else: ?>
                                        <i class="fas fa-camera"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Platform / Bio</label>
                            <textarea name="platform" rows="4"><?php echo h($candidate['platform']); ?></textarea>
                        </div>

                        <!-- Mutually exclusive checkboxes: only one can be checked at a time -->
                        <div class="checkbox-section">
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" name="is_approved" id="approvedCheckbox" <?php echo $candidate['is_approved'] === 't' ? 'checked' : ''; ?>>
                                    <span>✅ <strong>Approved</strong> — Candidate is visible on the ballot</span>
                                </label>
                            </div>
                            <div class="checkbox-group">
                                <label>
                                    <input type="checkbox" name="is_disqualified" id="disqualifiedCheckbox" <?php echo $candidate['is_disqualified'] === 't' ? 'checked' : ''; ?>>
                                    <span>🚫 <strong>Disqualified</strong> — Candidate is removed from ballot</span>
                                </label>
                            </div>
                        </div>
                        <small style="display: block; margin-top: 8px; color: var(--text-muted);">Only one status can be selected. Leave both unchecked for pending approval.</small>

                        <div style="display: flex; gap: 12px; margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Update Candidate</button>
                            <a href="index.php" class="btn-cancel"><i class="fas fa-times"></i> Cancel</a>
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
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
        localStorage.setItem('vms-theme', isDark ? 'light' : 'dark');
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
            title: 'Logging Out?',
            text: 'End your admin session?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: 'transparent',
            confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout',
            cancelButtonText: 'Cancel',
            background: isDark ? '#0f1e4a' : '#fff',
            color: isDark ? '#f1f5f9' : '#1e293b'
        }).then(result => {
            if (result.isConfirmed) window.location.href = '../logout.php';
        });
    }

    function previewPhoto(input) {
        const preview = document.getElementById('photoPreview');
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Mutually exclusive checkbox logic
    const approvedCheckbox = document.getElementById('approvedCheckbox');
    const disqualifiedCheckbox = document.getElementById('disqualifiedCheckbox');

    if (approvedCheckbox && disqualifiedCheckbox) {
        approvedCheckbox.addEventListener('change', function() {
            if (this.checked) {
                disqualifiedCheckbox.checked = false;
            }
        });
        disqualifiedCheckbox.addEventListener('change', function() {
            if (this.checked) {
                approvedCheckbox.checked = false;
            }
        });
    }

    // Mobile sidebar toggle
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