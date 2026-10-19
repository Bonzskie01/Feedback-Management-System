<?php
header('Content-Type: application/json');

$root_path = dirname(__FILE__) . '/..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

header('Cache-Control: no-store');

$election_id = isset($_GET['election_id']) ? intval($_GET['election_id']) : 0;

if ($election_id <= 0) {
    echo json_encode(['error' => 'Invalid election_id']);
    exit;
}

$result = pg_query_params($conn, "SELECT id, name, max_winners FROM positions WHERE election_id = $1 ORDER BY display_order ASC", array($election_id));

if (!$result) {
    echo json_encode(['error' => 'Database query failed.']);
    exit;
}

$positions = [];
while ($row = pg_fetch_assoc($result)) {
    $positions[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'max_winners' => $row['max_winners']
    ];
}

echo json_encode($positions);
?>
