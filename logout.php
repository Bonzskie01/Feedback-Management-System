<?php
require __DIR__ . '/feedback/logout.php';
exit;
require_once __DIR__ . '/includes/session.php';
include 'dbconn.php';

// Clear session in DB — reset login_status and user_session_id
if (isset($_SESSION['login_id'])) {
    pg_query_params($conn,
        "UPDATE public.login SET user_session_id = '', login_status = 0 WHERE id = \$1",
        array($_SESSION['login_id']));
}

// Destroy session
session_unset();
session_destroy();

header('Location: index.php');
exit;
?>
