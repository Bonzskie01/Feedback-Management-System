<?php
// config/database.php

function load_env_file($path) {
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

$envPath = getenv('FMS_ENV_FILE') ?: getenv('VMS_ENV_FILE');
if (!$envPath) {
    $projectEnvPath = dirname(__DIR__) . '/.env';
    $envPath = is_readable($projectEnvPath)
        ? $projectEnvPath
        : dirname(__DIR__, 2) . '/private-config/feedback-system.env';
    $legacyEnvPath = dirname(__DIR__, 2) . '/private-config/voting-system.env';
    if (!is_readable($envPath) && is_readable($legacyEnvPath)) {
        $envPath = $legacyEnvPath;
    }
}
load_env_file($envPath);

// Keep HTML datetime fields and database schedule checks on one local clock.
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'feedback_schema';
$user = getenv('DB_USER') ?: 'feedback_app';
$password = getenv('DB_PASSWORD') ?: '';

$conn_string = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s',
    $host,
    $port,
    $dbname,
    $user,
    $password
);

$conn = pg_connect($conn_string);

if (!$conn) {
    error_log('Database connection failed. Check DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD.');
    http_response_code(500);
    die('Database connection failed. Please contact the system administrator.');
}

$dbTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
pg_query_params($conn, 'SELECT set_config($1, $2, false)', ['timezone', $dbTimezone]);
?>
