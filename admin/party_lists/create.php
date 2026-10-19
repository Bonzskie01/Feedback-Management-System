<?php
require_once '../../config/database.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

// Fetch elections for dropdown
$elections_result = pg_query($conn, "SELECT id, title, status FROM elections WHERE status IN ('draft', 'published') ORDER BY created_at DESC");

$error = null;
$success = false;
$name = '';
$description = '';
$election_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $election_id = intval($_POST['election_id'] ?? 0);

    if (empty($name)) {
        $error = "Party list name is required.";
    } elseif ($election_id <= 0) {
        $error = "Please select an election.";
    } elseif (!election_is_mutable($conn, $election_id)) {
        $error = 'Party-list changes are locked for this election.';
    } else {
        $insertQuery = "INSERT INTO party_lists (name, description, election_id, created_at) VALUES ($1, $2, $3, NOW()) RETURNING id";
        $result = pg_query_params($conn, $insertQuery, [$name, $description, $election_id]);
        if ($result) {
            audit_log($conn, 'party_list_created', 'party_list', pg_fetch_result($result, 0, 'id'));
            $success = true;
        } else {
            $error = 'Unable to save party list. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Party List - Voting System</title>
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
           DEEP BLUE THEME (identical to dashboard)
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
        .panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 14px;
            overflow: hidden;
            max-width: 700px;
            margin: 0 auto;
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
        .panel-body {
            padding: 24px 28px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--label-color);
            margin-bottom: 6px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            background: var(--bg-input);
            border: 1px solid var(--input-border);
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            color: var(--text-primary);
            transition: 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(59,130,246,0.2);
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: rgba(59,130,246,0.2);
            border: 1px solid rgba(59,130,246,0.4);
            color: #1e3a8a;
        }
        [data-theme="dark"] .btn-primary {
            color: #93c5fd;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }
        .btn-secondary {
            background: rgba(100,116,139,0.2);
            border: 1px solid rgba(100,116,139,0.4);
            color: #1e293b;
        }
        [data-theme="dark"] .btn-secondary {
            color: #cbd5e1;
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error {
            background: rgba(239,68,68,0.1);
            border-left: 3px solid #ef4444;
            color: #b91c1c;
        }
        [data-theme="dark"] .alert-error {
            color: #f87171;
        }
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
            .panel-body { padding: 20px; }
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
                <div><h2>Voting System</h2><small>Admin Panel</small></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="../elections/index.php"><i class="fas fa-calendar-alt"></i> Elections</a>
            <a href="index.php" class="active"><i class="fas fa-list-ul"></i> Party Lists</a>
            <a href="../positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
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
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <h1><i class="fas fa-plus-circle" style="font-size:15px;opacity:0.6;margin-right:6px;"></i> Create Party List</h1>
            </div>
            <div class="topbar-right">
                <div class="topbar-user"><div class="avatar"><i class="fas fa-user-shield"></i></div><span>Welcome, <strong><?php echo h($_SESSION['admin_full_name']); ?></strong></span></div>
                <button class="topbar-btn" onclick="toggleTheme()"><i class="fas fa-moon" id="themeIcon"></i></button>
                <button class="topbar-btn" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>
        <div class="content-area">
            <div class="panel">
                <div class="panel-header">
                    <h3><i class="fas fa-plus-circle"></i> New Party List</h3>
                    <a href="index.php" class="btn btn-secondary" style="padding:6px 12px;"><i class="fas fa-arrow-left"></i> Back</a>
                </div>
                <div class="panel-body">
                    <?php if ($error && !$success): ?>
                        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" id="createForm">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label for="election_id">Select Election <span style="color:#ef4444;">*</span></label>
                            <select name="election_id" id="election_id" required>
                                <option value="">-- Select Election --</option>
                                <?php while ($el = pg_fetch_assoc($elections_result)): ?>
                                    <option value="<?= $el['id'] ?>" <?= ($election_id == $el['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($el['title']) ?> (<?= ucfirst($el['status']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="name">Party List Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required placeholder="e.g., Progressive Alliance">
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" placeholder="Optional description or platform"><?= htmlspecialchars($description) ?></textarea>
                        </div>
                        <div class="form-actions">
                            <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Party List</button>
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
    const icon = document.getElementById('themeIcon');
    if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
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
    }).then(result => { if (result.isConfirmed) window.location.href = '../logout.php'; });
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
document.addEventListener('DOMContentLoaded', updateThemeIcon);

<?php if ($success): ?>
Swal.fire({
    title: 'Success!',
    text: 'Party list created successfully!',
    icon: 'success',
    confirmButtonColor: '#3b82f6',
    background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#0f1e4a' : '#ffffff',
    color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b'
}).then(() => {
    window.location.href = 'index.php?created=1';
});
<?php endif; ?>
</script>
</body>
</html>