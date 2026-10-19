<?php
// admin/candidates/delete.php
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

$candidate_result = pg_query_params($conn, "SELECT c.photo_path, p.election_id FROM candidates c JOIN positions p ON p.id = c.position_id WHERE c.id = $1", [$id]);
$photo_path = null;
if ($candidate_result && pg_num_rows($candidate_result) > 0) {
    $candidate = pg_fetch_assoc($candidate_result);
    $photo_path = $candidate['photo_path'] ?? null;
    if (!election_is_mutable($conn, (int)$candidate['election_id'])) {
        redirect('index.php?error=election_locked');
    }
}


$delete_result = pg_query_params($conn, "DELETE FROM candidates WHERE id = $1", [$id]);

if ($delete_result && pg_affected_rows($delete_result) > 0) {
    audit_log($conn, 'candidate_deleted', 'candidate', $id);
    if ($photo_path) {
        $absolute_photo = realpath($root_path . '/' . $photo_path);
        $photo_root = realpath($root_path . '/assets/photos');
        if ($absolute_photo && $photo_root && str_starts_with($absolute_photo, $photo_root) && is_file($absolute_photo)) {
            @unlink($absolute_photo);
        }
    }
    redirect('index.php?deleted=1');
}

redirect('index.php?error=1');
?>
