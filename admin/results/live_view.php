<?php
// admin/results/live_view.php
$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('./index.php');
}

$election_id = isset($_GET['election_id']) ? intval($_GET['election_id']) : 0;
$selected_position_id = isset($_GET['position_id']) ? intval($_GET['position_id']) : 0;
$masked_view = isset($_GET['mask']) && $_GET['mask'] == '1';
$order_seed = strtolower(trim($_GET['order_seed'] ?? ''));
if (!preg_match('/^[a-f0-9]{16}$/' , $order_seed)) {
    $order_seed = bin2hex(random_bytes(8));
}

if ($election_id == 0) {
    die("No election selected.");
}

// Fetch election details
$election_result = pg_query_params($conn, "SELECT * FROM elections WHERE id = $1", [$election_id]);
if (pg_num_rows($election_result) == 0) {
    die("Election not found.");
}
$election = pg_fetch_assoc($election_result);

// Get all positions for this election
$positions_list = pg_query_params($conn, "SELECT * FROM positions WHERE election_id = $1 ORDER BY display_order ASC", [$election_id]);
$all_positions = [];
while ($p = pg_fetch_assoc($positions_list)) {
    $all_positions[] = $p;
}

// Statistics
$total_voters = (int) pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM voters"), 0, 0);
$voted_result = pg_query_params($conn, "SELECT COUNT(DISTINCT vt.voter_id) FROM voter_turnout vt WHERE vt.election_id = $1", [$election_id]);
$total_voted = $voted_result ? (int) pg_fetch_result($voted_result, 0, 0) : 0;
$turnout_percentage = ($total_voters > 0) ? round(($total_voted / $total_voters) * 100, 1) : 0;

function prepare_live_view_candidates(array $candidates, string $order_seed, int $position_id, int $max_winners): array {
    foreach ($candidates as $index => &$candidate) {
        $candidate['_result_rank'] = $index + 1;
        $candidate['_is_winner'] = $index < $max_winners && (int) $candidate['vote_count'] > 0;
        $candidate['_display_key'] = hash('sha256', $order_seed . ':' . $position_id . ':' . $candidate['id']);
    }
    unset($candidate);

    $winners = array_values(array_filter($candidates, static function ($candidate) {
        return $candidate['_is_winner'];
    }));

    usort($candidates, static function ($left, $right) {
        return strcmp($left['_display_key'], $right['_display_key']);
    });

    foreach ($candidates as &$candidate) {
        unset($candidate['_display_key']);
    }
    unset($candidate);

    return [$candidates, $winners];
}

// Prepare data for all positions or a single position
$positions_data = [];

