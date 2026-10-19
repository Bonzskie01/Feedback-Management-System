<?php
// voter/submit_vote.php
$root_path = dirname(__FILE__) . '/..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

// Check kung naka-login
if (!isset($_SESSION['voter_id']) || !isset($_SESSION['election_id'])) {
    redirect('../index.php');
}

$voter_id = $_SESSION['voter_id'];
$election_id = $_SESSION['election_id'];

if (!ensure_voters_election_id_column($conn) || !ensure_election_access_columns($conn)) {
    session_unset();
    session_destroy();
    redirect('../index.php');
}

// Check kung active pa ang voter
$status_check = pg_query_params($conn, "SELECT status, grade_section FROM voters WHERE voter_id = $1 AND election_id = $2", array($voter_id, $election_id));
if (!$status_check || pg_num_rows($status_check) === 0) {
    session_unset();
    session_destroy();
    redirect('thank_you.php');
}
$voter = pg_fetch_assoc($status_check);
if ($voter['status'] !== 'active') {
    session_unset();
    session_destroy();
    redirect('thank_you.php');
}

// Check kung ongoing pa ang election
$election_check = pg_query_params($conn, "SELECT id, status, voting_access_mode, allowed_grade_section FROM elections WHERE id = $1", array($election_id));
if (!$election_check || pg_num_rows($election_check) === 0) {
    session_unset();
    session_destroy();
    redirect('thank_you.php?status=auto_closed');
}
$election = pg_fetch_assoc($election_check);
if ($election['status'] !== 'ongoing') {
    session_unset();
    session_destroy();
    redirect('thank_you.php?status=auto_closed');
}
if (!election_access_allows_voter($election, $voter['grade_section'] ?? '')) {
    session_unset();
    session_destroy();
    redirect('../index.php?access_wait=1');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('ballot.php');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect('ballot.php?error=security_token');
}

$votes = isset($_POST['votes']) && is_array($_POST['votes']) ? $_POST['votes'] : [];
$abstentions = isset($_POST['abstain']) && is_array($_POST['abstain']) ? $_POST['abstain'] : [];

$positions_result = pg_query_params($conn,
    "SELECT id, max_winners FROM positions WHERE election_id = $1",
    array($election_id)
);
if (!$positions_result) {
    redirect('ballot.php?error=vote_failed');
}

$position_rules = [];
while ($position = pg_fetch_assoc($positions_result)) {
    $position_rules[(int) $position['id']] = max(1, (int) $position['max_winners']);
}

$validated_votes = [];
foreach ($votes as $position_id => $candidate_ids) {
    $position_id = (int) $position_id;
    if (!isset($position_rules[$position_id])) {
        redirect('ballot.php?error=invalid_vote');
    }

    $candidate_ids = array_values(array_unique(array_map('intval', (array) $candidate_ids)));
    $candidate_ids = array_values(array_filter($candidate_ids, fn($candidate_id) => $candidate_id > 0));
    if (count($candidate_ids) > $position_rules[$position_id]) {
        redirect('ballot.php?error=too_many_choices');
    }
    if (empty($candidate_ids)) {
        continue;
    }

    $placeholders = [];
    $params = [$position_id];
    foreach ($candidate_ids as $idx => $candidate_id) {
        $placeholders[] = '$' . ($idx + 2);
        $params[] = $candidate_id;
    }

    $valid_candidates = pg_query_params($conn,
        "SELECT id FROM candidates WHERE position_id = $1 AND id IN (" . implode(',', $placeholders) . ") AND is_approved = true AND is_disqualified = false",
        $params
    );
    if (!$valid_candidates || pg_num_rows($valid_candidates) !== count($candidate_ids)) {
        redirect('ballot.php?error=invalid_candidate');
    }

    $validated_votes[$position_id] = $candidate_ids;
}
$votes = $validated_votes;

if (!ensure_voter_turnout_unique_index($conn)) {
    redirect('ballot.php?error=vote_integrity_unavailable');
}

// Start transaction
if (!pg_query($conn, 'BEGIN')) {
    redirect('ballot.php?error=vote_failed');
}

try {
    $lockedVoter = pg_query_params($conn,
        "SELECT status FROM voters WHERE voter_id = $1 AND election_id = $2 FOR UPDATE",
        [$voter_id, $election_id]);
    if (!$lockedVoter || pg_num_rows($lockedVoter) !== 1
        || pg_fetch_result($lockedVoter, 0, 'status') !== 'active') {
        throw new Exception('Voter is no longer eligible.');
    }

    $lockedElection = pg_query_params($conn,
        "SELECT status FROM elections WHERE id = $1 FOR SHARE",
        [$election_id]);
    if (!$lockedElection || pg_num_rows($lockedElection) !== 1
        || pg_fetch_result($lockedElection, 0, 'status') !== 'ongoing') {
        throw new Exception('Election is no longer ongoing.');
    }

    $position_ids = array_keys($position_rules);

    // I-record ang votes (SECRET BALLOT - walang voter_id)
    foreach ($votes as $position_id => $candidate_ids) {
        foreach ($candidate_ids as $candidate_id) {
            $insert_vote = "INSERT INTO votes (election_id, position_id, candidate_id) VALUES ($1, $2, $3)";
            if (!pg_query_params($conn, $insert_vote, array($election_id, $position_id, $candidate_id))) {
                throw new Exception('Unable to record vote.');
            }
        }
    }

    // I-record ang abstain per position. Walang voter_id para secret pa rin.
    foreach ($position_ids as $position_id) {
        $selected_candidates = isset($votes[$position_id]) ? array_filter((array) $votes[$position_id]) : [];
        $explicit_abstain = isset($abstentions[$position_id]) && $abstentions[$position_id] === '1';

        if ($explicit_abstain || count($selected_candidates) === 0) {
            $insert_abstain = "INSERT INTO vote_abstentions (election_id, position_id) VALUES ($1, $2)";
            if (!pg_query_params($conn, $insert_abstain, array($election_id, $position_id))) {
                throw new Exception('Unable to record abstention.');
            }
        }
    }

    // I-record ang turnout
    $insert_turnout = "INSERT INTO voter_turnout (voter_id, election_id) VALUES ($1, $2)";
    if (!pg_query_params($conn, $insert_turnout, array($voter_id, $election_id))) {
        throw new Exception('Unable to record turnout.');
    }

    // I-update ang voter status
    $update_voter = "UPDATE voters SET status = 'voted', voted_at = CURRENT_TIMESTAMP WHERE voter_id = $1 AND election_id = $2 AND status = 'active'";
    $voterUpdateResult = pg_query_params($conn, $update_voter, array($voter_id, $election_id));
    if (!$voterUpdateResult || pg_affected_rows($voterUpdateResult) !== 1) {
        throw new Exception('Unable to update voter status.');
    }

    if (!pg_query($conn, 'COMMIT')) {
        throw new Exception('Unable to commit vote.');
    }

    // Clear session
    session_unset();
    session_destroy();

    // Redirect to thank you page
    redirect('thank_you.php?status=success');

} catch (Exception $e) {
    pg_query($conn, "ROLLBACK");
    redirect('ballot.php?error=vote_failed');
}
?>
