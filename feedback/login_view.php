<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(site_name()) ?> | Login</title>
    <link rel="icon" type="image/png" href="<?= h(site_favicon()) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/feedback.css">
</head>
<body class="legacy-login">
<div class="split-layout">
    <div class="login-bg" style="background-image:linear-gradient(rgba(0,0,0,.4),rgba(8,52,106,.5)),url('<?= h(site_background()) ?>')"></div>
    <section class="visual-side">
        <div class="brand-floating">
            <img src="<?= h(site_logo()) ?>" alt="Organization logo" class="school-logo">
            <div class="brand-text"><h1><?= h(school_name()) ?></h1><p><?= h(school_address()) ?></p></div>
        </div>
    </section>
    <section class="action-side">
        <div class="portal-card">
            <div class="status-pill online">● System Online</div>
            <header class="card-header"><h2>Welcome to the<br><span>Feedback System</span></h2><p>Sign in using your participant code to access the platform.</p></header>
            <div class="feature-grid">
                <div class="feat-box"><span>💬</span> Secure Feedback</div>
                <div class="feat-box"><span>📊</span> Live Insights</div>
                <div class="feat-box"><span>🔐</span> Role-Based</div>
                <div class="feat-box"><span>⚡</span> Real-Time</div>
            </div>
            <?php if ($error): ?><div class="login-alert error"><?= h($error) ?></div><?php elseif ($notice): ?><div class="login-alert"><?= h($notice) ?></div><?php endif; ?>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <div class="form-group"><label>Participant Code / Admin Username</label><div class="input-wrap"><i class="fas fa-user icon-left"></i><input type="text" name="identity" value="<?= h($_POST['identity'] ?? '') ?>" placeholder="Enter your code or username" required autocomplete="username"></div></div>
                <div class="form-group"><label>Password</label><div class="input-wrap"><i class="fas fa-lock icon-left"></i><input type="password" name="password" id="passwordField" placeholder="Enter your password" required autocomplete="current-password"><button type="button" class="toggle-pw" onclick="togglePassword()"><i class="fas fa-eye" id="eyeIcon"></i></button></div></div>
                <button type="submit" class="btn-primary"><i class="fas fa-sign-in-alt"></i> Sign In</button>
            </form>
            <div class="role-hint"><span>🔐 Admin</span> &bull; <span>💬 Participant</span></div>
            <footer class="portal-footer"><p>© <?= date('Y') ?> <?= h(site_name()) ?> Information Systems. All rights reserved.</p></footer>
        </div>
    </section>
</div>
<script>function togglePassword(){const f=document.getElementById('passwordField'),i=document.getElementById('eyeIcon');f.type=f.type==='password'?'text':'password';i.className=f.type==='password'?'fas fa-eye':'fas fa-eye-slash'}</script>
</body></html>
