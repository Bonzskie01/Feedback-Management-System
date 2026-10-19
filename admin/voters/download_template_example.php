<?php
// admin/voters/download_template_example.php

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Invalid request method.');
}

$template_path = $root_path . '/templates/credentials_template.docx';
$validation_error = '';
if (!validate_safe_docx_template($template_path, $validation_error)) {
    http_response_code(404);
    exit('A valid template is not available.');
}

audit_log($conn, 'credentials_template_downloaded', 'template', 'credentials_template.docx', [
    'sha256' => hash_file('sha256', $template_path),
]);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="credentials_template_' . date('Ymd_His') . '.docx"');
header('Content-Length: ' . filesize($template_path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
readfile($template_path);
exit;
?>
