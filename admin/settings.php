<?php
require __DIR__ . '/feedback_settings.php';
exit;
// admin/settings.php
$root_path = dirname(__FILE__) . '/..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../index.php');
}

$admin_id = $_SESSION['admin_id'];

$message = '';
$error = '';
$active_tab = isset($_GET['tab']) && $_GET['tab'] === 'profile' ? 'profile' : 'interface';

function settings_image_policy($field) {
    $policies = [
        'logo' => ['max_width' => 2048, 'max_height' => 2048, 'max_pixels' => 4000000],
        'background' => ['max_width' => 4096, 'max_height' => 4096, 'max_pixels' => 16000000],
        'favicon' => ['max_width' => 512, 'max_height' => 512, 'max_pixels' => 262144],
    ];
    return $policies[$field] ?? null;
}

function save_settings_image($field, $prefix, $root_path, &$error, &$metadata = null) {
    $metadata = null;
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];
    $policy = settings_image_policy($field);
    if (!$policy || !in_array($prefix, ['logo', 'bg', 'favicon'], true)) {
        $error = 'Invalid image upload field.';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $error = 'Upload failed. Please try again.';
        return null;
    }
    if ((int)$file['size'] < 100 || (int)$file['size'] > 2 * 1024 * 1024) {
        $error = 'Image must be 2MB or smaller.';
        return null;
    }
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        $error = 'Secure image processing is unavailable. Enable the PHP GD extension.';
        return null;
    }

    $allowed = [
        'image/jpeg' => ['extension' => 'jpg', 'type' => IMAGETYPE_JPEG],
        'image/png' => ['extension' => 'png', 'type' => IMAGETYPE_PNG],
        'image/webp' => ['extension' => 'webp', 'type' => IMAGETYPE_WEBP],
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);
    $imageType = @exif_imagetype($file['tmp_name']);
    $imageInfo = @getimagesize($file['tmp_name']);

    if (!isset($allowed[$mime]) || $imageType !== $allowed[$mime]['type'] || !$imageInfo) {
        $error = 'Invalid image content. Use a genuine JPEG, PNG, or WEBP image.';
        return null;
    }

    $width = (int)$imageInfo[0];
    $height = (int)$imageInfo[1];
    if ($width < 1 || $height < 1
        || $width > $policy['max_width']
        || $height > $policy['max_height']
        || ($width * $height) > $policy['max_pixels']) {
        $error = 'Uploaded image dimensions exceed the allowed limit for this field.';
        return null;
    }

    $bytes = file_get_contents($file['tmp_name']);
    $image = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
    unset($bytes);
    if (!$image || imagesx($image) !== $width || imagesy($image) !== $height) {
        if ($image) imagedestroy($image);
        $error = 'The image could not be decoded safely.';
        return null;
    }

    $upload_dir = $root_path . '/assets/images/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        imagedestroy($image);
        $error = 'Image storage is unavailable.';
        return null;
    }

    $extension = $allowed[$mime]['extension'];
    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $target = $upload_dir . $filename;
    $saved = false;

    if ($mime === 'image/jpeg') {
        imageinterlace($image, false);
        $saved = imagejpeg($image, $target, 90);
    } elseif ($mime === 'image/png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $saved = imagepng($image, $target, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $saved = imagewebp($image, $target, 85);
    }
    imagedestroy($image);

    if (!$saved || !is_file($target) || filesize($target) === 0 || filesize($target) > 8 * 1024 * 1024) {
        @unlink($target);
        $error = 'Failed to create a safe normalized image.';
        return null;
    }

    $savedInfo = @getimagesize($target);
    if (!$savedInfo || (int)$savedInfo[0] !== $width || (int)$savedInfo[1] !== $height) {
        @unlink($target);
        $error = 'Image verification failed after processing.';
        return null;
    }

    chmod($target, 0644);
    $metadata = [
        'field' => $field,
        'mime' => $mime,
        'width' => $width,
        'height' => $height,
        'size' => filesize($target),
        'sha256' => hash_file('sha256', $target),
    ];
    return 'assets/images/' . $filename;
}

