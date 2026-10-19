<?php
// admin/party_lists/delete.php
$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?error=1');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect('index.php?error=security_token');
}

$id = intval($_POST['id'] ?? 0);
$admin_password = $_POST['admin_password'] ?? '';
if ($id <= 0 || $admin_password === '') {
    redirect('index.php?error=1');
}

$admin_id = $_SESSION['admin_id'] ?? 0;
$admin_result = pg_query_params($conn, "SELECT password_hash FROM admins WHERE id = $1", [$admin_id]);
if (!$admin_result || pg_num_rows($admin_result) === 0) {
    redirect('index.php?error=1');
}
$admin = pg_fetch_assoc($admin_result);
if (!password_verify($admin_password, $admin['password_hash'])) {
    redirect('index.php?error=invalid_password');
}

$partyResult = pg_query_params($conn, "SELECT election_id FROM party_lists WHERE id = $1", [$id]);
if (!$partyResult || pg_num_rows($partyResult) !== 1
    || !election_is_mutable($conn, (int)pg_fetch_result($partyResult, 0, 'election_id'))) {
    redirect('index.php?error=election_locked');
}

$check_result = pg_query_params($conn, "SELECT COUNT(*) FROM candidates WHERE party_list_id = $1", [$id]);
$count = ($check_result) ? (int) pg_fetch_result($check_result, 0, 0) : 0;
if ($count > 0) {
    redirect('index.php?error=in_use');
}

$delete_result = pg_query_params($conn, "DELETE FROM party_lists WHERE id = $1", [$id]);
if ($delete_result && pg_affected_rows($delete_result) > 0) {
    audit_log($conn, 'party_list_deleted', 'party_list', $id);
    redirect('index.php?deleted=1');
}

redirect('index.php?error=1');
?>
