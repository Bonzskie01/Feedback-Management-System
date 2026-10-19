<?php
// includes/footer.php
// Common footer for ALL pages with theme toggle and mobile sidebar
?>
    
    <!-- Floating Theme Toggle (hidden on admin pages with sidebar) -->
    <?php if (!isset($hide_theme_toggle) || !$hide_theme_toggle): ?>
    <button class="floating-theme-toggle" onclick="toggleTheme()" title="Toggle Dark/Light Mode" id="floatingThemeBtn">
        <i class="fas fa-moon" id="themeToggleIcon"></i>
    </button>
    <?php endif; ?>
    
    <script>
        // ============================================
        // THEME FUNCTIONS
        // ============================================
        
        // Initialize theme icons on page load
        (function() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const isDark = currentTheme === 'dark';
            
            // Update floating button icon
            const icon = document.getElementById('themeToggleIcon');
            const btn = document.getElementById('floatingThemeBtn');
            if (icon) {
                icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                if (btn) btn.title = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
            }
            
            // Update sidebar toggle label
            const sidebarLabel = document.querySelector('.theme-toggle-label');
            if (sidebarLabel) {
                sidebarLabel.innerHTML = isDark ? 
                    '<i class="fas fa-sun"></i> Light Mode' : 
                    '<i class="fas fa-moon"></i> Dark Mode';
            }
        })();
        
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const isDark = currentTheme === 'dark';
            
            if (isDark) {
                html.removeAttribute('data-theme');
                localStorage.setItem('voting-system-theme', 'light');
            } else {
                html.setAttribute('data-theme', 'dark');
                localStorage.setItem('voting-system-theme', 'dark');
            }
            
            updateAllToggleIcons(!isDark);
        }
        
        function updateAllToggleIcons(isDark) {
            // Update floating button
            const icon = document.getElementById('themeToggleIcon');
            const btn = document.getElementById('floatingThemeBtn');
            if (icon) {
                icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
                if (btn) btn.title = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
            }
            
            // Update sidebar label
            const sidebarLabel = document.querySelector('.theme-toggle-label');
            if (sidebarLabel) {
                sidebarLabel.innerHTML = isDark ? 
                    '<i class="fas fa-sun"></i> Light Mode' : 
                    '<i class="fas fa-moon"></i> Dark Mode';
            }
        }

        // ============================================
        // MOBILE SIDEBAR TOGGLE (HAMBURGER MENU)
        // ============================================
        
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const hamburger = document.getElementById('hamburgerMenu');
            
            if (sidebar && overlay && hamburger) {
                const isOpen = sidebar.classList.contains('open');
                
                if (isOpen) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                    hamburger.classList.remove('open');
                } else {
                    sidebar.classList.add('open');
                    overlay.classList.add('show');
                    hamburger.classList.add('open');
                }
            }
        }

        function closeSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const hamburger = document.getElementById('hamburgerMenu');
            
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('show');
            if (hamburger) hamburger.classList.remove('open');
        }

        // Close sidebar when clicking a nav link (mobile)
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.sidebar-nav a');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 768) {
                        closeSidebar();
                    }
                });
            });
        });

        // Close sidebar when window resizes to desktop size
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                closeSidebar();
            }
        });

        // Close sidebar with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });
    </script>
    
    <?php if (isset($extra_js)): ?>
        <script><?php echo $extra_js; ?></script>
    <?php endif; ?>
</body>
</html>