<?php
// includes/header.php
if (!isset($page_title)) {
    $page_title = 'Voting Management System';
}

$is_admin = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false);
$is_voter = (strpos($_SERVER['PHP_SELF'], '/voter/') !== false);

if (isset($root_path)) {
    $script_dir   = dirname(realpath($_SERVER['SCRIPT_FILENAME']));
    $root_abs     = realpath($root_path);
    $script_parts = explode(DIRECTORY_SEPARATOR, rtrim($script_dir, DIRECTORY_SEPARATOR));
    $root_parts   = explode(DIRECTORY_SEPARATOR, rtrim($root_abs,   DIRECTORY_SEPARATOR));
    $depth        = count($script_parts) - count($root_parts);
    $prefix       = str_repeat('../', max(0, $depth));
    $css_path     = $prefix . 'assets/css/style.css';
    $responsive_css_path = $prefix . 'assets/css/responsive.css';
} else {
    $css_path = 'assets/css/style.css';
    $responsive_css_path = 'assets/css/responsive.css';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="<?php echo $css_path; ?>">
    <link rel="stylesheet" href="<?php echo $responsive_css_path; ?>">

    <!-- Apply saved theme BEFORE render (prevents flash) -->
    <script>
        (function() {
            if (localStorage.getItem('voting-system-theme') === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <?php if (isset($extra_css)): ?>
        <style><?php echo $extra_css; ?></style>
    <?php endif; ?>
</head>
<body class="<?php echo isset($body_class) ? $body_class : ''; ?>">

<?php if ($is_admin): ?>
<!-- Hamburger + Overlay -->
<button class="hamburger-menu" id="hamburgerBtn" aria-label="Toggle navigation">
    <span></span><span></span><span></span>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
// ── GLOBAL theme toggle — callable from anywhere ──
function toggleTheme() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('voting-system-theme', 'light');
    } else {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('voting-system-theme', 'dark');
    }
    _updateThemeIcon(!isDark);
}

function _updateThemeIcon(isDark) {
    var btn = document.getElementById('topbarThemeBtn');
    if (btn) btn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
}

// ── Run after DOM is ready ──
document.addEventListener('DOMContentLoaded', function() {
    // Set correct icon on load
    _updateThemeIcon(document.documentElement.getAttribute('data-theme') === 'dark');

    // Attach theme button click
    var themeBtn = document.getElementById('topbarThemeBtn');
    if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

    // ── Hamburger / Sidebar ──
    var hamburger = document.getElementById('hamburgerBtn');
    var overlay   = document.getElementById('sidebarOverlay');
    var sidebar   = document.getElementById('sidebar');
    if (!hamburger || !sidebar) return;

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        hamburger.classList.remove('open');
    }
    function toggleSidebar() {
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            sidebar.classList.add('open');
            overlay.classList.add('show');
            hamburger.classList.add('open');
        }
    }

    hamburger.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', closeSidebar);
    document.querySelectorAll('.sidebar-nav a').forEach(function(l) {
        l.addEventListener('click', function() { if (window.innerWidth <= 768) closeSidebar(); });
    });
    window.addEventListener('resize', function() { if (window.innerWidth > 768) closeSidebar(); });
});
</script>
<?php endif; ?>
