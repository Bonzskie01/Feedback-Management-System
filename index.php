<?php
// index.php
require __DIR__ . '/feedback/login.php';
exit;
require_once 'includes/session.php';
require_once 'includes/functions.php';
require_once 'config/database.php';

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    redirect('admin/dashboard.php');
}
if (isset($_SESSION['voter_id'])) {
    redirect('voter/ballot.php');
}

$error = '';
$swal  = '';

if (isset($_GET['access_wait']) && $_GET['access_wait'] == 1) {
    $swal = "info|Please Wait|Voting is currently open for another grade or section. Please log in again when your group is opened.|index.php";
}

$voter_election_ready = ensure_voters_election_id_column($conn) && ensure_election_access_columns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Please refresh the page and try again.';
    } else {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($identity === '' || $password === '') {
            $error = 'Please enter your ID/Username and Password.';
        } else {
            $rate = login_rate_limit_status($conn, $identity, 'password', 5, 900);
            if ($rate['limited']) {
                $minutes = max(1, (int) ceil($rate['retry_after'] / 60));
                $error = 'Too many failed login attempts. Please try again in ' . $minutes . ' minute(s).';
            } else {
                $res = pg_query_params($conn,
                    "SELECT id, username, password_hash, full_name, totp_enabled FROM admins WHERE username = $1",
                    [$identity]
                );

                if ($res && pg_num_rows($res) === 1) {
                    $admin = pg_fetch_assoc($res);
                    if (password_verify($password, $admin['password_hash'])) {
                        record_login_attempt($conn, $identity, 'password', true);
                        if ($admin['totp_enabled'] === 't' || $admin['totp_enabled'] === true) {
                            session_regenerate_id(true);
                            $_SESSION['pending_admin_id'] = $admin['id'];
                            $_SESSION['pending_admin_username'] = $admin['username'];
                            $_SESSION['pending_admin_full_name'] = $admin['full_name'];
                            $_SESSION['pending_expiry'] = time() + 300;
                            header('Location: verify_2fa.php');
                            exit;
                        }

                        session_regenerate_id(true);
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['admin_full_name'] = $admin['full_name'];
                        $_SESSION['auth_started_at'] = time();
                        $_SESSION['last_activity_at'] = time();
                        audit_log($conn, 'admin_login_success', 'admin', $admin['id']);
                        $swal = "success|Login Successful|Welcome back, {$admin['full_name']}!|admin/dashboard.php";
                    } else {
                        record_login_attempt($conn, $identity, 'password', false);
                        $error = 'Invalid credentials.';
                    }
                } else {
                    if (!$voter_election_ready) {
                        $error = 'Voter login is temporarily unavailable. Please contact the administrator.';
                    } else {
                        $res2 = pg_query_params($conn, "
                            SELECT v.*, e.id AS election_id, e.title AS election_title, e.status AS election_status,
                                   e.start_time, e.voting_access_mode, e.allowed_grade_section
                              FROM voters v
                              LEFT JOIN elections e ON e.id = v.election_id
                             WHERE v.voter_id = $1
                             LIMIT 1
                        ", [$identity]);

                        if ($res2 && pg_num_rows($res2) === 1) {
                            $voter = pg_fetch_assoc($res2);
                            if (!password_verify($password, $voter['password_hash'])) {
                                record_login_attempt($conn, $identity, 'password', false);
                                $error = 'Invalid credentials.';
                            } else {
                                record_login_attempt($conn, $identity, 'password', true);
                                if (empty($voter['election_id'])) {
                                    $swal = 'warning|No Election Assigned|This voter account is not assigned to an election. Please contact the administrator.|index.php';
                                } elseif ($voter['election_status'] !== 'ongoing') {
                                    $startText = !empty($voter['start_time'])
                                        ? ' It is scheduled to start on ' . date('M d, Y h:i A', strtotime($voter['start_time'])) . '.'
                                        : '';
                                    $swal = "warning|Election Not Open|Your account is for {$voter['election_title']}. You can log in only when that election is open.{$startText}|index.php";
                                } elseif (!election_access_allows_voter($voter, $voter['grade_section'] ?? '')) {
                                    $allowedText = election_access_label($voter);
                                    $swal = "warning|Please Wait|Voting is currently open for {$allowedText}. Please wait for your grade or section to be opened.|index.php";
                                } elseif ($voter['status'] === 'voted') {
                                    $swal = 'info|Already Voted|You have already cast your vote. Thank you!|voter/thank_you.php';
                                } else {
                                    session_regenerate_id(true);
                                    $_SESSION['voter_id'] = $voter['voter_id'];
                                    $_SESSION['voter_name'] = $voter['full_name'];
                                    $_SESSION['election_id'] = $voter['election_id'];
                                    $_SESSION['election_title'] = $voter['election_title'];
                                    $_SESSION['auth_started_at'] = time();
                                    $_SESSION['last_activity_at'] = time();
                                    $swal = "success|Login Successful|Welcome, {$voter['full_name']}! You may now cast your vote.|voter/ballot.php";
                                }
                            }
                        } else {
                            record_login_attempt($conn, $identity, 'password', false);
                            $error = 'Invalid credentials.';
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> | Login</title>
    <link rel="icon" type="image/png" href="<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            scroll-behavior: smooth;
            --brand-blue: #0f4c81;
            --brand-white: #f8f9fa;
            --accent-blue: #3b82f6;
            --text-muted: #e2e8f0;
            --glass-bg: rgba(30, 55, 90, 0.65);
            --shadow-black: 0 25px 50px rgba(0, 0, 0, 0.6);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        *::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            background: #000;
            color: white;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @keyframes bgMove {
            0%   { transform: scale(1); }
            100% { transform: scale(1.1); }
        }
        .split-layout {
            display: flex;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .bg-layer {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(rgba(0,0,0,0.4), rgba(8,52,106,0.5)),
                        url('<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 1;
            animation: fadeIn 2s ease, bgMove 25s infinite alternate ease-in-out;
        }
        .visual-side {
            flex: 1.2;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            padding: 50px 60px;
            z-index: 2;
        }
        .brand-floating {
            background: rgba(255,255,255,0.1);
            -webkit-backdrop-filter: blur(12px);
            backdrop-filter: blur(12px);
            padding: 20px 35px;
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: var(--shadow-black);
            animation: fadeInUp 1s ease-out 0.3s both;
        }
        .school-logo {
            height: 60px;
            filter: drop-shadow(0 5px 15px rgba(0,0,0,0.5));
        }
        .brand-text h1 {
            font-size: 1.4rem;
            font-weight: 800;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
        }
        .brand-text p {
            font-size: 0.85rem;
            color: rgba(255,255,255,0.6);
            margin-top: 4px;
        }
        .action-side {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 30px 40px 30px;
            z-index: 3;
            overflow-y: auto;
        }
        .portal-card {
            max-width: 440px;
            width: 100%;
            background: var(--glass-bg);
            -webkit-backdrop-filter: blur(25px) saturate(160%);
            backdrop-filter: blur(25px) saturate(160%);
            padding: 36px 36px;
            border-radius: 30px;
            border: 1px solid rgba(255,255,255,0.15);
            box-shadow: var(--shadow-black);
            animation: fadeInUp 0.8s cubic-bezier(0.22,1,0.36,1) 0.5s both;
        }
        .status-pill {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 800;
            margin-bottom: 14px;
        }
        .status-pill.online  { background: rgba(34,197,94,0.2);  color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
        .card-header h2  { font-size: 2rem; font-weight: 800; color: var(--brand-white); margin-bottom: 8px; }
        .card-header span { color: var(--accent-blue); }
        .card-header p   { color: var(--text-muted); line-height: 1.6; margin-bottom: 20px; font-size: 0.9rem; }
        .feature-grid {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .feat-box {
            background: rgba(255,255,255,0.08);
            padding: 6px 12px;
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.1);
            font-size: 0.73rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .input-wrap { position: relative; }
        .input-wrap i.icon-left {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.35);
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 44px 12px 40px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            color: white;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.3s, background 0.3s;
        }
        .form-group input::placeholder { color: rgba(255,255,255,0.3); }
        .form-group input:focus {
            border-color: var(--accent-blue);
            background: rgba(255,255,255,0.18);
        }
        .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(255,255,255,0.35);
            cursor: pointer;
            font-size: 14px;
        }
        .toggle-pw:hover { color: rgba(255,255,255,0.8); }
        .alert-error {
            background: rgba(198,40,40,0.2);
            border: 1px solid rgba(239,83,80,0.35);
            border-radius: 12px;
            padding: 12px 15px;
            font-size: 0.85rem;
            color: #ef9a9a;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--brand-blue);
            color: white;
            text-decoration: none;
            padding: 14px;
            border-radius: 15px;
            text-align: center;
            font-weight: 700;
            font-size: 1rem;
            transition: 0.3s;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            display: block;
            width: 100%;
            border: none;
            cursor: pointer;
        }
        .btn-primary:hover {
            background: var(--accent-blue);
            transform: translateY(-3px);
        }
        .role-hint {
            text-align: center;
            margin-top: 18px;
            font-size: 0.78rem;
            color: rgba(255,255,255,0.35);
        }
        .portal-footer {
            margin-top: 20px;
            text-align: center;
            border-top: 1px solid rgba(255,255,255,0.1);
            padding-top: 16px;
            font-size: 0.78rem;
            opacity: 0.7;
        }
        @media (max-width: 992px) {
            .split-layout { flex-direction: column; overflow-y: auto; }
            .visual-side  { flex: none; width: 100%; padding: 40px 20px; justify-content: center; }
            .action-side  { flex: none; width: 100%; padding: 20px; }
            .brand-floating { width: 100%; max-width: 500px; }
        }
        @media (max-width: 480px) {
            .portal-card  { padding: 30px 20px; }
            .card-header h2 { font-size: 1.8rem; }
            .brand-text h1  { font-size: 1.1rem; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

<div class="split-layout">
    <div class="bg-layer"></div>

    <!-- LEFT: Branding (dynamic school info) -->
    <section class="visual-side">
        <div class="brand-floating">
            <img src="<?php echo site_logo(); ?>" alt="School Logo" class="school-logo">
            <div class="brand-text">
                <h1><?php echo school_name(); ?></h1>
                <p><?php echo school_address(); ?></p>
            </div>
        </div>
    </section>

    <!-- RIGHT: Login Card (unchanged) -->
    <section class="action-side">
        <div class="portal-card">
            <div class="status-pill online">&#x25CF; System Online</div>
            <header class="card-header">
                <h2>Welcome to the<br><span>Voting System</span></h2>
                <p>Sign in using your Voter's ID to access the platform.</p>
            </header>
            <div class="feature-grid">
                <div class="feat-box"><span>🗳️</span> Secure Voting</div>
                <div class="feat-box"><span>📊</span> Live Results</div>
                <div class="feat-box"><span>🔐</span> Role-Based</div>
                <div class="feat-box"><span>⚡</span> Real-Time</div>
            </div>
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Voter ID</label>
                    <div class="input-wrap">
                        <i class="fas fa-user icon-left"></i>
                        <input type="text" name="identity" placeholder="Enter Voter ID" required autocomplete="off">
                    </div>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrap">
                        <i class="fas fa-lock icon-left"></i>
                        <input type="password" name="password" id="passwordField" placeholder="Enter your password" required>
                        <button type="button" class="toggle-pw" onclick="togglePassword()">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
            <div class="role-hint">
                <span>🔐 Admin</span> &bull; <span>🗳️ Voter</span>
            </div>
            <footer class="portal-footer">
                <p>© <?php echo date('Y'); ?> <?php echo site_name(); ?> Information Systems. All rights reserved.</p>
            </footer>
        </div>
    </section>
</div>

<script>
function togglePassword() {
    const field = document.getElementById('passwordField');
    const icon  = document.getElementById('eyeIcon');
    field.type  = field.type === 'password' ? 'text' : 'password';
    icon.className = field.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
<?php if ($swal): ?>
<?php
    $parts = array_pad(explode('|', $swal, 4), 4, '');
    $allowedRedirects = ['index.php', 'admin/dashboard.php', 'voter/ballot.php', 'voter/thank_you.php'];
    if (!in_array($parts[3], $allowedRedirects, true)) {
        $parts[3] = 'index.php';
    }
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
Swal.fire({
    icon: <?php echo json_encode($parts[0], $jsonFlags); ?>,
    title: <?php echo json_encode($parts[1], $jsonFlags); ?>,
    text: <?php echo json_encode($parts[2], $jsonFlags); ?>,
    confirmButtonColor: '#0f4c81',
    confirmButtonText: 'Continue'
}).then(() => {
    window.location.href = <?php echo json_encode($parts[3], $jsonFlags); ?>;
});
<?php elseif ($error): ?>
Swal.fire({
    icon: 'error',
    title: 'Login Failed',
    text: <?php echo json_encode($error, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    confirmButtonColor: '#0f4c81',
    confirmButtonText: 'Try Again'
});
<?php endif; ?>
</script>
</body>
</html>
