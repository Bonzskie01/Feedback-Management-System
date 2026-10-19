<?php
// voter/thank_you.php
$root_path = dirname(__FILE__) . '/..';

require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

$status = isset($_GET['status']) ? $_GET['status'] : 'already_voted';

switch ($status) {
    case 'success':
        $icon_class = 'fas fa-check-circle';
        $title_color = '#2e7d32';
        $message_title = 'Vote Successfully Cast!';
        $message_text = 'Thank you for exercising your right to vote.';
        break;
    case 'auto_closed':
        $icon_class = 'fas fa-clock';
        $title_color = '#e65100';
        $message_title = 'Election Period Ended';
        $message_text = 'The election has been automatically closed. Thank you for participating.';
        break;
    default:
        $icon_class = 'fas fa-vote-yea';
        $title_color = '#e65100';
        $message_title = "You've Already Voted!";
        $message_text = 'Thank you for using our system.';
        break;
}

$page_title = 'Thank You - Voting System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?></title>
    <link rel="icon" type="image/png" href="../<?php echo site_favicon(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.3s, color 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        :root {
            --bg-body: #f0f4ff;
            --bg-card: rgba(255,255,255,0.96);
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --border-color: rgba(0,0,0,0.1);
            --shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        [data-theme="dark"] {
            --bg-body: #060d1f;
            --bg-card: #0f1e4a;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --border-color: rgba(255,255,255,0.1);
            --shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('../<?php echo site_background(); ?>') center/cover no-repeat;
            z-index: 0;
        }
        .bg-layer::before {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(240,244,255,0.7);
        }
        [data-theme="dark"] .bg-layer::before {
            background: rgba(6,13,31,0.85);
        }
        .container {
            position: relative;
            z-index: 1;
            max-width: 500px;
            width: 100%;
            background: var(--bg-card);
            border-radius: 24px;
            box-shadow: var(--shadow);
            text-align: center;
            padding: 40px 30px;
            backdrop-filter: blur(10px);
            border: 1px solid var(--border-color);
        }
        .icon {
            font-size: 72px;
            margin-bottom: 20px;
            color: <?php echo $title_color; ?>;
        }
        h1 {
            font-size: 28px;
            margin-bottom: 15px;
            font-weight: 700;
        }
        .message {
            color: var(--text-secondary);
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        .info-box {
            background: rgba(59,130,246,0.1);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 30px;
            text-align: left;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border-left: 3px solid #3b82f6;
        }
        .info-box i {
            color: #3b82f6;
            font-size: 18px;
            margin-top: 2px;
        }
        .info-box span {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 28px;
            background: #1a237e;
            color: white;
            text-decoration: none;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background: #283593;
            transform: translateY(-2px);
        }
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 40px;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
            transition: all 0.2s;
            z-index: 100;
        }
        .theme-toggle:hover {
            background: var(--bg-body);
        }
        @media (max-width: 480px) {
            .container { padding: 30px 20px; }
            h1 { font-size: 22px; }
        }
    </style>
    <script>
        (function() {
            if (localStorage.getItem('vms-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
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
    </script>
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>
    <div class="bg-layer"></div>
    <button class="theme-toggle" onclick="toggleTheme()">
        <i class="fas fa-moon"></i> <span>Dark/Light</span>
    </button>
    <div class="container">
        <div class="icon"><i class="<?php echo h($icon_class); ?>"></i></div>
        <h1 style="color: <?php echo $title_color; ?>;"><?php echo h($message_title); ?></h1>
        <div class="message"><?php echo h($message_text); ?></div>
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <span>Your vote has been securely recorded. Your account is now locked - you cannot vote again with the same credentials.</span>
        </div>
        <a href="../index.php" class="btn"><i class="fas fa-home"></i> Return to Home</a>
    </div>
</body>
</html>