if ($selected_position_id == 0) {
    // "All Positions" mode: gather data for each position
    foreach ($all_positions as $pos) {
        $candidates_result = pg_query_params($conn,
            "SELECT c.*, 
                COALESCE((SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id AND v.position_id = c.position_id AND v.election_id = $1), 0) as vote_count
             FROM candidates c 
             WHERE c.position_id = $2 AND c.is_approved = true AND c.is_disqualified = false
             ORDER BY vote_count DESC, c.full_name ASC",
            [$election_id, $pos['id']]
        );
        $candidates = [];
        $total_votes_for_pos = 0;
        while ($cand = pg_fetch_assoc($candidates_result)) {
            $candidates[] = $cand;
            $total_votes_for_pos += $cand['vote_count'];
        }
        $max_votes = count($candidates) > 0 ? $candidates[0]['vote_count'] : 0;
        [$candidates, $winners] = prepare_live_view_candidates(
            $candidates,
            $order_seed,
            (int) $pos['id'],
            (int) $pos['max_winners']
        );
        $abstained = max(0, $total_voted - $total_votes_for_pos);
        $abstention_pct = $total_voted > 0 ? round(($abstained / $total_voted) * 100, 1) : 0;
        
        $positions_data[] = [
            'info' => $pos,
            'candidates' => $candidates,
            'winners' => $winners,
            'total_votes' => $total_votes_for_pos,
            'max_votes' => $max_votes,
            'abstained' => $abstained,
            'abstention_pct' => $abstention_pct
        ];
    }
} else {
    // Single position mode
    $single_pos = null;
    foreach ($all_positions as $p) {
        if ($p['id'] == $selected_position_id) {
            $single_pos = $p;
            break;
        }
    }
    if ($single_pos) {
        $candidates_result = pg_query_params($conn,
            "SELECT c.*, 
                COALESCE((SELECT COUNT(*) FROM votes v WHERE v.candidate_id = c.id AND v.position_id = c.position_id AND v.election_id = $1), 0) as vote_count
             FROM candidates c 
             WHERE c.position_id = $2 AND c.is_approved = true AND c.is_disqualified = false
             ORDER BY vote_count DESC, c.full_name ASC",
            [$election_id, $selected_position_id]
        );
        $candidates = [];
        $total_votes_for_pos = 0;
        while ($cand = pg_fetch_assoc($candidates_result)) {
            $candidates[] = $cand;
            $total_votes_for_pos += $cand['vote_count'];
        }
        $max_votes = count($candidates) > 0 ? $candidates[0]['vote_count'] : 0;
        [$candidates, $winners] = prepare_live_view_candidates(
            $candidates,
            $order_seed,
            (int) $single_pos['id'],
            (int) $single_pos['max_winners']
        );
        $abstained = max(0, $total_voted - $total_votes_for_pos);
        $abstention_pct = $total_voted > 0 ? round(($abstained / $total_voted) * 100, 1) : 0;
        
        $positions_data[] = [
            'info' => $single_pos,
            'candidates' => $candidates,
            'winners' => $winners,
            'total_votes' => $total_votes_for_pos,
            'max_votes' => $max_votes,
            'abstained' => $abstained,
            'abstention_pct' => $abstention_pct
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $masked_view ? 'Masked Live View' : 'Live View'; ?> - <?php echo h($election['title']); ?></title>
    <link rel="icon" type="image/png" href="../../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        (function() {
            if (localStorage.getItem('vms-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
        const liveViewUrl = new URL(window.location.href);
        liveViewUrl.searchParams.set('order_seed', <?php echo json_encode($order_seed); ?>);
        window.history.replaceState(null, '', liveViewUrl.toString());

        // Auto refresh every 15 seconds while preserving the display order seed.
        setTimeout(function() { location.reload(); }, 15000);
    </script>
    <style>
        /* ========================================
           SAME DEEP BLUE THEME AS ADMIN PAGES
        ======================================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
            position: relative;
        }
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
        .container {
            position: relative;
            z-index: 1;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        /* Header */
        .header {
            background: linear-gradient(135deg, #1a237e, #283593);
            color: white;
            padding: 20px 25px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
        }
        .header h2 { font-size: 22px; display: flex; align-items: center; gap: 10px; }
        .header p { opacity: 0.9; font-size: 13px; margin-top: 8px; }
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        .stat-card .value { font-size: 32px; font-weight: 700; color: var(--accent-primary); }
        .stat-card .label { font-size: 11px; text-transform: uppercase; color: var(--text-muted); margin-top: 5px; letter-spacing: 0.8px; }
        /* Filters Toolbar */
        .filters {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            border: 1px solid var(--border-color);
        }
        .filters label { font-weight: 600; }
        .filter-select {
            padding: 8px 32px 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--input-border);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 13px;
            cursor: pointer;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            background: #1a237e;
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn:hover { background: #283593; transform: translateY(-1px); }
        .live-indicator {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        .live-dot {
            width: 10px;
            height: 10px;
            background: #4caf50;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.5; transform: scale(0.8); } 100% { opacity: 1; transform: scale(1); } }
        /* Position Card */
        .position-card {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }
        .position-title {
            padding: 18px 22px;
            background: var(--bg-panel);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .winner-badge {
            background: #fff3e0;
            color: #e65100;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        [data-theme="dark"] .winner-badge { background: #3e2000; color: #ffb74d; }
        .winners-preview {
            background: var(--bg-panel);
            padding: 10px 22px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
        .winner-chip {
            background: var(--bg-card);
            border-radius: 30px;
            padding: 4px 14px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .candidate-row {
            display: flex;
            align-items: center;
            padding: 14px 22px;
            border-bottom: 1px solid var(--border-color);
            gap: 15px;
            flex-wrap: wrap;
        }
        .candidate-rank { font-weight: 700; min-width: 35px; font-size: 18px; text-align: center; }
        .candidate-rank.winner { color: #f9a825; }
        .candidate-photo {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .candidate-photo img { width: 100%; height: 100%; object-fit: cover; }
        .anonymous-avatar { color: #1a237e; background: rgba(26,35,126,0.12); }
        [data-theme="dark"] .anonymous-avatar { color: #93c5fd; background: rgba(96,165,250,0.14); }
        .candidate-info { flex: 1; }
        .candidate-name { font-weight: 600; font-size: 15px; }
        .candidate-partylist { font-size: 11px; color: var(--text-muted); }
        .progress-wrapper { width: 120px; height: 6px; background: var(--bg-input); border-radius: 10px; overflow: hidden; margin-top: 6px; }
        .progress-fill { height: 100%; border-radius: 10px; transition: width 0.3s; }
        .progress-fill.gold { background: linear-gradient(90deg, #f9a825, #ffd54f); }
        .progress-fill.blue { background: #1a237e; }
        .progress-fill.green { background: #2e7d32; }
        .candidate-votes { text-align: right; min-width: 100px; }
        .vote-count { font-size: 20px; font-weight: 700; color: var(--accent-primary); }
        .vote-label { font-size: 10px; color: var(--text-muted); }
        .abstain-row { background: var(--bg-panel); border-top: 1px solid var(--border-color); }
        .no-data { text-align: center; padding: 30px; color: var(--text-muted); }
        .theme-toggle-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        .theme-toggle-btn:hover { color: var(--accent); border-color: var(--accent); }
        .footer-note { text-align: center; margin-top: 30px; font-size: 12px; color: var(--text-muted); padding: 15px; }
        @media (max-width: 640px) {
            .container { padding: 12px; }
            .candidate-row { flex-direction: column; align-items: flex-start; }
            .candidate-votes { text-align: left; margin-left: 50px; }
            .filters { flex-direction: column; align-items: stretch; }
            .live-indicator { margin-left: 0; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="bg-layer"></div>
<div class="container">
    <!-- Top bar with theme toggle -->
    <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
        <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeToggleBtn">
            <i class="fas fa-moon"></i> Theme
        </button>
    </div>

    <div class="header">
        <h2><i class="fas fa-chart-line"></i> <?php echo $masked_view ? 'Masked Live Results' : 'Live Results'; ?>: <?php echo h($election['title']); ?></h2>
        <p>
            Status: <strong><?php echo ucfirst($election['status']); ?></strong> &nbsp;|&nbsp;
            <?php echo date('M d, Y h:i A', strtotime($election['start_time'])); ?> — 
            <?php echo date('M d, Y h:i A', strtotime($election['end_time'])); ?>
            <?php if ($masked_view): ?> &nbsp;|&nbsp; Candidate names and photos hidden <?php endif; ?>
        </p>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="value"><?php echo number_format($total_voters); ?></div><div class="label">Registered Voters</div></div>
        <div class="stat-card"><div class="value"><?php echo number_format($total_voted); ?></div><div class="label">Votes Cast</div></div>
        <div class="stat-card"><div class="value"><?php echo $turnout_percentage; ?>%</div><div class="label">Turnout</div></div>
    </div>

    <div class="filters">
        <i class="fas fa-filter"></i>
        <label>Position:</label>
        <select id="positionFilter" class="filter-select" onchange="location.href='?election_id=<?php echo $election_id; ?>&mask=<?php echo $masked_view ? 1 : 0; ?>&order_seed=<?php echo h($order_seed); ?>&position_id='+encodeURIComponent(this.value)">
            <option value="0" <?php echo $selected_position_id == 0 ? 'selected' : ''; ?>>-- All Positions --</option>
            <?php foreach ($all_positions as $pos): ?>
                <option value="<?php echo $pos['id']; ?>" <?php echo $selected_position_id == $pos['id'] ? 'selected' : ''; ?>>
                    <?php echo h($pos['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="live-indicator">
            <span class="live-dot"></span> Auto-refresh (15s)
        </div>
    </div>

    <?php if (count($positions_data) > 0): ?>
        <?php foreach ($positions_data as $pdata): 
            $pos = $pdata['info'];
            $candidates = $pdata['candidates'];
            $total_votes_for_pos = $pdata['total_votes'];
            $max_votes = $pdata['max_votes'];
            $abstained = $pdata['abstained'];
            $abstention_pct = $pdata['abstention_pct'];
            $winners = $pdata['winners'];
            $has_winner = count($winners) > 0 && $winners[0]['vote_count'] > 0;
        ?>
            <div class="position-card">
                <div class="position-title">
                    <h3><i class="fas fa-trophy" style="color: #f9a825;"></i> <?php echo h($pos['name']); ?></h3>
                    <span class="winner-badge"><?php echo $pos['max_winners']; ?> Winner(s)</span>
                </div>
                
                <?php if ($has_winner): ?>
                    <div class="winners-preview">
                        <strong><i class="fas fa-crown"></i> Current Winner<?php echo count($winners) > 1 ? 's' : ''; ?>:</strong>
                        <?php foreach ($winners as $winner_idx => $w): ?>
                            <span class="winner-chip">
                                <?php if ($masked_view): ?>
                                    <i class="fas fa-user-secret"></i>
                                    Candidate <?php echo $w['_result_rank']; ?> (<?php echo number_format($w['vote_count']); ?>)
                                <?php else: ?>
                                    <?php if ($w['photo_path'] && file_exists('../../' . $w['photo_path'])): ?>
                                        <img src="../../<?php echo h($w['photo_path']); ?>" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-user"></i>
                                    <?php endif; ?>
                                    <?php echo h($w['full_name']); ?> (<?php echo number_format($w['vote_count']); ?>)
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (count($candidates) > 0): ?>
                    <?php foreach ($candidates as $idx => $cand):
                        $percentage = $total_votes_for_pos > 0 ? round(($cand['vote_count'] / $total_votes_for_pos) * 100, 1) : 0;
                        $bar_width = $max_votes > 0 ? round(($cand['vote_count'] / $max_votes) * 100, 1) : 0;
                        $is_winner = $cand['_is_winner'];
                        $bar_class = $is_winner ? 'gold' : (($idx % 2 == 0) ? 'blue' : 'green');
                    ?>
                        <div class="candidate-row">
                            <div class="candidate-rank <?php echo $is_winner ? 'winner' : ''; ?>">
                                <?php if ($is_winner): ?><i class="fas fa-trophy"></i><?php else: ?><?php echo $cand['_result_rank']; ?><?php endif; ?>
                            </div>
                            <div class="candidate-photo <?php echo $masked_view ? 'anonymous-avatar' : ''; ?>">
                                <?php if ($masked_view): ?>
                                    <i class="fas fa-user-secret"></i>
                                <?php elseif ($cand['photo_path'] && file_exists('../../' . $cand['photo_path'])): ?>
                                    <img src="../../<?php echo h($cand['photo_path']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div class="candidate-info">
                                <div class="candidate-name"><?php echo $masked_view ? 'Candidate ' . $cand['_result_rank'] : h($cand['full_name']); ?></div>
                                <?php if (!$masked_view && $cand['partylist']): ?>
                                    <div class="candidate-partylist"><?php echo h($cand['partylist']); ?></div>
                                <?php endif; ?>
                                <div class="progress-wrapper">
                                    <div class="progress-fill <?php echo $bar_class; ?>" style="width: <?php echo $bar_width; ?>%;"></div>
                                </div>
                            </div>
                            <div class="candidate-votes">
                                <div class="vote-count"><?php echo number_format($cand['vote_count']); ?></div>
                                <div class="vote-label">votes (<?php echo $percentage; ?>%)</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Abstention row -->
                    <div class="candidate-row abstain-row">
                        <div class="candidate-rank"><i class="fas fa-user-slash"></i></div>
                        <div class="candidate-info">
                            <div class="candidate-name">Abstained</div>
                            <div class="candidate-partylist">Did not vote for this position</div>
                        </div>
                        <div class="candidate-votes">
                            <div class="vote-count"><?php echo number_format($abstained); ?></div>
                            <div class="vote-label">voters (<?php echo $abstention_pct; ?>%)</div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">No candidates found for this position.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="position-card">
            <div class="no-data">No positions available for this election.</div>
        </div>
    <?php endif; ?>
    
    <div class="footer-note">
        <i class="fas fa-sync-alt"></i> This page refreshes automatically every 15 seconds.
    </div>
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
        const btn = document.getElementById('themeToggleBtn');
        if (btn) {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            btn.innerHTML = isDark ? '<i class="fas fa-sun"></i> Light' : '<i class="fas fa-moon"></i> Dark';
        }
    }
    updateThemeIcon();
</script>
</body>
</html>
