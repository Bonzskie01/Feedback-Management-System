<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../config/database.php';

$username = trim($argv[1] ?? '');
$fullName = trim($argv[2] ?? '');

if ($username === '') {
    $username = trim((string) readline('Admin username: '));
}
if ($fullName === '') {
    $fullName = trim((string) readline('Full name: '));
}
$password = (string) readline('Password (minimum 12 characters): ');
$confirmation = (string) readline('Confirm password: ');

if (!preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)) {
    fwrite(STDERR, "Username must be 3-80 letters, numbers, dots, dashes, or underscores.\n");
    exit(1);
}
if ($fullName === '') {
    fwrite(STDERR, "Full name is required.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$result = pg_query_params(
    $conn,
    'INSERT INTO admins (username, password_hash, full_name) VALUES ($1, $2, $3) RETURNING id',
    [$username, password_hash($password, PASSWORD_DEFAULT), $fullName]
);

if (!$result) {
    fwrite(STDERR, "Unable to create administrator. The username may already exist.\n");
    exit(1);
}

fwrite(STDOUT, "Administrator created successfully (ID " . pg_fetch_result($result, 0, 0) . ").\n");
