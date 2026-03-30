<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Coordinator Input Form</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            margin: 0;
            padding: 0;
            color: #333;
            overflow-x: hidden;
        }
        
        /* Header styling */
        .header {
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: #fff;
            padding: 6px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(32, 96, 24, 0.3);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
        }
        
        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
        }

        /* Sidebar styling */
        .sidebar {
            width: 250px;
            height: calc(100vh - 46px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 44px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 20px;
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .sidebar-menu {
    list-style: none;
    padding: 6px 0;
    margin: 0;
}

        .sidebar-menu li {
    margin: 0;
}

        .sidebar-menu a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 20px;
    color: #ffffff;
    text-decoration: none;
    transition: all 0.3s ease;
    font-size: 15px;
    line-height: 1.2;
}

        .sidebar-menu a:hover {
            background-color: rgba(255,255,255,0.1);
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background-color: rgba(255,255,255,0.15);
            border-left-color: #4CAF50;
        }

        .sidebar-menu img {
    width: 20px;
    height: 20px;
    margin-right: 0;
    filter: brightness(0) invert(1);
}

        .menu-group {
    margin: 8px 0;
}

        .menu-group-title {
    padding: 6px 20px 2px 20px;
    color: rgba(255, 255, 255, 0.7);
    font-size: 15px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
}

        /* Main content styling */
        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 46px);
            background-color: #f5f5f5;
            width: calc(100% - 250px);
            overflow-x: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 30px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            box-sizing: border-box;
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100%;
        }
        
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px 40px;
            border-radius: 15px;
            margin-top: 70px;
            width: 100%;
            max-width: 650px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(32, 96, 24, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(32, 96, 24, 0.1);
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-container h2 {
            margin-bottom: 25px;
            margin-top: 0;
            font-size: 24px;
            color: #206018;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }
        .form-group label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }
        .program-help {
            margin-top: 6px;
            font-size: 12px;
            color: #556;
        }
        .program-checkbox-list {
            border: 1px solid #ccc;
            border-radius: 8px;
            background: #fff;
            max-height: 250px;
            overflow-y: auto;
            padding: 10px 12px;
            display: grid;
            gap: 8px;
        }
        .program-checkbox-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 14px;
            line-height: 1.35;
            color: #2f3a2f;
        }
        .program-checkbox-item input[type="checkbox"] {
            margin-top: 2px;
            accent-color: #2e7d32;
            flex: 0 0 auto;
        }
        .button-group {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 20px;
        }
        .button-group button {
            flex: 1;
            padding: 12px 20px;
            border: none;
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: white;
            cursor: pointer;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
        }
        
        .button-group button:hover {
            background: linear-gradient(135deg, #1a4f14 0%, #2a7a20 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(32, 96, 24, 0.4);
        }
        
        .back-btn {
            display: none; /* Hide since we have sidebar navigation */
        }

        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 44px;
            left: 0;
            width: 100%;
            height: calc(100vh - 44px);
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* Responsive design */
        @media (max-width: 1280px) {
            .sidebar {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                transform: translateX(-100%) !important;
                z-index: 1000;
            }
            
            .sidebar.active {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                transform: translateX(0) !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .menu-toggle {
                display: block;
            }
        }

        .menu-toggle {
            display: inline-flex;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
        }
    
        /* Sidebar normalization: consistent spacing and interaction across admin pages */
        .sidebar-menu {
            list-style: none;
            padding: 6px 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #ffffff;
            text-decoration: none;
            line-height: 1.2;
            font-size: 15px;
            border-left: 4px solid transparent;
            transition: all 0.25s ease;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.10);
            padding-left: 25px;
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: #4CAF50;
        }

        .sidebar-menu img {
            width: 20px;
            height: 20px;
            margin-right: 0;
            flex: 0 0 20px;
            filter: brightness(0) invert(1);
        }

        .menu-group {
            margin: 8px 0;
        }

        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            line-height: 1.2;
            letter-spacing: 1px;
        }

        .notification {
            position: fixed;
            top: 58px;
            right: 16px;
            z-index: 2000;
            min-width: 280px;
            max-width: 420px;
            padding: 12px 16px;
            border-radius: 10px;
            color: #fff;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.18);
            font-size: 14px;
            font-weight: 600;
            opacity: 0;
            transform: translateY(-8px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .notification.show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .notification.success {
            background: linear-gradient(135deg, #1e7f34 0%, #2e9b45 100%);
        }

        .notification.error {
            background: linear-gradient(135deg, #b72b2b 0%, #d64545 100%);
        }

        .notification.info {
            background: linear-gradient(135deg, #2b5db7 0%, #3f79de 100%);
        }
    </style>
</head>
<body>
    <div id="notification" class="notification" role="status" aria-live="polite"></div>

    <!-- Header -->
    <div class="header">
        <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
        <img src="../img/cav.png" alt="CvSU Logo" style="cursor: pointer;" onclick="toggleSidebar()">
        <span style="color: #d9e441;">ASPLAN</span>
    </div>

    <!-- Sidebar Navigation -->
    <?php
    $activeAdminPage = '';
    $adminSidebarCollapsed = false;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <script>
        // Immediately hide sidebar on mobile devices
        (function() {
            const isMobile = window.innerWidth <= 1280 || 
                            ('ontouchstart' in window) || 
                            /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            const sidebar = document.getElementById('sidebar');
            
            if (isMobile) {
                sidebar.style.setProperty('display', 'none', 'important');
                sidebar.style.setProperty('visibility', 'hidden', 'important');
                sidebar.style.setProperty('opacity', '0', 'important');
                sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
            } else {
                sidebar.style.removeProperty('display');
                sidebar.style.removeProperty('visibility');
                sidebar.style.removeProperty('opacity');
                sidebar.style.removeProperty('transform');
            }
        })();
    </script>
    <div class="main-content">
        <div class="form-container">
            <h2>PROGRAM COORDINATOR INPUT FORM</h2>
            <form id="programCoordinatorForm" action="../handlers/program_coordinator_connection.php" method="post">
                <div class="form-group">
                    <label for="last-name">Last Name</label>
                    <input type="text" id="last-name" name="last_name" placeholder="ex. Dela Cruz" required>
                </div>
                <div class="form-group">
                    <label for="first-name">First Name</label>
                    <input type="text" id="first-name" name="first_name" placeholder="ex. Juan" required>
                </div>
                <div class="form-group">
                    <label for="middle-name">Middle Name</label>
                    <input type="text" id="middle-name" name="middle_name" placeholder="ex. Garcia (Optional)">
                </div>
                <div class="form-group">
                    <label for="username">Preferred Username</label>
                    <input type="text" id="username" name="username" placeholder="ex. juandelacruz" required>
                </div>
                <div class="form-group">
                    <label for="password">Preferred Password</label>
                    <input type="password" id="password" name="password" placeholder="ex. @juandelacruz123" required>
                </div>
                <div class="form-group">
                    <label for="sex">Sex</label>
                    <select id="sex" name="sex" required>
                        <option value="">Select</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="pronoun">Prefixes/Suffixes</label>
                    <select id="pronoun" name="pronoun" required>
                        <option value="">Select</option>
                        <option value="Mr.">Mr.</option>
                        <option value="Ms.">Ms.</option>
                        <option value="Mrs.">Mrs.</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="program">Program</label>
                    <div id="program" class="program-checkbox-list" role="group" aria-label="Program list">
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Computer Science"> Bachelor of Science in Computer Science</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Information Technology"> Bachelor of Science in Information Technology</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Computer Engineering"> Bachelor of Science in Computer Engineering</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Industrial Technology"> Bachelor of Science in Industrial Technology</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Hospitality Management"> Bachelor of Science in Hospitality Management</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Business Administration - Major in Marketing Management"> Bachelor of Science in Business Administration - Major in Marketing Management</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Science in Business Administration - Major in Human Resource Management"> Bachelor of Science in Business Administration - Major in Human Resource Management</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Secondary Education major in English"> Bachelor of Secondary Education major in English</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Secondary Education major Math"> Bachelor of Secondary Education major Math</label>
                        <label class="program-checkbox-item"><input type="checkbox" name="program[]" value="Bachelor of Secondary Education major in Science"> Bachelor of Secondary Education major in Science</label>
                    </div>
                    <div class="program-help">Select one or more programs.</div>
                </div>
                <div class="button-group">
                    <button type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Helper function to detect mobile devices
        function isMobileDevice() {
            return window.innerWidth <= 1280 || 
                   ('ontouchstart' in window) || 
                   /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const isActive = sidebar.classList.contains('active');
            
            if (isMobileDevice()) {
                if (isActive) {
                    // Hide sidebar
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    sidebar.style.setProperty('display', 'none', 'important');
                    sidebar.style.setProperty('visibility', 'hidden', 'important');
                    sidebar.style.setProperty('opacity', '0', 'important');
                    sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
                } else {
                    // Show sidebar
                    sidebar.classList.add('active');
                    overlay.classList.add('active');
                    sidebar.style.setProperty('display', 'block', 'important');
                    sidebar.style.setProperty('visibility', 'visible', 'important');
                    sidebar.style.setProperty('opacity', '1', 'important');
                    sidebar.style.setProperty('transform', 'translateX(0)', 'important');
                }
            } else {
                // Desktop: toggle collapsed class
                sidebar.classList.toggle('collapsed');
                document.querySelector('.main-content').classList.toggle('expanded');
            }
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const menuToggle = document.querySelector('.menu-toggle');
            const logo = document.querySelector('.header img');
            
            if (isMobileDevice() && 
                sidebar.classList.contains('active') &&
                !sidebar.contains(event.target) && 
                event.target !== menuToggle &&
                event.target !== logo) {
                
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                sidebar.style.setProperty('display', 'none', 'important');
                sidebar.style.setProperty('visibility', 'hidden', 'important');
                sidebar.style.setProperty('opacity', '0', 'important');
                sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
            }
        });

        // Handle responsive behavior
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (!isMobileDevice()) {
                // Desktop: clear mobile styles
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                sidebar.style.removeProperty('display');
                sidebar.style.removeProperty('visibility');
                sidebar.style.removeProperty('opacity');
                sidebar.style.removeProperty('transform');
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            } else {
                // Mobile: ensure sidebar is hidden
                if (!sidebar.classList.contains('active')) {
                    sidebar.style.setProperty('display', 'none', 'important');
                    sidebar.style.setProperty('visibility', 'hidden', 'important');
                    sidebar.style.setProperty('opacity', '0', 'important');
                    sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
                }
            }
        });

        let notificationTimer = null;
        function showNotification(message, type) {
            const notification = document.getElementById('notification');
            if (!notification) {
                return;
            }

            notification.classList.remove('success', 'error', 'info', 'show');
            notification.textContent = message;
            notification.classList.add(type || 'info');

            if (notificationTimer) {
                clearTimeout(notificationTimer);
            }

            requestAnimationFrame(function() {
                notification.classList.add('show');
            });

            notificationTimer = setTimeout(function() {
                notification.classList.remove('show');
            }, 3500);
        }

        document.getElementById('programCoordinatorForm').onsubmit = function(e) {
            e.preventDefault();

            const selectedPrograms = document.querySelectorAll('input[name="program[]"]:checked');
            if (selectedPrograms.length === 0) {
                showNotification('Please select at least one program.', 'info');
                return false;
            }
            
            var formData = new FormData(this);
            
            fetch('../handlers/program_coordinator_connection.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    showNotification(data.message, 'success');
                    document.getElementById('programCoordinatorForm').reset();
                } else {
                    showNotification(data.message || 'Unable to save the account at this time.', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred while saving the data.', 'error');
            });
            
            return false;
        };
    </script>

</body>
</html>