function remove_custom_settings_image($root_path, $relative_path, $default_path = null) {
    if (!$relative_path || $relative_path === $default_path) return;
    $filename = basename(str_replace(chr(92), '/', $relative_path));
    if (!preg_match('/^(?:logo|bg|favicon)_[a-f0-9]{16}\.(?:jpg|png|webp)$/', $filename)) return;

    $image_root = realpath($root_path . '/assets/images');
    $absolute = realpath($root_path . '/' . ltrim($relative_path, '/'));
    if (!$image_root || !$absolute) return;

    $prefix = rtrim($image_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($absolute, $prefix) && is_file($absolute)) {
        @unlink($absolute);
    }
}
// Handle Profile Update (Account Information & Password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'profile') {
    require_valid_csrf();
    $full_name = trim($_POST['full_name'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name)) {
        $error = 'Full name is required.';
    } else {
        $admin_query = pg_query_params($conn, "SELECT full_name, password_hash FROM admins WHERE id = $1", [$admin_id]);
        if (!$admin_query || pg_num_rows($admin_query) === 0) {
            $error = 'Admin account not found.';
        } else {
            $admin = pg_fetch_assoc($admin_query);
            
            if (!empty($new_password) || !empty($current_password)) {
                if (empty($current_password)) {
                    $error = 'Current password is required to change password.';
                } elseif (strlen($new_password) < 12) {
                    $error = 'New password must be at least 12 characters.';
                } elseif ($new_password !== $confirm_password) {
                    $error = 'New password and confirmation do not match.';
                } elseif (!password_verify($current_password, $admin['password_hash'])) {
                    $error = 'Current password is incorrect.';
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update = pg_query_params($conn, "UPDATE admins SET full_name = $1, password_hash = $2 WHERE id = $3", 
                        [$full_name, $new_hash, $admin_id]);
                    if ($update) {
                        $_SESSION['admin_full_name'] = $full_name;
                        session_regenerate_id(true);
                        audit_log($conn, 'admin_password_changed', 'admin', $admin_id);
                        $message = 'Profile updated successfully!';
                        // Redirect to clear POST data and show toast
                        header("Location: settings.php?tab=profile&success=1");
                        exit;
                    } else {
                        $error = 'Failed to update profile.';
                    }
                }
            } else {
                $update = pg_query_params($conn, "UPDATE admins SET full_name = $1 WHERE id = $2", 
                    [$full_name, $admin_id]);
                if ($update) {
                    $_SESSION['admin_full_name'] = $full_name;
                        session_regenerate_id(true);
                    audit_log($conn, 'admin_profile_updated', 'admin', $admin_id);
                    $message = 'Profile updated successfully!';
                    header("Location: settings.php?tab=profile&success=1");
                    exit;
                } else {
                    $error = 'Failed to update profile.';
                }
            }
        }
    }
}

