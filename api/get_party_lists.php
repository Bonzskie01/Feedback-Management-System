<?php
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$root_path = dirname(__FILE__) . '/..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$election_id = isset($_GET['election_id']) ? intval($_GET['election_id']) : 0;
if ($election_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid election_id.']);
    exit;
}

$result = pg_query_params($conn,
    'SELECT id, name FROM party_lists WHERE election_id = $1 ORDER BY name, id',
    [$election_id]
);
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load party lists.']);
    exit;
}

$party_lists = [];
while ($row = pg_fetch_assoc($result)) {
    $party_lists[] = [
        'id' => (int) $row['id'],
        'name' => $row['name']
    ];
}

echo json_encode($party_lists, JSON_UNESCAPED_UNICODE);
?>