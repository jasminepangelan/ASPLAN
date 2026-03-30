<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$coordinatorName = isset($_SESSION['full_name']) ? htmlspecialchars((string)$_SESSION['full_name']) : 'Program Coordinator';
$username = $_SESSION['username'];

$successMessage = '';
$errorMessage = '';
$profile = [];
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/program-coordinator/profile/update',
            array_merge($_POST, [
                'bridge_authorized' => true,
                'username' => $username,
            ])
        );

        if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
            if (!empty($bridgeData['success'])) {
                $successMessage = (string) ($bridgeData['message'] ?? 'Profile updated successfully!');
                if (!empty($bridgeData['full_name'])) {
                    $coordinatorName = htmlspecialchars((string) $bridgeData['full_name']);
                    $_SESSION['full_name'] = (string) $bridgeData['full_name'];
                }
                if (isset($bridgeData['profile']) && is_array($bridgeData['profile'])) {
                    $profile = $bridgeData['profile'];
                }
                $bridgeLoaded = true;
            } else {
                $errorMessage = (string) ($bridgeData['message'] ?? 'Failed to update profile.');
                $bridgeLoaded = true;
            }
        }
    }

    if (!$bridgeLoaded) {
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/program-coordinator/profile/view',
            [
                'bridge_authorized' => true,
                'username' => $username,
            ]
        );

        if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['profile']) && is_array($bridgeData['profile'])) {
            $profile = $bridgeData['profile'];
            $bridgeLoaded = true;
        }
    }
}

