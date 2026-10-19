<?php
// admin/elections/edit.php

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

$election_sql = "SELECT * FROM elections WHERE id = $1";
$election_result = pg_query_params($conn, $election_sql, array($id));
if (pg_num_rows($election_result) === 0) {
    redirect('index.php?error=1');
}
$election = pg_fetch_assoc($election_result);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    if (!election_is_mutable($conn, $id)) {
        redirect('index.php?error=election_locked');
    }
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    if (empty($title) || empty($start_time) || empty($end_time)) {
        $error = 'Please fill in all required fields.';
    } elseif (strtotime($end_time) <= strtotime($start_time)) {
        $error = 'End time must be after start time.';
    } else {
        $update_sql = "UPDATE elections SET title = $1, description = $2, start_time = $3, end_time = $4 WHERE id = $5";
        $result = pg_query_params($conn, $update_sql, array($title, $description, $start_time, $end_time, $id));

        if ($result) {
            audit_log($conn, 'election_updated', 'election', $id);
            redirect('index.php?updated=1');
        } else {
            $error = 'Failed to update election.';
        }
    }
}

$page_title = 'Edit Election - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Edit Election Details</title>
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
        /* Same style as create.php – keep as provided */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.3); border-radius: 3px; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; min-height: 100vh; background: var(--bg-body); color: var(--text-primary); transition: background 0.3s, color 0.3s; }
        :root {
            --bg-body: #f0f4ff; --bg-card: #ffffff; --bg-panel: #f8faff; --bg-input: #f1f5f9;
            --bg-sidebar: #0f1e4a; --text-primary: #1e293b; --text-secondary: #475569;
            --text-muted: #64748b; --border-color: rgba(0,0,0,0.08); --border-light: #e2e8f0;
            --accent: #3b82f6; --accent-primary: #e65100; --accent-primary-hover: #f97316;
            --shadow: 0 2px 12px rgba(0,0,0,0.08); --shadow-md: 0 4px 20px rgba(0,0,0,0.1);
            --topbar-bg: rgba(255,255,255,0.9); --input-border: #cbd5e1; --label-color: #334155;
            --placeholder-color: #94a3b8;
        }
        [data-theme="dark"] {
            --bg-body: #0a0e1a; --bg-card: #0f1e4a; --bg-panel: #141824; --bg-input: #0f1e4a;
            --bg-sidebar: #0a0e1a; --text-primary: #f1f5f9; --text-secondary: #cbd5e1;
            --text-muted: #94a3b8; --border-color: rgba(255,255,255,0.1); --border-light: #2d3348;
            --accent: #60a5fa; --accent-primary: #f97316; --accent-primary-hover: #fb923c;
            --shadow: 0 4px 24px rgba(0,0,0,0.4); --shadow-md: 0 6px 28px rgba(0,0,0,0.5);
            --topbar-bg: #0f1e4a; --input-border: #3d435a; --label-color: #cbd5e1;
            --placeholder-color: #64748b;
        }
        .bg-layer { position: fixed; inset: 0; background: url('../../<?php echo site_background(); ?>') center/cover no-repeat; z-index: 0; }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(6,13,31,0.85); }
        .sidebar { width: 240px; min-height: 100vh; background: var(--bg-sidebar); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; z-index: 100; transition: transform 0.3s; }
        [data-theme="dark"] .sidebar { background: #0f1e4a; backdrop-filter: blur(20px); border-right: 1px solid var(--border-color); }
        .sidebar-header { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-header .logo-row { display: flex; align-items: center; gap: 10px; }
        .sidebar-header img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 2px 6px rgba(0,0,0,0.5)); }
        .sidebar-header h2 { font-size: 14px; font-weight: 800; color: white; line-height: 1.2; }
        .sidebar-header small { font-size: 10px; color: rgba(255,255,255,0.4); display: block; }
        .sidebar-nav { flex: 1; padding: 14px 10px; display: flex; flex-direction: column; gap: 3px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 10px; padding: 10px 13px; border-radius: 10px; text-decoration: none; color: rgba(255,255,255,0.65); font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a.active { background: rgba(59,130,246,0.25); color: #90caf9; border: 1px solid rgba(59,130,246,0.3); }
        .sidebar-nav a i { width: 18px; text-align: center; font-size: 13px; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.08); margin: 6px 0; }
        .sidebar-nav a.logout-link { color: rgba(248,113,113,0.8); }
        .sidebar-nav a.logout-link:hover { background: rgba(239,68,68,0.15); color: #f87171; }
        .sidebar-footer { padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 10px; color: rgba(255,255,255,0.25); text-align: center; }
        .admin-wrapper { display: flex; min-height: 100vh; position: relative; z-index: 1; }
        .main-content { margin-left: 240px; flex: 1; display: flex; flex-direction: column; }
        .topbar { padding: 14px 26px; background: var(--topbar-bg); backdrop-filter: blur(16px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; box-shadow: var(--shadow); }
        .topbar h1 { font-size: 17px; font-weight: 800; color: var(--text-primary); }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .topbar-user { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary); }
        .topbar-user .avatar { width: 30px; height: 30px; border-radius: 50%; background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.3); display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--accent); }
        .topbar-btn { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); padding: 6px 10px; cursor: pointer; font-size: 13px; transition: all 0.2s; }
        .topbar-btn:hover { color: var(--accent); border-color: var(--accent); }
        .content-area { padding: 24px 26px; flex: 1; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; font-size: 13.5px; }
        .alert-error { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #dc2626; }
        [data-theme="dark"] .alert-error { color: #fca5a5; }
        .form-section { background: var(--bg-card); border-radius: 16px; box-shadow: var(--shadow-md); overflow: hidden; border: 1px solid var(--border-color); }
        .form-section-header { padding: 20px 25px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 15px; }
        .form-section-header h3 { font-size: 18px; color: var(--text-primary); }
        .form-section-header p { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
        .form-section-body { padding: 25px; }
        .icon-circle { width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: white; background: linear-gradient(135deg, #e65100, #ff9800); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px; color: var(--label-color); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--input-border); background: var(--bg-input); color: var(--text-primary); font-size: 14px; font-family: inherit; transition: all 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .form-group input::placeholder, .form-group textarea::placeholder { color: var(--placeholder-color); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
        .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 700; text-decoration: none; border: none; cursor: pointer; font-family: inherit; transition: all 0.2s; }
        .btn-secondary { background: var(--bg-panel); color: var(--text-secondary); border: 1px solid var(--border-color); }
        .btn-sm { padding: 6px 11px; font-size: 12px; border-radius: 7px; }
        .btn-save { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: var(--accent-primary); color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-save:hover { background: var(--accent-primary-hover); transform: translateY(-2px); box-shadow: 0 4px 15px rgba(249,115,22,0.3); }
        .btn-cancel { display: inline-flex; align-items: center; gap: 8px; padding: 12px 28px; background: #546e7a; color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; }
        .btn-cancel:hover { background: #455a64; }
        [data-theme="dark"] .btn-cancel { background: #334155; }
        [data-theme="dark"] .btn-cancel:hover { background: #475569; }
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
                    <h1>Edit Election</h1>
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
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo h($error); ?>
                    </div>
                <?php endif; ?>

                <div class="form-section">
                    <div class="form-section-header">
                        <div class="icon-circle"><i class="fas fa-edit"></i></div>
                        <div>
                            <h3><?php echo h($election['title']); ?></h3>
                            <p>Update election details</p>
                        </div>
                    </div>
                    <div class="form-section-body">
                        <form method="POST" action="">
                        <?php echo csrf_field(); ?>
                            <div class="form-group">
                                <label>Election Title <span style="color: #c62828;">*</span></label>
                                <input type="text" name="title" required value="<?php echo h($election['title']); ?>">
                            </div>

                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" rows="3"><?php echo h($election['description']); ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Start Date & Time <span style="color: #c62828;">*</span></label>
                                    <input type="datetime-local" name="start_time" required value="<?php echo date('Y-m-d\TH:i', strtotime($election['start_time'])); ?>">
                                </div>
                                <div class="form-group">
                                    <label>End Date & Time <span style="color: #c62828;">*</span></label>
                                    <input type="datetime-local" name="end_time" required value="<?php echo date('Y-m-d\TH:i', strtotime($election['end_time'])); ?>">
                                </div>
                            </div>

                            <div style="display: flex; gap: 12px; margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                                <button type="submit" class="btn-save">
                                    <i class="fas fa-save"></i> Update Election
                                </button>
                                <a href="index.php" class="btn-cancel">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
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
                cancelButtonColor: '#1e293b',
                confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout',
                cancelButtonText: 'Cancel',
                background: isDark ? '#0f1e4a' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#1e293b'
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