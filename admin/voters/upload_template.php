<?php
// admin/voters/upload_template.php
$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$message = '';
$message_type = '';
$templates_dir = $root_path . '/templates';
$template_path = $templates_dir . '/credentials_template.docx';

$ongoing_result = pg_query($conn, "SELECT EXISTS (SELECT 1 FROM elections WHERE status = 'ongoing')");
$upload_locked = !$ongoing_result || pg_fetch_result($ongoing_result, 0, 0) === 't';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $admin_id = (int)($_SESSION['admin_id'] ?? 0);
    $admin_password = $_POST['admin_password'] ?? '';
    $rate_identity = 'template-upload-admin-' . $admin_id;
    $rate = login_rate_limit_status($conn, $rate_identity, 'password', 5, 900);

    if ($upload_locked) {
        http_response_code(423);
        $message = 'Template upload is locked while an election is ongoing.';
        $message_type = 'error';
        audit_log($conn, 'credentials_template_upload_denied', 'template', null, ['reason' => 'ongoing_election']);
    } elseif (!isset($_FILES['template']) || $_FILES['template']['error'] === UPLOAD_ERR_NO_FILE) {
        $message = 'Please select a .docx file.';
        $message_type = 'error';
    } elseif ($_FILES['template']['error'] !== UPLOAD_ERR_OK) {
        $message = 'Upload failed. Please try again.';
        $message_type = 'error';
    } elseif ($rate['limited']) {
        http_response_code(429);
        $minutes = max(1, (int)ceil($rate['retry_after'] / 60));
        $message = 'Too many failed confirmations. Try again in ' . $minutes . ' minute(s).';
        $message_type = 'error';
        audit_log($conn, 'credentials_template_upload_denied', 'template', null, ['reason' => 'rate_limited']);
    } elseif (!verify_admin_password($conn, $admin_id, $admin_password)) {
        record_login_attempt($conn, $rate_identity, 'password', false);
        http_response_code(403);
        $message = 'Incorrect admin password. Upload denied.';
        $message_type = 'error';
        audit_log($conn, 'credentials_template_upload_denied', 'template', null, ['reason' => 'incorrect_password']);
    } else {
        record_login_attempt($conn, $rate_identity, 'password', true);
        $file = $_FILES['template'];
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'docx' || !is_uploaded_file($file['tmp_name'])) {
            $message = 'Invalid upload. Please select a genuine Microsoft Word .docx file.';
            $message_type = 'error';
            audit_log($conn, 'credentials_template_upload_rejected', 'template', null, ['reason' => 'invalid_upload']);
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $message = 'File size exceeds the 5MB limit.';
            $message_type = 'error';
            audit_log($conn, 'credentials_template_upload_rejected', 'template', null, ['reason' => 'size_limit']);
        } else {
            $validation_error = '';
            if (!validate_safe_docx_template($file['tmp_name'], $validation_error)) {
                $message = 'Unsafe or invalid DOCX template: ' . $validation_error;
                $message_type = 'error';
                audit_log($conn, 'credentials_template_upload_rejected', 'template', null, [
                    'reason' => $validation_error,
                    'size' => (int)$file['size'],
                ]);
            } else {
                if (!is_dir($templates_dir) && !mkdir($templates_dir, 0755, true)) {
                    $message = 'Template storage is unavailable.';
                    $message_type = 'error';
                } else {
                    $sha256 = hash_file('sha256', $file['tmp_name']);
                    if (move_uploaded_file($file['tmp_name'], $template_path)) {
                        chmod($template_path, 0644);
                        $message = 'Template uploaded and security-validated successfully.';
                        $message_type = 'success';
                        audit_log($conn, 'credentials_template_uploaded', 'template', 'credentials_template.docx', [
                            'sha256' => $sha256,
                            'size' => (int)$file['size'],
                        ]);
                    } else {
                        $message = 'Failed to save the template. Please check folder permissions.';
                        $message_type = 'error';
                    }
                }
            }
        }
    }
}
$template_exists = file_exists($template_path);
$template_size = $template_exists ? filesize($template_path) : 0;
$template_date = $template_exists ? date('F d, Y h:i A', filemtime($template_path)) : '';
$page_title = 'Upload Credentials Template - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo site_name(); ?> - Upload Template</title>
    <link rel="icon" type="image/png" href="../../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/responsive.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #f0f4ff;
            color: #1e293b;
            padding: 24px;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 14px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
            padding: 32px;
        }
        .header { margin-bottom: 24px; }
        .header h1 {
            color: #1a237e;
            font-size: 26px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header p { color: #64748b; margin-top: 6px; font-size: 14px; }
        .message,
        .template-status,
        .info-box {
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.5;
        }
        .message.success,
        .template-status.success { background: #e8f5e9; border-left-color: #22c55e; color: #166534; }
        .message.error { background: #ffebee; border-left-color: #dc2626; color: #991b1b; }
        .template-status { background: #fff7ed; border-left-color: #f97316; }
        .info-box { background: #eff6ff; }
        .template-status strong { display: block; margin-bottom: 4px; }
        .template-status a { color: #1d4ed8; font-weight: 700; text-decoration: none; }
        .placeholder-list {
            display: grid;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 22px;
        }
        .placeholder-list h4 {
            color: #1a237e;
            font-size: 14px;
            margin-top: 8px;
        }
        .placeholder-list h4:first-child { margin-top: 0; }
        .placeholder-item {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #334155;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            padding: 9px 11px;
            overflow-wrap: anywhere;
        }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 8px; }
        input[type="password"] {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 12px;
            font: inherit;
        }
        input:disabled,
        button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }
        input[type="file"] {
            width: 100%;
            border: 2px dashed #93c5fd;
            border-radius: 12px;
            padding: 26px;
            background: #f8fbff;
            cursor: pointer;
        }
        small { display: block; margin-top: 8px; color: #64748b; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn,
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 9px;
            padding: 11px 16px;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary { background: #1a237e; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #1e293b; }
        .help {
            border-top: 1px solid #e2e8f0;
            margin-top: 26px;
            padding-top: 20px;
            color: #475569;
            font-size: 14px;
            line-height: 1.8;
        }
        .help h4 { color: #1a237e; margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fas fa-file-word"></i> Upload Word Template</h1>
        <p>Use one uploaded .docx template for login credentials and voters list Word exports.</p>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($template_exists): ?>
        <div class="template-status success">
            <strong><i class="fas fa-check-circle"></i> Active Template</strong>
            Uploaded: <?php echo h($template_date); ?><br>
            Size: <?php echo number_format($template_size / 1024, 2); ?> KB<br>
            <a href="download_template_example.php"><i class="fas fa-download"></i> Download Current Template</a>
        </div>
    <?php else: ?>
        <div class="template-status">
            <strong><i class="fas fa-info-circle"></i> No Custom Template</strong>
            The system will generate a default Word document until a template is uploaded.
        </div>
    <?php endif; ?>

    <div class="info-box">
        <strong>Placeholders:</strong> Type these exact labels in your Word document where data should appear.
    </div>

    <div class="placeholder-list">
        <h4><i class="fas fa-key"></i> Login Credentials</h4>
        <div class="placeholder-item">{{SCHOOL_NAME}}</div>
        <div class="placeholder-item">{{ELECTION_TITLE}}</div>
        <div class="placeholder-item">{{GENERATED_DATE}}</div>
        <div class="placeholder-item">{{VOTER_ID}}</div>
        <div class="placeholder-item">{{PASSWORD}}</div>
        <div class="placeholder-item">{{FULL_NAME}}</div>
        <div class="placeholder-item">{{GRADE_SECTION}}</div>
        <div class="placeholder-item">{{VOTER_LIST}} - Text list for bulk generated credentials</div>
        <div class="placeholder-item">{{TOTAL_VOTERS}}</div>

        <h4><i class="fas fa-table"></i> Voters List Word Export</h4>
        <div class="placeholder-item">{{VOTER_TABLE}} - Full voters list table</div>
        <div class="placeholder-item">{{VOTER_LIST}} - Text list of voters</div>
        <div class="placeholder-item">{{TOTAL_VOTERS}} - Total voters in the exported list</div>
    </div>

    <?php if ($upload_locked): ?>
        <div class="message error">
            <i class="fas fa-lock"></i>
            Template upload is disabled while an election is ongoing.
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label for="admin_password"><i class="fas fa-key"></i> Confirm Admin Password</label>
            <input type="password" id="admin_password" name="admin_password"
                   autocomplete="current-password" required <?php echo $upload_locked ? 'disabled' : ''; ?>>
            <small>Password confirmation is required for every template replacement.</small>
        </div>
        <div class="form-group">
            <label for="template"><i class="fas fa-file-upload"></i> Upload .docx File</label>
            <input type="file" id="template" name="template" accept=".docx"
                   required <?php echo $upload_locked ? 'disabled' : ''; ?>>
            <small>Maximum file size: 5MB. Active content, external links, macros, and embedded objects are rejected.</small>
        </div>

        <div class="btn-group">
            <button type="submit" class="btn-primary" <?php echo $upload_locked ? 'disabled' : ''; ?>><i class="fas fa-upload"></i> Upload Template</button>
            <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Voters</a>
        </div>
    </form>

    <div class="help">
        <h4><i class="fas fa-lightbulb"></i> Template Tips</h4>
        <ol>
            <li>Create your layout in Microsoft Word.</li>
            <li>Add the placeholders where the generated data should appear.</li>
            <li>For voters list export, place <strong>{{VOTER_TABLE}}</strong> where the table should be inserted.</li>
            <li>If a table placeholder is missing, the system appends the table automatically.</li>
        </ol>
    </div>
</div>
</body>
</html>
