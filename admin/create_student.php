<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_number = trim($_POST['student_number'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');

    if (empty($student_number) || empty($last_name) || empty($first_name)) {
        $error = 'Student Number, Last Name, and First Name are required.';
    } else {
        $last_name = ucwords(strtolower($last_name));
        $first_name = ucwords(strtolower($first_name));
        $middle_name = $middle_name !== '' ? ucwords(strtolower($middle_name)) : null;

        try {
            $conn = getDBConnection();
            
            // Check if student already exists
            $stmt = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ?");
            $stmt->execute([$student_number]);
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Student account with this Student Number already exists.';
            } else {
                $stmt->close();
                
                // Insert new student
                // We use standard default values for mandatory fields.
                $hashed_password = password_hash('12345678', PASSWORD_BCRYPT);
                $status = 'approved';
                $registration_classification = 'Old';
                
                $stmt = $conn->prepare("
                    INSERT INTO student_info (
                        student_number, last_name, first_name, middle_name, 
                        password, status, registration_classification, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                if ($stmt->execute([
                    $student_number, $last_name, $first_name, $middle_name,
                    $hashed_password, $status, $registration_classification
                ])) {
                    $success = true;
                } else {
                    $error = 'Failed to create student account.';
                }
            }
            if (isset($stmt)) $stmt->close();
        } catch (Exception $e) {
            $error = 'Database error occurred: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Student Account</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            min-height: 100vh;
        }
        
        /* Header styling */
        .main-header {
            width: 100%;
            background: linear-gradient(135deg, #206018 0%, #2d7a2d 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 15px;
            height: 45px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .main-header > div:first-child {
            display: flex;
            align-items: center;
        }

        .main-header img {
            height: 32px;
            margin-right: 10px;
            cursor: pointer;
        }

        .main-header span {
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: 0.6px;
        }

        .admin-info {
            font-size: 16px;
            font-weight: 600;
            color: white;
            background: rgba(255, 255, 255, 0.15);
            padding: 5px 15px;
            border-radius: 8px;
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

        .sidebar.collapsed {
            transform: translateX(-250px);
        }

        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            color: white;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
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

        .main-content {
            padding-top: 80px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            min-height: 100vh;
            margin-left: 250px;
            width: calc(100% - 250px);
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
            width: 100%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid rgba(0,0,0,0.05);
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-container h2 {
            margin-bottom: 25px;
            font-size: 24px;
            color: #1565C0;
            font-weight: 700;
            text-align: center;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
            color: #444;
        }
        
        .form-group label span.required {
            color: #e53935;
        }

        .form-group input[type="text"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #1976D2;
            box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }
        
        .button-group button, .button-group a {
            flex: 1;
            padding: 12px;
            text-align: center;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #1976D2 0%, #1565C0 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
        }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .btn-back {
            background: #e0e0e0;
            color: #333;
        }
        .btn-back:hover { background: #d5d5d5; }
        
        .alert-error {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c62828;
        }
    </style>
</head>
<body>

    <div class="main-header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info">Admin Panel</div>
    </div>

    <?php
    $activeAdminPage = 'account_module';
    $adminSidebarCollapsed = false;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <div class="main-content" id="mainContent">
        <div class="form-container">
            <h2>Create Student Account</h2>
            
            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label>Student Number <span class="required">*</span></label>
                    <input type="number" name="student_number" placeholder="e.g. 202312345" required>
                </div>
                
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" placeholder="e.g. Dela Cruz" required>
                </div>

                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="first_name" placeholder="e.g. Juan" required>
                </div>

                <div class="form-group">
                    <label>Middle Name/Initial <span style="font-weight: normal; color: #888;">(Optional)</span></label>
                    <input type="text" name="middle_name" placeholder="e.g. M">
                </div>

                <div class="button-group">
                    <a href="account_module.php" class="btn-back">Cancel</a>
                    <button type="submit" class="btn-submit">Create Account</button>
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
            const logo = document.querySelector('.main-header img');

            if (window.innerWidth <= 768 &&
                sidebar && !sidebar.contains(event.target) &&
                (!logo || !logo.contains(event.target))) {
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
    <?php if ($success): ?>
    <script>
        Swal.fire({
            title: 'Success!',
            text: 'Student account has been created successfully. The default password is 12345678.',
            icon: 'success',
            confirmButtonColor: '#1976D2'
        }).then((result) => {
            window.location.href = 'account_module.php';
        });
    </script>
    <?php endif; ?>
</body>
</html>
