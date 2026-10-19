<?php
// voter/check_election_status.php
$root_path = dirname(__FILE__) . '/..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

header('Content-Type: application/json');

$response = [
    'active' => false,
    'status' => 'closed',
    'message' => 'The election is no longer active.',
    'redirect' => true
];

if (!isset($_SESSION['election_id']) || !isset($_SESSION['voter_id'])) {
    $response['message'] = 'Session expired.';
    echo json_encode($response);
    exit;
}

$election_id = $_SESSION['election_id'];

if (!ensure_election_access_columns($conn)) {
    echo json_encode($response);
    exit;
}

// Check election status
$result = pg_query_params($conn, "SELECT status, voting_access_mode, allowed_grade_section FROM elections WHERE id = $1", array($election_id));
$voter_result = pg_query_params($conn, "SELECT grade_section FROM voters WHERE voter_id = $1 AND election_id = $2", array($_SESSION['voter_id'], $election_id));

if ($result && pg_num_rows($result) > 0 && $voter_result && pg_num_rows($voter_result) > 0) {
    $election = pg_fetch_assoc($result);
    $voter = pg_fetch_assoc($voter_result);
    $status = $election['status'];
    
    if ($status === 'ongoing') {
        if (election_access_allows_voter($election, $voter['grade_section'] ?? '')) {
            $response['active'] = true;
            $response['status'] = 'ongoing';
            $response['message'] = 'Election is active.';
            $response['redirect'] = false;
        } else {
            $response['active'] = false;
            $response['status'] = 'restricted';
            $response['message'] = 'Voting is now open for another grade or section.';
            $response['redirect'] = true;
        }
    } elseif ($status === 'paused') {
        $response['active'] = false;
        $response['status'] = 'paused';
        $response['message'] = 'The election has been paused. Please wait for it to resume.';
        $response['redirect'] = false;
    } elseif ($status === 'closed') {
        $response['active'] = false;
        $response['status'] = 'closed';
        $response['message'] = 'The election has ended. Thank you for participating.';
        $response['redirect'] = true;
    }
}

echo json_encode($response);
?>
