<?php
// admin/voters/bulk_delete.php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once '../../config/database.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

function json_response($success, $message) {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if (!is_admin_logged_in()) {
    json_response(false, 'Admin session expired. Please login again.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['ids'])) {
    json_response(false, 'Invalid request.');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_response(false, 'Invalid security token. Please refresh and try again.');
}

$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_password = $_POST['admin_password'] ?? '';
if (!$admin_id || $admin_password === '') {
    json_response(false, 'Admin password is required to delete voters.');
}

$admin_result = pg_query_params($conn, "SELECT password_hash FROM admins WHERE id = $1", [$admin_id]);
if (!$admin_result || pg_num_rows($admin_result) === 0) {
    json_response(false, 'Admin account not found.');
}
$admin = pg_fetch_assoc($admin_result);
if (!password_verify($admin_password, $admin['password_hash'])) {
    json_response(false, 'Incorrect admin password. Deletion denied.');
}

$ids = json_decode($_POST['ids'], true);
if (!is_array($ids) || empty($ids)) {
    json_response(false, 'No voter IDs provided.');
}
$ids = array_values(array_filter(array_unique(array_map('intval', $ids)), fn($id) => $id > 0));
if (empty($ids)) {
    json_response(false, 'No valid voter IDs provided.');
}
$id_count = count($ids);

$placeholders = [];
for ($i = 1; $i <= $id_count; $i++) {
    $placeholders[] = '$' . $i;
}
$placeholder_str = implode(',', $placeholders);

$voter_id_strings = [];
$voter_data = pg_query_params($conn, "SELECT v.voter_id, v.election_id, e.status AS election_status FROM voters v LEFT JOIN elections e ON e.id = v.election_id WHERE v.id IN ($placeholder_str)", $ids);
if (!$voter_data) {
    json_response(false, 'Unable to fetch selected voters.');
}
while ($row = pg_fetch_assoc($voter_data)) {
    if (empty($row['election_id']) || !in_array($row['election_status'], ['draft', 'published'], true)) {
        json_response(false, 'Voter deletion is locked after voting starts.');
    }
    $voter_id_strings[] = $row['voter_id'];
}
if (empty($voter_id_strings)) {
    json_response(false, 'Selected voters were not found.');
}

pg_query($conn, 'BEGIN');

$string_placeholders = [];
for ($j = 1; $j <= count($voter_id_strings); $j++) {
    $string_placeholders[] = '$' . $j;
}
$string_placeholder_str = implode(',', $string_placeholders);
$del_turnout = pg_query_params($conn, "DELETE FROM voter_turnout WHERE voter_id IN ($string_placeholder_str)", $voter_id_strings);
if (!$del_turnout) {
    pg_query($conn, 'ROLLBACK');
    json_response(false, 'Unable to delete related turnout records.');
}

$del_voters = pg_query_params($conn, "DELETE FROM voters WHERE id IN ($placeholder_str)", $ids);
if (!$del_voters) {
    pg_query($conn, 'ROLLBACK');
    json_response(false, 'Unable to delete voters.');
}

pg_query($conn, 'COMMIT');
audit_log($conn, 'voters_bulk_deleted', 'voters', null, ['count' => $id_count]);
json_response(true, 'Successfully deleted ' . $id_count . ' voter(s) and related turnout records.');
?>
