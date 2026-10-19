<?php
// admin/elections/delete.php
$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?error=invalid_request');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect('index.php?error=security_token');
}

$id = (int)($_POST['id'] ?? 0);
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$admin_password = $_POST['admin_password'] ?? '';
if ($id <= 0 || $admin_id <= 0 || $admin_password === '') {
    redirect('index.php?error=invalid_request');
}

$rate_identity = 'delete-election-admin-' . $admin_id;
$rate = login_rate_limit_status($conn, $rate_identity, 'password', 5, 900);
if ($rate['limited']) {
    audit_log($conn, 'election_delete_denied', 'election', $id, ['reason' => 'rate_limited']);
    redirect('index.php?error=rate_limited');
}

if (!verify_admin_password($conn, $admin_id, $admin_password)) {
    record_login_attempt($conn, $rate_identity, 'password', false);
    audit_log($conn, 'election_delete_denied', 'election', $id, ['reason' => 'incorrect_password']);
    redirect('index.php?error=invalid_password');
}
record_login_attempt($conn, $rate_identity, 'password', true);

$election_result = pg_query_params(
    $conn,
    'SELECT id, title, status FROM elections WHERE id = $1',
    [$id]
);
if (!$election_result || pg_num_rows($election_result) !== 1) {
    redirect('index.php?error=not_found');
}
$election = pg_fetch_assoc($election_result);
$status = $election['status'];

if (!in_array($status, ['draft', 'closed'], true)) {
    audit_log($conn, 'election_delete_denied', 'election', $id, [
        'reason' => 'invalid_status',
        'status' => $status,
    ]);
    redirect('index.php?error=election_locked');
}

if ($status === 'closed') {
    $confirmation_title = trim($_POST['confirmation_title'] ?? '');
    $confirmation_phrase = trim($_POST['confirmation_phrase'] ?? '');
    $backup_confirmed = ($_POST['backup_confirmed'] ?? '') === '1';

    if (!$backup_confirmed
        || !hash_equals((string)$election['title'], $confirmation_title)
        || !hash_equals('DELETE CLOSED ELECTION', $confirmation_phrase)) {
        audit_log($conn, 'closed_election_purge_denied', 'election', $id, [
            'reason' => 'confirmation_failed',
        ]);
        redirect('index.php?error=confirmation_failed');
    }
}

$count_result = pg_query_params($conn, "
    SELECT
        (SELECT COUNT(*) FROM voters WHERE election_id = $1) AS voters,
        (SELECT COUNT(*) FROM voter_turnout WHERE election_id = $1) AS turnout,
        (SELECT COUNT(*) FROM votes WHERE election_id = $1) AS votes,
        (SELECT COUNT(*) FROM vote_abstentions WHERE election_id = $1) AS abstentions,
        (SELECT COUNT(*) FROM positions WHERE election_id = $1) AS positions,
        (SELECT COUNT(*) FROM candidates c JOIN positions p ON p.id = c.position_id
          WHERE p.election_id = $1) AS candidates,
        (SELECT COUNT(*) FROM party_lists WHERE election_id = $1) AS party_lists
", [$id]);
if (!$count_result || pg_num_rows($count_result) !== 1) {
    error_log('Failed to count related records before deleting election ID ' . $id);
    redirect('index.php?error=delete_failed');
}

$deleted_counts = pg_fetch_assoc($count_result);

pg_query($conn, 'BEGIN');
$delete_turnout = pg_query_params($conn, 'DELETE FROM voter_turnout WHERE election_id = $1', [$id]);
$delete_voters = $delete_turnout
    ? pg_query_params($conn, 'DELETE FROM voters WHERE election_id = $1', [$id])
    : false;
$delete_election = $delete_voters
    ? pg_query_params($conn, 'DELETE FROM elections WHERE id = $1', [$id])
    : false;

if ($delete_turnout && $delete_voters && $delete_election
    && pg_affected_rows($delete_election) === 1) {
    $audit_saved = audit_log(
        $conn,
        $status === 'closed' ? 'closed_election_purged' : 'election_deleted',
        'election',
        $id,
        [
            'title' => $election['title'],
            'status' => $status,
            'deleted_counts' => $deleted_counts,
        ]
    );

    if ($audit_saved && pg_query($conn, 'COMMIT')) {
        redirect('index.php?deleted=1&mode=' . ($status === 'closed' ? 'purge' : 'draft'));
    }
}

pg_query($conn, 'ROLLBACK');
error_log('Election deletion transaction failed for election ID ' . $id);
redirect('index.php?error=delete_failed');
?>
