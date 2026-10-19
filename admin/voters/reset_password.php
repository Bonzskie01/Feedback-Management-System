<?php
// admin/voters/reset_password.php
$root_path = dirname(__FILE__) . '/../..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

function generate_secure_voter_password($length = 14) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

$id = $_SERVER['REQUEST_METHOD'] === 'POST' ? intval($_POST['id'] ?? 0) : intval($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php?error=1');
}

$voter_q = pg_query_params($conn, "
    SELECT v.*, e.title AS election_title, e.status AS election_status
      FROM voters v
      LEFT JOIN elections e ON v.election_id = e.id
     WHERE v.id = $1
", array($id));
if (!$voter_q || pg_num_rows($voter_q) === 0) {
    redirect('index.php?error=1');
}
$voter = pg_fetch_assoc($voter_q);
if (empty($voter['election_id']) || !election_is_mutable($conn, (int)$voter['election_id'])) {
    redirect('index.php?error=election_locked');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Confirm Password Reset</title>
    <style>body{font-family:Arial,sans-serif;background:#eef2ff;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{background:white;padding:24px;border-radius:12px;max-width:420px;box-shadow:0 12px 35px rgba(0,0,0,.15)}input,button,a{width:100%;box-sizing:border-box;margin-top:12px;padding:12px;border-radius:8px}button{border:0;background:#1a237e;color:white;font-weight:bold}a{display:block;text-align:center;text-decoration:none;background:#e5e7eb;color:#111827}</style></head>
    <body><div class="card"><h2>Confirm Password Reset</h2><p>Reset password for <strong><?php echo h($voter['full_name']); ?></strong>?</p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <input type="password" name="admin_password" required placeholder="Confirm admin password">
        <button type="submit">Reset Password</button>
    </form><a href="index.php">Cancel</a></div></body></html>
    <?php
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

$admin_result = pg_query_params($conn, "SELECT password_hash FROM admins WHERE id = $1", [$_SESSION['admin_id']]);
$admin = $admin_result && pg_num_rows($admin_result) > 0 ? pg_fetch_assoc($admin_result) : null;
if (!$admin || !password_verify($_POST['admin_password'] ?? '', $admin['password_hash'])) {
    redirect('index.php?error=1');
}

$new_password = generate_secure_voter_password();
$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$update = pg_query_params($conn,
    "UPDATE voters SET password_hash = $1 WHERE id = $2",
    array($hashed_password, $id)
);

if ($update && pg_affected_rows($update) > 0) {
    audit_log($conn, 'voter_password_reset', 'voter', $id, ['voter_id' => $voter['voter_id']]);
    $_SESSION['reset_credentials'] = [
        'voter_id' => $voter['voter_id'],
        'password' => $new_password,
        'full_name' => $voter['full_name'],
        'grade_section' => $voter['grade_section'],
        'election_id' => $voter['election_id'] ?? null,
        'election_title' => $voter['election_title'] ?? '',
    ];
    redirect('view_credentials.php?id=' . $id . '&reset=1');
}

redirect('index.php?error=1');
?>
