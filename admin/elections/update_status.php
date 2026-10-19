<?php
// admin/elections/update_status.php
$root_path = dirname(__FILE__) . '/../..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$id = $_SERVER['REQUEST_METHOD'] === 'POST' ? intval($_POST['id'] ?? 0) : intval($_GET['id'] ?? 0);
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
$allowed_actions = ['publish', 'start', 'pause', 'resume', 'close', 'reopen'];

if ($id <= 0 || !in_array($action, $allowed_actions, true)) {
    redirect('index.php?error=1');
}

$election_result = pg_query_params($conn, "SELECT title, status FROM elections WHERE id = $1", [$id]);
$election = $election_result && pg_num_rows($election_result) > 0 ? pg_fetch_assoc($election_result) : null;
if (!$election) {
    redirect('index.php?error=1');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Confirm Election Action</title>
    <style>body{font-family:Arial,sans-serif;background:#eef2ff;display:flex;align-items:center;justify-content:center;min-height:100vh}.card{background:white;padding:24px;border-radius:12px;max-width:440px;box-shadow:0 12px 35px rgba(0,0,0,.15)}input,button,a{width:100%;box-sizing:border-box;margin-top:12px;padding:12px;border-radius:8px}button{border:0;background:#1a237e;color:white;font-weight:bold}a{display:block;text-align:center;text-decoration:none;background:#e5e7eb;color:#111827}</style></head>
    <body><div class="card"><h2>Confirm Election Action</h2><p>Action: <strong><?php echo h(ucfirst($action)); ?></strong></p><p>Election: <strong><?php echo h($election['title']); ?></strong></p>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <input type="hidden" name="action" value="<?php echo h($action); ?>">
        <input type="password" name="admin_password" required placeholder="Confirm admin password">
        <button type="submit">Confirm</button>
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

$allowedTransitions = [
    'draft' => ['publish'],
    'published' => ['start'],
    'ongoing' => ['pause', 'close'],
    'paused' => ['resume', 'close'],
    'closed' => ['reopen'],
];
$currentStatus = $election['status'];
if (!in_array($action, $allowedTransitions[$currentStatus] ?? [], true)) {
    redirect('index.php?error=invalid_transition');
}

switch ($action) {
    case 'publish':
        $new_status = 'published';
        break;
    case 'start':
        $ongoingCheck = pg_query_params($conn,
            "SELECT 1 FROM elections WHERE id <> $1 AND status IN ('ongoing', 'paused') LIMIT 1",
            [$id]);
        if ($ongoingCheck && pg_num_rows($ongoingCheck) > 0) {
            redirect('index.php?error=another_election_active');
        }
        $new_status = 'ongoing';
        break;
    case 'pause':
        $new_status = 'paused';
        break;
    case 'resume':
        $new_status = 'ongoing';
        break;
    case 'close':
        $new_status = 'closed';
        break;
    case 'reopen':
        $ongoing_check = pg_query($conn, "SELECT id FROM elections WHERE status IN ('ongoing', 'paused')");
        if (pg_num_rows($ongoing_check) > 0) {
            redirect('index.php?error=1');
        }
        $election_info = pg_query_params($conn, "SELECT * FROM elections WHERE id = $1", array($id));
        $election = pg_fetch_assoc($election_info);
        if (strtotime($election['end_time']) < time()) {
            $new_end_time = date('Y-m-d H:i:s', strtotime('+24 hours'));
            pg_query_params($conn, "UPDATE elections SET end_time = $1 WHERE id = $2", array($new_end_time, $id));
        }
        $new_status = 'ongoing';
        break;
}

$update_result = pg_query_params($conn, "UPDATE elections SET status = $1 WHERE id = $2", array($new_status, $id));

if ($update_result && pg_affected_rows($update_result) > 0) {
    audit_log($conn, 'election_status_changed', 'election', $id, [
        'from' => $currentStatus,
        'to' => $new_status,
        'action' => $action,
    ]);
    redirect('index.php?status_updated=1');
}
redirect('index.php?error=1');
?>
