<?php
// includes/session.php

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

function is_lan_client_ip($ip) {
    if ($ip === '127.0.0.1' || $ip === '::1') return true;
    if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $value = ip2long($ip);
        foreach ([
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['169.254.0.0', '169.254.255.255'],
        ] as [$start, $end]) {
            if ($value >= ip2long($start) && $value <= ip2long($end)) return true;
        }
        return false;
    }

    $normalized = strtolower($ip);
    return str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd')
        || str_starts_with($normalized, 'fe8') || str_starts_with($normalized, 'fe9')
        || str_starts_with($normalized, 'fea') || str_starts_with($normalized, 'feb');
}

if (PHP_SAPI !== 'cli') {
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remoteAddress === '' || !is_lan_client_ip($remoteAddress)) {
        http_response_code(403);
        exit('Access is restricted to the local network.');
    }
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
session_name('FMSSESSID');

if ($isSecure) {
    ini_set('session.cookie_secure', '1');
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; connect-src 'self'");
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

$now = time();
$isAuthenticated = isset($_SESSION['admin_id']) || isset($_SESSION['participant_id']);

if ($isAuthenticated) {
    $idleLimit = isset($_SESSION['admin_id']) ? 1800 : 3600;
    $absoluteLimit = isset($_SESSION['admin_id']) ? 28800 : 21600;
    $userAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

    $_SESSION['auth_started_at'] = $_SESSION['auth_started_at'] ?? $now;
    $_SESSION['last_activity_at'] = $_SESSION['last_activity_at'] ?? $now;
    $_SESSION['user_agent_hash'] = $_SESSION['user_agent_hash'] ?? $userAgentHash;

    $expired = ($now - (int) $_SESSION['last_activity_at']) > $idleLimit
        || ($now - (int) $_SESSION['auth_started_at']) > $absoluteLimit
        || !hash_equals((string) $_SESSION['user_agent_hash'], $userAgentHash);

    if ($expired) {
        session_unset();
        session_regenerate_id(true);
        $_SESSION['session_expired'] = true;
    } else {
        $_SESSION['last_activity_at'] = $now;
        if ($now - (int) ($_SESSION['last_regenerated_at'] ?? 0) >= 900) {
            session_regenerate_id(true);
            $_SESSION['last_regenerated_at'] = $now;
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }
    }
}
?>
