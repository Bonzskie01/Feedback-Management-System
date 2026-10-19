<?php
// voter/ballot.php – Dashboard‑style UI
$root_path = dirname(__FILE__) . '/..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!isset($_SESSION['voter_id'])) {
    redirect('../index.php');
}

if (!isset($_SESSION['election_id']) || !ensure_voters_election_id_column($conn) || !ensure_election_access_columns($conn)) {
    session_unset();
    session_destroy();
    redirect('../index.php');
}

// Check if voter is still active
$voter_check = pg_query_params($conn, "SELECT status, grade_section FROM voters WHERE voter_id = $1 AND election_id = $2", [$_SESSION['voter_id'], $_SESSION['election_id']]);
if (!$voter_check || pg_num_rows($voter_check) === 0) {
    session_unset();
    session_destroy();
    redirect('thank_you.php');
}
$voter = pg_fetch_assoc($voter_check);
if ($voter['status'] !== 'active') {
    session_unset();
    session_destroy();
    redirect('thank_you.php');
}

// Get the election attached to this voter session.
$election_result = pg_query_params($conn, "SELECT * FROM elections WHERE id = $1 AND status IN ('ongoing', 'paused')", [$_SESSION['election_id']]);
if (!$election_result || pg_num_rows($election_result) === 0) {
    session_unset();
    session_destroy();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><title>No Active Election</title><?php include_theme_styles(); ?>    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
    <body><div class="empty-state" style="max-width:500px;margin:100px auto;"><i class="fas fa-ban"></i><h2>No Active Election</h2><p>There is no ongoing election at the moment.</p><a href="../index.php" class="btn-primary" style="display:inline-block;margin-top:15px;">Go Home</a></div></body>
    </html>
    <?php
    exit;
}

$election = pg_fetch_assoc($election_result);
$is_paused = ($election['status'] === 'paused');

if (!election_access_allows_voter($election, $voter['grade_section'] ?? '')) {
    session_unset();
    session_destroy();
    redirect('../index.php?access_wait=1');
}

// Get positions and candidates
$positions_result = pg_query_params($conn,
    "SELECT * FROM positions WHERE election_id = $1 ORDER BY display_order ASC",
    [$election['id']]);

