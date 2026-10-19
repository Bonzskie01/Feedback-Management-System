<?php
require_once __DIR__.'/../../includes/session.php';require_once __DIR__.'/../../includes/functions.php';if(!is_admin_logged_in()){http_response_code(403);exit('Forbidden');}
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="participant_import_template.csv"');echo "full_name,group_name\r\n";echo "Juan Dela Cruz,Department A\r\n";echo "Maria Santos,Department B\r\n";exit;
