<?php
// admin/voters/export.php
$root_path = dirname(__FILE__) . '/../..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token.');
}

if (!verify_admin_password($conn, $_SESSION['admin_id'] ?? 0, $_POST['admin_password'] ?? '')) {
    http_response_code(403);
    exit('Admin password confirmation is required to export voters.');
}

ensure_voters_election_id_column($conn);

// Optional filters passed from index.php
$search        = isset($_POST['search']) ? trim($_POST['search']) : '';
$status_filter = isset($_POST['status']) ? trim($_POST['status']) : '';

$where_clauses = [];
$params        = [];
$param_count   = 1;

if ($search) {
    $where_clauses[] = "(v.full_name ILIKE \$$param_count OR v.voter_id ILIKE \$$param_count)";
    $params[]        = "%$search%";
    $param_count++;
}

if ($status_filter && in_array($status_filter, ['active', 'voted'])) {
    $where_clauses[] = "v.status = \$$param_count";
    $params[]        = $status_filter;
    $param_count++;
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$result = pg_query_params(
    $conn,
    "SELECT v.voter_id, v.full_name, v.grade_section, v.status, v.generated_at, v.voted_at,
            e.title AS election_title
       FROM voters v
  LEFT JOIN elections e ON e.id = v.election_id
      $where_sql
      ORDER BY generated_at DESC",
    $params
);

if (!$result) {
    $_SESSION['error'] = 'Export failed: could not query voters.';
    redirect('index.php');
}

audit_log($conn, 'voters_exported', 'voters', null, ['format' => 'csv', 'search' => $search, 'status' => $status_filter]);

$filename = 'voters_export_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens it correctly
fwrite($out, "\xEF\xBB\xBF");

// Header row
fputcsv($out, ['Voter ID', 'Full Name', 'Election', 'Grade / Section', 'Status', 'Generated At', 'Voted At']);

while ($row = pg_fetch_assoc($result)) {
    fputcsv($out, [
        $row['voter_id'],
        $row['full_name'],
        $row['election_title'] ?: 'Unassigned',
        $row['grade_section'] ?: '',
        ucfirst($row['status']),
        $row['generated_at'] ? date('Y-m-d H:i:s', strtotime($row['generated_at'])) : '',
        $row['voted_at']     ? date('Y-m-d H:i:s', strtotime($row['voted_at']))     : '',
    ]);
}

fclose($out);
exit;