$page_title = 'Cast Your Vote – ' . h($election['title']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ========================================
           DEEP BLUE THEME (exactly like dashboard)
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
        }
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('../<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
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

        /* Voter container (no sidebar) */
        .voter-wrapper {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        /* Top Bar (voter info) – similar to admin topbar but simpler */
        .voter-topbar {
            background: var(--topbar-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 12px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .voter-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .voter-info .badge {
            background: var(--accent-primary);
            color: white;
            padding: 6px 14px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
        }
        .theme-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 40px;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
        }
        .logout-link {
            color: #ef4444;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Banner (election title) */
        .election-banner {
            background: linear-gradient(135deg, #1a237e, #283593);
            color: white;
            padding: 28px 30px;
            border-radius: 20px;
            margin-bottom: 24px;
            text-align: center;
        }
        .election-banner h1 { font-size: 26px; margin-bottom: 8px; }
        .election-banner p { opacity: 0.9; font-size: 14px; }

        /* Paused alert */
        .paused-alert {
            display: none;
            background: #fff3e0;
            border-left: 4px solid #e65100;
            color: #e65100;
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            align-items: center;
            gap: 12px;
        }
        [data-theme="dark"] .paused-alert {
            background: #3e2000;
            color: #ffb74d;
        }
        .paused-alert.show { display: flex; }

        /* Stats row (like dashboard) */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 14px;
            padding: 16px;
            text-align: center;
        }
        .stat-number {
            font-size: 28px;
            font-weight: 800;
            color: var(--accent-primary);
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        /* Position card – same style as dashboard panels */
        .position-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 28px;
        }
        .position-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--accent-primary);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .position-desc {
            font-size: 13px;
            color: var(--text-muted);
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        .candidates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 18px;
            margin-bottom: 20px;
        }
        .candidate-card {
            background: var(--bg-panel);
            border: 2px solid var(--border-color);
            border-radius: 16px;
            padding: 18px 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .candidate-card:hover {
            border-color: var(--accent-primary);
            transform: translateY(-3px);
        }
        .candidate-card.selected {
            border-color: #2e7d32;
            background: #e8f5e9;
        }
        [data-theme="dark"] .candidate-card.selected {
            background: #1e3a2f;
            border-color: #4caf50;
        }
        .candidate-card.selected::after {
            content: "✓";
            position: absolute;
            top: 10px;
            right: 14px;
            font-size: 24px;
            color: #2e7d32;
            font-weight: bold;
        }
        .candidate-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #e2e8f0;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: #94a3b8;
            overflow: hidden;
        }
        [data-theme="dark"] .candidate-photo { background: #334155; }
        .candidate-photo img { width: 100%; height: 100%; object-fit: cover; }
        .candidate-name { font-weight: 700; font-size: 15px; margin-bottom: 4px; color: var(--text-primary); }
        .candidate-partylist {
            min-height: 18px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .candidate-partylist i { color: var(--accent); margin-right: 4px; }
        .abstain-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: 2px solid var(--border-color);
            border-radius: 40px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
            color: var(--text-secondary);
            background: var(--bg-card);
        }
        .abstain-btn.selected {
            border-color: #e65100;
            background: #fff3e0;
            color: #e65100;
        }
        [data-theme="dark"] .abstain-btn.selected {
            background: #3e2000;
            color: #ffb74d;
        }
        .submit-section {
            text-align: center;
            margin: 30px 0 20px;
        }
        .btn-submit {
            background: linear-gradient(135deg, #2e7d32, #43a047);
            color: white;
            border: none;
            padding: 14px 40px;
            font-size: 18px;
            font-weight: 700;
            border-radius: 50px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(46,125,50,0.4);
        }
        .btn-submit:disabled {
            background: #aaa;
            cursor: not-allowed;
        }
        .footer-note {
            text-align: center;
            margin-top: 40px;
            padding: 16px;
            color: var(--text-muted);
            font-size: 12px;
        }
        @media (max-width: 640px) {
            .candidates-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .container { padding: 0 12px; }
            .voter-topbar { flex-direction: column; align-items: stretch; }
        }
    </style>
    <script>
        (function() {
            if (localStorage.getItem('vms-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
        function toggleTheme() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('vms-theme', 'light');
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('vms-theme', 'dark');
            }
        }
        function confirmLogout(event) {
            event.preventDefault();
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to end your voting session?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Cancel',
                background: isDark ? '#0f1e4a' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#1e293b'
            }).then(result => {
                if (result.isConfirmed) window.location.href = '../index.php';
            });
        }
    </script>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="voter-wrapper">
    <div class="container">
        <!-- Voter Top Bar -->
        <div class="voter-topbar">
            <div class="voter-info">
                <span class="badge"><i class="fas fa-user-check"></i> <?php echo h($_SESSION['voter_name']); ?></span>
                <span style="color:var(--text-secondary);"><i class="fas fa-id-card"></i> <?php echo h($_SESSION['voter_id']); ?></span>
                <span style="color:var(--text-muted);"><i class="fas fa-clock"></i> Ends: <?php echo date('M d, Y h:i A', strtotime($election['end_time'])); ?></span>
            </div>
            <div style="display:flex; gap:12px; align-items:center;">
                <button class="theme-btn" onclick="toggleTheme()"><i class="fas fa-moon"></i> Dark/Light</button>
                <a href="#" onclick="confirmLogout(event)" class="logout-link"><i class="fas fa-sign-out-alt"></i> Exit</a>
            </div>
        </div>

        <!-- Election Banner -->
        <div class="election-banner">
            <h1><i class="fas fa-vote-yea"></i> <?php echo h($election['title']); ?></h1>
            <p>Cast your vote wisely – you can only vote once.</p>
        </div>

        <!-- Paused Alert -->
        <div class="paused-alert" id="pausedAlert">
            <i class="fas fa-pause-circle"></i> The election is currently paused. Please wait for it to resume.
        </div>

        <!-- Stats (voter turnout etc.) – quick overview -->
        <div class="stats-row">
            <?php
            $total_positions = pg_num_rows($positions_result);
            $total_candidates = 0;
            pg_result_seek($positions_result, 0);
            while ($pos = pg_fetch_assoc($positions_result)) {
                $cand_q = pg_query_params($conn, "SELECT COUNT(*) FROM candidates WHERE position_id=$1 AND is_approved=true AND is_disqualified=false", [$pos['id']]);
                $total_candidates += pg_fetch_result($cand_q,0,0);
            }
            pg_result_seek($positions_result, 0);
            ?>
            <div class="stat-card"><div class="stat-number"><?php echo $total_positions; ?></div><div class="stat-label">Positions to vote</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $total_candidates; ?></div><div class="stat-label">Candidates total</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $election['status'] === 'ongoing' ? '● LIVE' : '⏸ PAUSED'; ?></div><div class="stat-label">Election status</div></div>
        </div>

        <!-- Voting Form -->
        <form method="POST" action="submit_vote.php" id="ballotForm">
            <?php echo csrf_field(); ?>
            <?php while ($position = pg_fetch_assoc($positions_result)):
                $candidates_q = pg_query_params($conn,
                    "SELECT c.*, pl.name AS party_list_name
                     FROM candidates c
                     LEFT JOIN party_lists pl
                       ON pl.id = c.party_list_id AND pl.election_id = $2
                     WHERE c.position_id = $1
                       AND c.is_approved = true
                       AND c.is_disqualified = false
                     ORDER BY c.full_name",
                    [$position['id'], $election['id']]);
                $candidates = [];
                while ($c = pg_fetch_assoc($candidates_q)) $candidates[] = $c;
                $maxWinners = $position['max_winners'];
            ?>
            <div class="position-card" data-pos-id="<?php echo $position['id']; ?>" data-max-winners="<?php echo $maxWinners; ?>">
                <div class="position-title">
                    <i class="fas fa-trophy"></i> <?php echo h($position['name']); ?>
                </div>
                <div class="position-desc">
                    <?php if ($maxWinners > 1): ?>
                        Select up to <strong><?php echo $maxWinners; ?></strong> candidates
                    <?php else: ?>
                        Select <strong>one</strong> candidate
                    <?php endif; ?>
                </div>
                <div class="candidates-grid">
                    <?php foreach ($candidates as $cand): ?>
                    <div class="candidate-card <?php echo $is_paused ? 'disabled' : ''; ?>" data-candidate-id="<?php echo $cand['id']; ?>">
                        <div class="candidate-photo">
                            <?php if (!empty($cand['photo_path']) && file_exists('../' . $cand['photo_path'])): ?>
                                <img src="../<?php echo h($cand['photo_path']); ?>" alt="<?php echo h($cand['full_name']); ?>">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="candidate-name"><?php echo h($cand['full_name']); ?></div>
                        <div class="candidate-partylist">
                            <i class="fas fa-flag" aria-hidden="true"></i>
                            <?php echo h($cand['party_list_name'] ?: 'Independent'); ?>
                        </div>
                        <input type="<?php echo $maxWinners > 1 ? 'checkbox' : 'radio'; ?>"
                               name="votes[<?php echo $position['id']; ?>][]"
                               value="<?php echo $cand['id']; ?>"
                               class="vote-input"
                               style="display: none;"
                               <?php echo $is_paused ? 'disabled' : ''; ?>>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="abstain-option">
                    <div class="abstain-btn <?php echo $is_paused ? 'disabled' : ''; ?>" data-pos-id="<?php echo $position['id']; ?>">
                        <i class="fas fa-minus-circle"></i> Abstain from this position
                    </div>
                    <input type="hidden"
                           name="abstain[<?php echo $position['id']; ?>]"
                           value="0"
                           class="abstain-input">
                </div>
            </div>
            <?php endwhile; ?>

            <div class="submit-section">
                <button type="submit" class="btn-submit" id="submitBtn" <?php echo $is_paused ? 'disabled' : ''; ?>>
                    <i class="fas fa-check-circle"></i> Submit My Vote
                </button>
                <p style="margin-top: 12px; font-size: 12px; color: var(--text-muted);">
                    <i class="fas fa-lock"></i> Your vote is secret and irreversible.
                </p>
            </div>
        </form>

        <div class="footer-note">
            Voting System &copy; <?php echo date('Y'); ?> – <?php echo site_name(); ?>
        </div>
    </div>
</div>

<script>
    // Candidate selection
    document.querySelectorAll('.candidate-card').forEach(card => {
        if (card.classList.contains('disabled')) return;
        card.addEventListener('click', function(e) {
            const positionCard = this.closest('.position-card');
            const maxWinners = parseInt(positionCard.dataset.maxWinners);
            const input = this.querySelector('.vote-input');
            const isSelected = this.classList.contains('selected');
            const abstainBtn = positionCard.querySelector('.abstain-btn');

            if (maxWinners === 1) {
                // Single select
                positionCard.querySelectorAll('.candidate-card').forEach(c => {
                    c.classList.remove('selected');
                    c.querySelector('.vote-input').checked = false;
                });
                if (!isSelected) {
                    this.classList.add('selected');
                    input.checked = true;
                }
                if (abstainBtn) abstainBtn.classList.remove('selected');
                const abstainInput = positionCard.querySelector('.abstain-input');
                if (abstainInput) abstainInput.value = '0';
            } else {
                // Multi select
                const selectedCount = positionCard.querySelectorAll('.candidate-card.selected').length;
                if (!isSelected && selectedCount >= maxWinners) {
                    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    Swal.fire({ icon: 'warning', title: 'Limit reached', text: `You can select up to ${maxWinners} candidate(s) for this position.`, confirmButtonColor: '#1a237e', background: isDark ? '#0f1e4a' : '#ffffff', color: isDark ? '#f1f5f9' : '#1e293b' });
                    return;
                }
                this.classList.toggle('selected');
                input.checked = !isSelected;
                if (positionCard.querySelectorAll('.candidate-card.selected').length > 0) {
                    if (abstainBtn) abstainBtn.classList.remove('selected');
                    const abstainInput = positionCard.querySelector('.abstain-input');
                    if (abstainInput) abstainInput.value = '0';
                }
            }
        });
    });

    // Abstain
    document.querySelectorAll('.abstain-btn').forEach(btn => {
        if (btn.classList.contains('disabled')) return;
        btn.addEventListener('click', function(e) {
            const positionCard = this.closest('.position-card');
            const candidates = positionCard.querySelectorAll('.candidate-card');
            const isSelected = this.classList.contains('selected');
            const abstainInput = positionCard.querySelector('.abstain-input');
            if (!isSelected) {
                candidates.forEach(c => {
                    c.classList.remove('selected');
                    c.querySelector('.vote-input').checked = false;
                });
                this.classList.add('selected');
                if (abstainInput) abstainInput.value = '1';
            } else {
                this.classList.remove('selected');
                if (abstainInput) abstainInput.value = '0';
            }
        });
    });

    // Confirm before submit
    document.getElementById('ballotForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        Swal.fire({
            title: 'Submit Your Vote?',
            text: 'This action cannot be undone. You will not be able to vote again.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#2e7d32',
            cancelButtonColor: '#c62828',
            confirmButtonText: 'Yes, submit my vote',
            cancelButtonText: 'Cancel',
            background: isDark ? '#0f1e4a' : '#ffffff',
            color: isDark ? '#f1f5f9' : '#1e293b'
        }).then(result => {
            if (result.isConfirmed) {
                document.getElementById('ballotForm').submit();
            }
        });
    });

    // Election status polling
    let checkInterval, isEnding = false;
    function checkElectionStatus() {
        fetch('check_election_status.php')
            .then(res => res.json())
            .then(data => {
                if (!data.active && data.redirect && !isEnding) {
                    clearInterval(checkInterval);
                    isEnding = true;
                    const ended = data.status === 'closed';
                    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    Swal.fire({ title: ended ? 'Election Has Ended' : 'Please Wait', text: data.message, icon: 'info', timer: 4000, showConfirmButton: true, background: isDark ? '#0f1e4a' : '#ffffff', color: isDark ? '#f1f5f9' : '#1e293b' }).then(() => {
                        window.location.href = ended ? 'thank_you.php?status=auto_closed' : '../index.php?access_wait=1';
                    });
                } else if (data.status === 'paused') {
                    document.getElementById('pausedAlert').classList.add('show');
                    document.getElementById('submitBtn').disabled = true;
                    document.querySelectorAll('.candidate-card').forEach(c => c.classList.add('disabled'));
                    document.querySelectorAll('.abstain-btn').forEach(b => b.classList.add('disabled'));
                } else if (data.status === 'ongoing') {
                    document.getElementById('pausedAlert').classList.remove('show');
                    document.getElementById('submitBtn').disabled = false;
                    document.querySelectorAll('.candidate-card').forEach(c => c.classList.remove('disabled'));
                    document.querySelectorAll('.abstain-btn').forEach(b => b.classList.remove('disabled'));
                }
            })
            .catch(() => console.log('Status check failed'));
    }
    checkInterval = setInterval(checkElectionStatus, 10000);
    checkElectionStatus();
</script>
</body>
</html>