// Handle Interface Settings Update
$ongoing_settings_result = pg_query($conn, "SELECT EXISTS (SELECT 1 FROM elections WHERE status = 'ongoing')");
$interface_locked = !$ongoing_settings_result || pg_fetch_result($ongoing_settings_result, 0, 0) === 't';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'interface') {
    require_valid_csrf();

    $admin_password = $_POST['admin_password'] ?? '';
    $rate_identity = 'interface-settings-admin-' . (int)$admin_id;
    $rate = login_rate_limit_status($conn, $rate_identity, 'password', 5, 900);

    if ($interface_locked) {
        http_response_code(423);
        $error = 'Interface and image changes are locked while an election is ongoing.';
        audit_log($conn, 'interface_settings_update_denied', 'settings', null, ['reason' => 'ongoing_election']);
    } elseif ($rate['limited']) {
        http_response_code(429);
        $minutes = max(1, (int)ceil($rate['retry_after'] / 60));
        $error = 'Too many failed confirmations. Try again in ' . $minutes . ' minute(s).';
        audit_log($conn, 'interface_settings_update_denied', 'settings', null, ['reason' => 'rate_limited']);
    } elseif (!verify_admin_password($conn, $admin_id, $admin_password)) {
        record_login_attempt($conn, $rate_identity, 'password', false);
        http_response_code(403);
        $error = 'Incorrect admin password. Settings update denied.';
        audit_log($conn, 'interface_settings_update_denied', 'settings', null, ['reason' => 'incorrect_password']);
    } else {
        record_login_attempt($conn, $rate_identity, 'password', true);

        $site_name = trim(strip_tags($_POST['site_name'] ?? ''));
        $school_name = trim(strip_tags($_POST['school_name'] ?? ''));
        $school_address = trim(strip_tags($_POST['school_address'] ?? ''));

        if ($site_name === '' || mb_strlen($site_name) > 100
            || mb_strlen($school_name) > 150 || mb_strlen($school_address) > 255) {
            $error = 'One or more interface text values are empty or too long.';
        } else {
            $old_paths = [
                'logo' => get_setting('logo_path'),
                'background' => get_setting('background_path'),
                'favicon' => get_setting('favicon_path'),
            ];
            $default_paths = [
                'logo' => 'assets/images/OLLCF_LOGO.png',
                'background' => 'assets/images/OLLCF_MAIN.jpg',
                'favicon' => 'assets/images/OLLCF_LOGO.png',
            ];
            $new_paths = [];
            $upload_metadata = [];

            foreach ([
                ['logo', 'logo'],
                ['background', 'bg'],
                ['favicon', 'favicon'],
            ] as [$field, $prefix]) {
                $meta = null;
                $path = save_settings_image($field, $prefix, $root_path, $error, $meta);
                if ($error !== '') break;
                if ($path) {
                    $new_paths[$field] = $path;
                    $upload_metadata[] = $meta;
                }
            }

            if ($error === '') {
                pg_query($conn, 'BEGIN');
                $saved = update_setting('site_name', $site_name)
                    && update_setting('school_name', $school_name)
                    && update_setting('school_address', $school_address);

                $setting_keys = [
                    'logo' => 'logo_path',
                    'background' => 'background_path',
                    'favicon' => 'favicon_path',
                ];
                foreach ($new_paths as $field => $path) {
                    $saved = $saved && update_setting($setting_keys[$field], $path);
                }

                if ($saved && pg_query($conn, 'COMMIT')) {
                    foreach ($new_paths as $field => $path) {
                        remove_custom_settings_image(
                            $root_path,
                            $old_paths[$field] ?? null,
                            $default_paths[$field] ?? null
                        );
                    }
                    audit_log($conn, 'interface_settings_updated', 'settings', null, [
                        'uploaded_images' => $upload_metadata,
                    ]);
                    header('Location: settings.php?tab=interface&success=1');
                    exit;
                }

                pg_query($conn, 'ROLLBACK');
                $error = 'Failed to save interface settings.';
            }

            if ($error !== '') {
                foreach ($new_paths as $path) {
                    remove_custom_settings_image($root_path, $path);
                }
            }
        }
    }
}
// Flash messages from URL parameters
$flashMessage = null;
$flashType = null;
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $flashMessage = $message ?: ($active_tab === 'profile' ? 'Profile updated successfully!' : 'Settings saved successfully!');
    $flashType = "success";
} elseif (isset($_GET['error']) || $error) {
    $flashMessage = $error ?: 'An error occurred. Please try again.';
    $flashType = "error";
}

// Fetch current values
$admin_data = pg_fetch_assoc(pg_query_params($conn, "SELECT full_name FROM admins WHERE id = $1", [$admin_id]));

$current_site_name = get_setting('site_name');
$current_school_name = get_setting('school_name');
$current_school_address = get_setting('school_address');
$current_logo = get_setting('logo_path');
$current_bg = get_setting('background_path');
$current_favicon = get_setting('favicon_path');