if (!$bridgeLoaded) {
    $conn = getDBConnection();
    $conn->set_charset('utf8mb4');

    // Handle profile update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $prefix = trim($_POST['prefix'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $email = trim($_POST['adviser_email'] ?? '');
    $sex = trim($_POST['sex'] ?? '');
    $pronoun = trim($_POST['pronoun'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($first_name) || empty($last_name)) {
        $errorMessage = "First name and last name are required.";
    } else {
        // Build the update query dynamically
        $updateSql = "UPDATE program_coordinator SET 
                      first_name = ?, last_name = ?, middle_name = ?, 
                      prefix = ?, suffix = ?, adviser_email = ?, 
                      sex = ?, pronoun = ?, program = ?";
        
        $params = [$first_name, $last_name, $middle_name, $prefix, $suffix, $email, $sex, $pronoun, $program];
        $types = "sssssssss";
        
        // Include password if provided
        if (!empty($new_password)) {
            $updateSql .= ", password = ?";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            $types .= "s";
        }
        
        $updateSql .= " WHERE username = ?";
        $params[] = $username;
        $types .= "s";
        
        $stmt = $conn->prepare($updateSql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $successMessage = "Profile updated successfully!";
                // Update session variable if name changed
                $newFullName = trim("$first_name $middle_name $last_name $suffix");
                if ($prefix) $newFullName = "$prefix $newFullName";
                $_SESSION['full_name'] = $newFullName;
                $coordinatorName = htmlspecialchars($newFullName);
            } else {
                $errorMessage = "Failed to update profile. Please try again.";
            }
            $stmt->close();
        } else {
            $errorMessage = "Database error. Please contact administrator.";
        }
    }
    }

    // Fetch current profile data
    if (empty($profile)) {
        $stmt = $conn->prepare("SELECT * FROM program_coordinator WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $profile = $result->fetch_assoc();
            }
            $stmt->close();
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile - Program Coordinator</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f2f5f1;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 45px;
        }
        .header {
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: #fff;
            padding: 5px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 45px;
        }
        
        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
        }

        .admin-info {
            font-size: 16px;
            font-weight: 600;
            color: white;
            font-family: 'Segoe UI', Arial, sans-serif;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.15);
            padding: 5px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border-radius: 6px;
            font-size: 18px;
            cursor: pointer;
            margin-right: 10px;
            transition: all 0.2s ease;
        }

        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: inline-flex;
            }
        }
        .sidebar {
            width: 250px;
            height: calc(100vh - 45px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 45px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 999;
        }
        .sidebar.collapsed { transform: translateX(-250px); }
        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
        }
        .sidebar-menu { list-style: none; padding: 6px 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
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
        .sidebar-menu img { width: 20px; height: 20px; filter: brightness(0) invert(1); }
        .menu-group { margin: 8px 0; }
        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            line-height: 1.2;
            letter-spacing: 1px;
        }
        .content {
            margin: 24px 24px 24px 274px;
            transition: margin-left 0.3s ease;
            padding-bottom: 20px;
            display: flex;
            justify-content: center;
        }
        .content.expanded { margin-left: 24px; }
        
        /* Profile Form Styles */
        .page-card {
            background: linear-gradient(180deg, #ffffff 0%, #fcfffc 100%);
            border: 1px solid #dbe5d9;
            border-radius: 16px;
            box-shadow: 0 14px 30px rgba(32, 96, 24, 0.08);
            padding: 28px;
            margin: 0 auto 20px;
            width: 100%;
            max-width: 980px;
        }
        .page-card h2 {
            color: #206018;
            font-size: 28px;
            margin-bottom: 22px;
            border-bottom: 1px solid #e8efe7;
            padding-bottom: 12px;
            letter-spacing: 0.2px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px 22px;
        }
        
        .form-group {
            margin-bottom: 8px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 7px;
            color: #1e4f1b;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.2px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #c8d8c6;
            border-radius: 10px;
            font-size: 14px;
            background: #fcfffb;
            color: #1f2d20;
            transition: border-color 0.25s ease, box-shadow 0.25s ease, background-color 0.25s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #2e7d32;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(46, 125, 50, 0.12);
        }
        
        .form-group input[readonly] {
            background-color: #f2f6f2;
            cursor: not-allowed;
            color: #5f6c60;
            border-color: #d8e2d7;
        }

        .form-group small {
            color: #6c786d;
            font-size: 11px;
            margin-top: 5px;
            display: block;
            line-height: 1.35;
        }
        
        .form-actions {
            margin-top: 30px;
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }
        
        .btn {
            padding: 11px 24px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.25s ease;
            letter-spacing: 0.25px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #2f8a35 0%, #206018 100%);
            color: white;
            box-shadow: 0 8px 16px rgba(32, 96, 24, 0.24);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #379d3d 0%, #256b1f 100%);
            box-shadow: 0 10px 20px rgba(32, 96, 24, 0.3);
            transform: translateY(-2px);
        }
        
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 13px;
            border-width: 1px;
            border-style: solid;
        }
        
        .alert-success {
            background: #edf9ee;
            color: #1f6d27;
            border-color: #cde8cf;
        }
        
        .alert-danger {
            background: #fff1f1;
            color: #b12424;
            border-color: #f2caca;
        }
        
        .section-title {
            grid-column: 1 / -1;
            font-size: 16px;
            color: #1f2b20;
            margin-top: 6px;
            margin-bottom: 4px;
            padding: 6px 0 8px;
            border-bottom: 1px solid #e8efe7;
            letter-spacing: 0.2px;
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .content { margin: 70px 10px 10px; }
            .content.expanded { margin-left: 10px; }
            .page-card {
                padding: 16px;
                border-radius: 12px;
            }
            .page-card h2 {
                font-size: 22px;
            }
            .form-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .form-actions {
                justify-content: stretch;
            }
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info"><?php echo $coordinatorName; ?> | Program Coordinator</div>
    </div>

    <div class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header"><h3>Program Coordinator Panel</h3></div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Modules</div>
                <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum" style="filter: brightness(0) invert(1);"> Curriculum Management</a></li>
                <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers" style="filter: brightness(0) invert(1);"> Adviser Management</a></li>
                <li><a href="list_of_students.php"><img src="../pix/checklist.png" alt="Students" style="filter: brightness(0) invert(1);"> List of Students</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift" style="filter: brightness(0) invert(1);"> Program Shift Requests</a></li>
                <li><a href="profile.php" class="active"><img src="../pix/account.png" alt="Profile" style="filter: brightness(0) invert(1);"> Update Profile</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out" style="filter: brightness(0) invert(1);"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="content" id="mainContent">
        <div class="page-card">
            <h2>Update Profile</h2>
            
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><?php echo $successMessage; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-grid">
                    <h3 class="section-title">Personal Information</h3>
                    
                    <div class="form-group">
                        <label for="prefix">Prefix (Optional)</label>
                        <input type="text" id="prefix" name="prefix" value="<?php echo htmlspecialchars($profile['prefix'] ?? ''); ?>" placeholder="e.g., Dr., Engr.">
                    </div>
                    
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($profile['first_name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="middle_name">Middle Name (Optional)</label>
                        <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($profile['middle_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($profile['last_name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="suffix">Suffix (Optional)</label>
                        <input type="text" id="suffix" name="suffix" value="<?php echo htmlspecialchars($profile['suffix'] ?? ''); ?>" placeholder="e.g., Jr., Sr., III">
                    </div>

                    <div class="form-group">
                        <label for="sex">Sex</label>
                        <select id="sex" name="sex">
                            <option value="">Select Sex</option>
                            <option value="Male" <?php echo ($profile['sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($profile['sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="pronoun">Pronoun</label>
                        <select id="pronoun" name="pronoun">
                            <option value="">Select Pronoun</option>
                            <option value="Mr." <?php echo ($profile['pronoun'] ?? '') === 'Mr.' ? 'selected' : ''; ?>>Mr.</option>
                            <option value="Ms." <?php echo ($profile['pronoun'] ?? '') === 'Ms.' ? 'selected' : ''; ?>>Ms.</option>
                            <option value="Mrs." <?php echo ($profile['pronoun'] ?? '') === 'Mrs.' ? 'selected' : ''; ?>>Mrs.</option>
                        </select>
                    </div>

                    <h3 class="section-title">Account Information</h3>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" value="<?php echo htmlspecialchars($profile['username'] ?? ''); ?>" readonly>
                        <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">Username cannot be changed.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="adviser_email">Email Address</label>
                        <input type="email" id="adviser_email" name="adviser_email" value="<?php echo htmlspecialchars($profile['adviser_email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="program">Program</label>
                        <input type="text" id="program" name="program" value="<?php echo htmlspecialchars($profile['program'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password">
                        <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">Only fill this if you want to change your password.</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.header img');

            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target))) {
                sidebar.classList.add('collapsed');
                const mainContent = document.getElementById('mainContent');
                if (mainContent) {
                    mainContent.classList.add('expanded');
                }
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        });

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            if (window.innerWidth > 768) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            } else {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
        });
    </script>
</body>
</html>
