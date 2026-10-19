<?php
// admin/voters/delete.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once '../../config/database.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

function json_response($success, $message) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (!is_admin_logged_in()) {
    json_response(false, 'Admin session expired. Please login again.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_response(false, 'Invalid request method.');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_response(false, 'Invalid security token. Please refresh and try again.');
}

$admin_id = $_SESSION['admin_id'] ?? 0;
$voter_numeric_id = intval($_POST['id'] ?? 0);
$admin_password = $_POST['admin_password'] ?? '';

if (!$admin_id || $voter_numeric_id <= 0 || $admin_password === '') {
    json_response(false, 'Admin password is required to delete a voter.');
}

$admin_result = pg_query_params($conn, "SELECT password_hash FROM admins WHERE id = $1", [$admin_id]);
if (!$admin_result || pg_num_rows($admin_result) === 0) {
    json_response(false, 'Admin account not found.');
}
$admin = pg_fetch_assoc($admin_result);
if (!password_verify($admin_password, $admin['password_hash'])) {
    json_response(false, 'Incorrect admin password. Deletion denied.');
}

$voter_result = pg_query_params($conn, "SELECT v.id, v.voter_id, v.election_id, e.status AS election_status FROM voters v LEFT JOIN elections e ON e.id = v.election_id WHERE v.id = $1", [$voter_numeric_id]);
if (!$voter_result || pg_num_rows($voter_result) === 0) {
    json_response(false, 'Voter not found.');
}
$voter = pg_fetch_assoc($voter_result);
if (empty($voter['election_id']) || !election_is_mutable($conn, (int)$voter['election_id'])) {
    json_response(false, 'Voter deletion is locked after voting starts.');
}

pg_query($conn, 'BEGIN');
$del_turnout = pg_query_params($conn, "DELETE FROM voter_turnout WHERE voter_id = $1", [$voter['voter_id']]);
$del_voter = $del_turnout ? pg_query_params($conn, "DELETE FROM voters WHERE id = $1", [$voter_numeric_id]) : false;

if ($del_turnout && $del_voter) {
    pg_query($conn, 'COMMIT');
    audit_log($conn, 'voter_deleted', 'voter', $voter_numeric_id, ['voter_id' => $voter['voter_id']]);
    json_response(true, 'Voter and related turnout records deleted successfully.');
}

pg_query($conn, 'ROLLBACK');
json_response(false, 'Unable to delete voter. Please try again.');
?>