$page_title = 'Settings - ' . site_name();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/png" href="../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Deep blue theme – same as original (keep all CSS) */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(128,128,128,0.4); border-radius: 3px; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
        }
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255,255,255,0.92);
            --bg-panel: rgba(248,250,255,0.9);
            --bg-input: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: rgba(0,0,0,0.1);
            --accent-primary: #1a237e;
            --shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        [data-theme="dark"] {
            --bg-body: #060d1f;
            --bg-card: #0f1e4a;
            --bg-panel: rgba(20,24,36,0.9);
            --bg-input: #252a3a;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: rgba(255,255,255,0.1);
            --accent-primary: #3b82f6;
        }
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('../<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
        }
        :root .bg-layer::before { content: ''; position: absolute; inset: 0; background: rgba(240,244,255,0.7); }
        [data-theme="dark"] .bg-layer::before { background: rgba(6,13,31,0.85); }
        .sidebar {
            width: 240px;
            min-height: 100vh;
            background: #0f1e4a;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            z-index: 100;
            transition: transform 0.3s;
        }
        .sidebar-header {
            padding: 22px 20px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .sidebar-header .logo-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-header img {
            width: 34px;
            height: 34px;
            object-fit: contain;
        }
        .sidebar-header h2 {
            font-size: 14px;
            font-weight: 800;
            color: white;
            line-height: 1.2;
        }
        .sidebar-header small {
            font-size: 10px;
            color: rgba(255,255,255,0.4);
            display: block;
        }
        .sidebar-nav {
            flex: 1;
            padding: 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 13px;
            border-radius: 10px;
            text-decoration: none;
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.1); color: white; }
        .sidebar-nav a.active {
            background: rgba(59,130,246,0.25);
            color: #90caf9;
            border: 1px solid rgba(59,130,246,0.3);
        }
        .sidebar-nav a i { width: 18px; text-align: center; }
        .nav-divider {
            height: 1px;
            background: rgba(255,255,255,0.08);
            margin: 6px 0;
        }
        .sidebar-nav a.logout-link { color: rgba(248,113,113,0.8); }
        .sidebar-nav a.logout-link:hover { background: rgba(239,68,68,0.15); color: #f87171; }
        .sidebar-footer {
            padding: 14px 18px;
            border-top: 1px solid rgba(255,255,255,0.06);
            font-size: 10px;
            color: rgba(255,255,255,0.25);
            text-align: center;
        }
        .admin-wrapper {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }
        .main-content {
            margin-left: 240px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            padding: 14px 26px;
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: var(--shadow);
        }
        .topbar h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .topbar-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
        }
        .content-area {
            padding: 24px 26px;
            flex: 1;
        }
        .settings-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 12px;
        }
        .tab-link {
            background: none;
            border: none;
            font-size: 15px;
            font-weight: 600;
            padding: 8px 20px;
            border-radius: 30px;
            cursor: pointer;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .tab-link.active {
            background: var(--accent-primary);
            color: white;
        }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(4px);
            border-radius: 16px;
            padding: 28px;
            max-width: 750px;
            margin: 0 auto;
        }
        .card h2 {
            font-size: 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
        }
        .settings-fieldset {
            border: 0;
            margin: 0;
            min-width: 0;
            padding: 0;
        }
        .settings-fieldset:disabled {
            opacity: 0.6;
        }
        .settings-lock-message {
            align-items: center;
            background: #fff1f2;
            border-left: 4px solid #dc2626;
            border-radius: 6px;
            color: #991b1b;
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            padding: 12px 14px;
        }
        .form-group {
            margin-bottom: 22px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 13px;
            color: var(--text-secondary);
        }
        input[type="text"], input[type="email"], input[type="password"], input[type="file"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 14px;
        }
        .btn-primary {
            background: #1a237e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover {
            background: #283593;
            transform: translateY(-1px);
        }
        .preview-img {
            max-width: 150px;
            max-height: 80px;
            margin-top: 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            display: block;
        }
        .preview-favicon {
            width: 32px;
            height: 32px;
            object-fit: contain;
            margin-top: 10px;
        }
        .hamburger { display: none; background: none; border: none; color: var(--text-primary); font-size: 20px; cursor: pointer; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .main-content { margin-left: 0; }
            .hamburger { display: block; }
            .content-area { padding: 16px; }
            .card { padding: 20px; margin: 0 10px; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>
<div class="bg-layer"></div>
<div class="admin-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-row">
                <img src="../<?php echo site_logo(); ?>" alt="Logo">
                <div><h2>Voting System</h2><small>Admin Panel</small></div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="elections/index.php"><i class="fas fa-calendar-alt"></i> Elections</a>
            <a href="party_lists/index.php"><i class="fas fa-list-ul"></i> Party Lists</a>
            <a href="positions/index.php"><i class="fas fa-trophy"></i> Positions</a>
            <a href="candidates/index.php"><i class="fas fa-users"></i> Candidates</a>
            <a href="voters/index.php"><i class="fas fa-user-check"></i> Voters</a>
            <a href="results/live.php"><i class="fas fa-chart-bar"></i> Live Results</a>
            <div class="nav-divider"></div>
            <a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a>
            <a href="profile.php"><i class="fas fa-shield-alt"></i>2FA</a>
            <a href="#" class="logout-link" onclick="confirmLogout(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">© <?php echo date('Y'); ?> <?php echo site_name(); ?> Information Systems</div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <main class="main-content">
        <header class="topbar">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></button>
                <h1><i class="fas fa-cog"></i> Settings</h1>
            </div>
            <div class="topbar-right">
                <button class="topbar-btn" onclick="toggleTheme()" title="Toggle Theme"><i class="fas fa-moon"></i></button>
                <button class="topbar-btn" onclick="confirmLogout(event)" title="Logout"><i class="fas fa-sign-out-alt"></i></button>
            </div>
        </header>

        <div class="content-area">
            <div class="settings-tabs">
                <button class="tab-link <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" onclick="switchTab('profile')"><i class="fas fa-user-circle"></i> Profile</button>
                <button class="tab-link <?php echo $active_tab === 'interface' ? 'active' : ''; ?>" onclick="switchTab('interface')"><i class="fas fa-sliders-h"></i> Interface</button>
            </div>

            <!-- Profile Settings Tab -->
            <div id="profile-tab" class="tab-content" style="display: <?php echo $active_tab === 'profile' ? 'block' : 'none'; ?>;">
                <div class="card">
                    <h2><i class="fas fa-user-edit"></i> Account Information</h2>
                    <!-- No inline alerts – only SweetAlert toast -->
                    <form method="POST" id="profileForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="profile">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($admin_data['full_name']); ?>" required>
                        </div>
                        <hr style="margin: 25px 0; border-color: var(--border-color);">
                        <h3 style="margin-bottom: 15px; font-size: 16px;"><i class="fas fa-key"></i> Change Password</h3>
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>New Password (leave blank to keep unchanged)</label>
                            <input type="password" name="new_password" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" autocomplete="off">
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Update Profile</button>
                    </form>
                </div>
            </div>

            <!-- Interface Settings Tab -->
            <div id="interface-tab" class="tab-content" style="display: <?php echo $active_tab === 'interface' ? 'block' : 'none'; ?>;">
                <div class="card">
                    <h2><i class="fas fa-sliders-h"></i> Interface Settings</h2>
                    <?php if ($interface_locked): ?>
                        <div class="settings-lock-message">
                            <i class="fas fa-lock"></i>
                            Interface and image changes are disabled while an election is ongoing.
                        </div>
                    <?php endif; ?>
                    <form method="POST" enctype="multipart/form-data" id="interfaceForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="interface">
                        <fieldset class="settings-fieldset" <?php echo $interface_locked ? 'disabled' : ''; ?>>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Confirm Admin Password</label>
                            <input type="password" name="admin_password" autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-globe"></i> Site Name (Browser Tab)</label>
                            <input type="text" name="site_name" value="<?php echo htmlspecialchars($current_site_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> School Name</label>
                            <input type="text" name="school_name" value="<?php echo htmlspecialchars($current_school_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> School Address</label>
                            <input type="text" name="school_address" value="<?php echo htmlspecialchars($current_school_address); ?>" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-image"></i> Logo (PNG, JPG, WEBP)</label>
                            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp">
                            <?php if ($current_logo && file_exists('../' . $current_logo)): ?>
                                <img src="../<?php echo $current_logo; ?>" class="preview-img" alt="Current Logo">
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-palette"></i> Background Image</label>
                            <input type="file" name="background" accept="image/jpeg,image/png,image/webp">
                            <?php if ($current_bg && file_exists('../' . $current_bg)): ?>
                                <img src="../<?php echo $current_bg; ?>" class="preview-img" alt="Current Background">
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-star"></i> Favicon (PNG, JPG, WEBP)</label>
                            <input type="file" name="favicon" accept="image/jpeg,image/png,image/webp">
                            <?php if ($current_favicon && file_exists('../' . $current_favicon)): ?>
                                <img src="../<?php echo $current_favicon; ?>" class="preview-favicon" alt="Current Favicon">
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </fieldset>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    function toggleTheme() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('vms-theme', 'light');
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('vms-theme', 'dark');
        }
    }
    function confirmLogout(event) {
        event.preventDefault();
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        Swal.fire({
            title: '<i class="fas fa-sign-out-alt"></i> Logging Out?',
            text: 'Are you sure you want to end your admin session?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Yes, Logout',
            cancelButtonText: '<i class="fas fa-times"></i> Cancel',
            background: isDark ? '#0f1e4a' : '#ffffff',
            color: isDark ? '#f1f5f9' : '#1e293b'
        }).then(result => {
            if (result.isConfirmed) window.location.href = 'logout.php';
        });
    }
    function switchTab(tab) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
        document.getElementById('profile-tab').style.display = tab === 'profile' ? 'block' : 'none';
        document.getElementById('interface-tab').style.display = tab === 'interface' ? 'block' : 'none';
        const btns = document.querySelectorAll('.tab-link');
        btns.forEach(btn => btn.classList.remove('active'));
        if (tab === 'profile') btns[0].classList.add('active');
        else btns[1].classList.add('active');
    }
    (function() {
        if (localStorage.getItem('vms-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab === 'profile' || activeTab === 'interface') {
            switchTab(activeTab);
        }
    })();

    // SweetAlert toast for flash messages
    <?php if ($flashMessage): ?>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: '<?= $flashType ?>',
            title: <?= json_encode($flashMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: document.documentElement.getAttribute('data-theme') === 'dark' ? '#0f1e4a' : '#ffffff',
            color: document.documentElement.getAttribute('data-theme') === 'dark' ? '#f1f5f9' : '#1e293b'
        });
    });
    <?php endif; ?>

    // Mobile sidebar toggle
    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (hamburger) {
        hamburger.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        });
    }
</script>
</body>
</html>
