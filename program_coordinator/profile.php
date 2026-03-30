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

$profileFirstName = trim((string)($profile['first_name'] ?? ''));
$profileMiddleName = trim((string)($profile['middle_name'] ?? ''));
$profileLastName = trim((string)($profile['last_name'] ?? ''));
$profilePrefix = trim((string)($profile['prefix'] ?? ''));
$profileSuffix = trim((string)($profile['suffix'] ?? ''));
$profileProgram = trim((string)($profile['program'] ?? ''));
$profileEmail = trim((string)($profile['adviser_email'] ?? ''));

$profileDisplayName = trim(implode(' ', array_filter([
    $profilePrefix,
    $profileFirstName,
    $profileMiddleName,
    $profileLastName,
    $profileSuffix,
])));

if ($profileDisplayName === '') {
    $profileDisplayName = html_entity_decode($coordinatorName, ENT_QUOTES, 'UTF-8');
}

$initialSource = trim($profileFirstName . ' ' . $profileLastName);
if ($initialSource === '') {
    $initialSource = $profileDisplayName;
}
$initialParts = preg_split('/\s+/', trim($initialSource)) ?: [];
$profileInitials = '';
foreach ($initialParts as $part) {
    if ($part === '') {
        continue;
    }
    $profileInitials .= strtoupper(substr($part, 0, 1));
    if (strlen($profileInitials) >= 2) {
        break;
    }
}
if ($profileInitials === '') {
    $profileInitials = 'PC';
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
        :root {
            --pc-green-900: #173f1a;
            --pc-green-800: #206018;
            --pc-green-700: #2c7c30;
            --pc-green-600: #3a9640;
            --pc-green-100: #eef7ee;
            --pc-gold-400: #d9e441;
            --pc-surface: rgba(255,255,255,0.94);
            --pc-surface-strong: #ffffff;
            --pc-border: rgba(28, 82, 31, 0.12);
            --pc-text: #1f2a21;
            --pc-muted: #627164;
            --pc-shadow: 0 18px 45px rgba(24, 54, 27, 0.12);
            --pc-shadow-soft: 0 10px 24px rgba(24, 54, 27, 0.08);
        }
        body {
            background:
                radial-gradient(circle at top left, rgba(217, 228, 65, 0.12), transparent 24%),
                linear-gradient(160deg, #f5faf4 0%, #eef4ec 42%, #f7faf6 100%);
            font-family: "Poppins", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 45px;
            color: var(--pc-text);
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
        .profile-layout {
            width: 100%;
            max-width: 1120px;
            display: grid;
            gap: 22px;
        }
        .profile-hero {
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            padding: 28px;
            background:
                linear-gradient(135deg, rgba(23, 63, 26, 0.98) 0%, rgba(39, 112, 44, 0.95) 58%, rgba(74, 153, 72, 0.92) 100%);
            box-shadow: var(--pc-shadow);
            color: #fff;
        }
        .profile-hero::after {
            content: "";
            position: absolute;
            inset: auto -40px -60px auto;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.02) 70%, transparent 72%);
        }
        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(260px, 0.9fr);
            gap: 24px;
            align-items: center;
        }
        .hero-copy h1 {
            font-size: 32px;
            line-height: 1.1;
            margin-bottom: 10px;
            letter-spacing: -0.03em;
        }
        .hero-copy p {
            max-width: 620px;
            color: rgba(255,255,255,0.86);
            font-size: 14px;
            line-height: 1.65;
        }
        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.12);
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            backdrop-filter: blur(6px);
        }
        .hero-badge strong {
            color: var(--pc-gold-400);
            font-weight: 700;
        }
        .profile-summary {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 14px;
            padding: 18px;
            border-radius: 20px;
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.18);
            backdrop-filter: blur(8px);
        }
        .summary-top {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .avatar {
            width: 70px;
            height: 70px;
            border-radius: 22px;
            display: grid;
            place-items: center;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.08em;
            background: linear-gradient(145deg, rgba(217, 228, 65, 0.9), rgba(255,255,255,0.92));
            color: #214922;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.45);
        }
        .summary-meta h2 {
            font-size: 20px;
            margin-bottom: 4px;
            line-height: 1.2;
        }
        .summary-meta p {
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.74);
        }
        .summary-list {
            display: grid;
            gap: 10px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.12);
            font-size: 13px;
        }
        .summary-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .summary-label {
            color: rgba(255,255,255,0.68);
        }
        .summary-value {
            text-align: right;
            font-weight: 600;
            color: #fff;
            word-break: break-word;
        }
        .page-card {
            background: var(--pc-surface);
            border: 1px solid var(--pc-border);
            border-radius: 24px;
            box-shadow: var(--pc-shadow-soft);
            padding: 28px;
            width: 100%;
            backdrop-filter: blur(8px);
        }
        .form-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 22px;
        }
        .form-toolbar h2 {
            color: var(--pc-green-900);
            font-size: 28px;
            letter-spacing: -0.03em;
            margin-bottom: 6px;
        }
        .form-toolbar p {
            color: var(--pc-muted);
            font-size: 14px;
            line-height: 1.6;
            max-width: 640px;
        }
        .toolbar-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: var(--pc-green-100);
            color: var(--pc-green-900);
            border: 1px solid rgba(44, 124, 48, 0.12);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        .form-shell {
            display: grid;
            gap: 22px;
        }
        .form-section {
            background: var(--pc-surface-strong);
            border: 1px solid rgba(23, 63, 26, 0.08);
            border-radius: 18px;
            padding: 22px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
        }
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin: 0 0 18px;
            padding: 0 0 14px;
            border-bottom: 1px solid #e8efe7;
        }
        .section-title strong {
            display: block;
            font-size: 17px;
            color: #1f2b20;
            letter-spacing: -0.02em;
        }
        .section-title span {
            display: block;
            margin-top: 4px;
            color: var(--pc-muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .section-chip {
            padding: 8px 11px;
            border-radius: 999px;
            background: #f4f8f3;
            color: var(--pc-green-700);
            border: 1px solid #dce9da;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 22px;
        }
        .field-span-2 {
            grid-column: 1 / -1;
        }
        .form-group {
            margin-bottom: 0;
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
            padding: 13px 14px;
            border: 1px solid #cad9c7;
            border-radius: 14px;
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
        .form-note {
            margin-top: 4px;
            color: var(--pc-muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .form-actions {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding-top: 6px;
        }
        .actions-copy {
            color: var(--pc-muted);
            font-size: 12px;
            line-height: 1.55;
            max-width: 420px;
        }
        .btn {
            padding: 13px 24px;
            border-radius: 14px;
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
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-weight: 600;
            font-size: 13px;
            border-width: 1px;
            border-style: solid;
            box-shadow: 0 8px 18px rgba(22, 44, 23, 0.05);
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
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .content { margin: 70px 10px 10px; }
            .content.expanded { margin-left: 10px; }
            .profile-layout {
                gap: 16px;
            }
            .hero-grid {
                grid-template-columns: 1fr;
            }
            .profile-hero,
            .page-card {
                padding: 18px;
                border-radius: 18px;
            }
            .form-toolbar {
                flex-direction: column;
                margin-bottom: 18px;
            }
            .hero-copy h1,
            .form-toolbar h2 {
                font-size: 22px;
            }
            .field-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .field-span-2 {
                grid-column: auto;
            }
            .form-actions {
                flex-direction: column;
                align-items: stretch;
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
        <div class="profile-layout">
            <section class="profile-hero">
                <div class="hero-grid">
                    <div class="hero-copy">
                        <h1>Profile Settings</h1>
                        <p>Keep your coordinator account details accurate and up to date. This information is used across advising, student management, and curriculum workflows.</p>
                        <div class="hero-badges">
                            <div class="hero-badge"><strong>Role</strong> Program Coordinator</div>
                            <div class="hero-badge"><strong>Status</strong> Active account</div>
                            <div class="hero-badge"><strong>Workspace</strong> Academic administration</div>
                        </div>
                    </div>
                    <div class="profile-summary">
                        <div class="summary-top">
                            <div class="avatar"><?php echo htmlspecialchars($profileInitials); ?></div>
                            <div class="summary-meta">
                                <h2><?php echo htmlspecialchars($profileDisplayName); ?></h2>
                                <p>Program Coordinator Profile</p>
                            </div>
                        </div>
                        <div class="summary-list">
                            <div class="summary-item">
                                <span class="summary-label">Username</span>
                                <span class="summary-value"><?php echo htmlspecialchars($profile['username'] ?? $username); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Program</span>
                                <span class="summary-value"><?php echo htmlspecialchars($profileProgram !== '' ? $profileProgram : 'Not set'); ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Email</span>
                                <span class="summary-value"><?php echo htmlspecialchars($profileEmail !== '' ? $profileEmail : 'Not provided'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="page-card">
                <div class="form-toolbar">
                    <div>
                        <h2>Update Profile</h2>
                        <p>Review your personal and account details below. Changes save directly to your coordinator account and take effect immediately after a successful update.</p>
                    </div>
                    <div class="toolbar-pill">Professional Profile</div>
                </div>
                
                <?php if (!empty($successMessage)): ?>
                    <div class="alert alert-success"><?php echo $successMessage; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($errorMessage)): ?>
                    <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-shell">
                        <section class="form-section">
                            <div class="section-title">
                                <div>
                                    <strong>Personal Information</strong>
                                    <span>Maintain the formal name and identity details used across coordinator-facing modules.</span>
                                </div>
                                <div class="section-chip">Identity</div>
                            </div>

                            <div class="field-grid">
                                <div class="form-group">
                                    <label for="prefix">Prefix</label>
                                    <input type="text" id="prefix" name="prefix" value="<?php echo htmlspecialchars($profile['prefix'] ?? ''); ?>" placeholder="e.g., Dr., Engr.">
                                    <div class="form-note">Optional honorific or title.</div>
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
                                
                                <div class="form-group">
                                    <label for="first_name">First Name *</label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($profile['first_name'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="middle_name">Middle Name</label>
                                    <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($profile['middle_name'] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="last_name">Last Name *</label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($profile['last_name'] ?? ''); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="suffix">Suffix</label>
                                    <input type="text" id="suffix" name="suffix" value="<?php echo htmlspecialchars($profile['suffix'] ?? ''); ?>" placeholder="e.g., Jr., Sr., III">
                                </div>

                                <div class="form-group field-span-2">
                                    <label for="sex">Sex</label>
                                    <select id="sex" name="sex">
                                        <option value="">Select Sex</option>
                                        <option value="Male" <?php echo ($profile['sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($profile['sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="form-section">
                            <div class="section-title">
                                <div>
                                    <strong>Account & Access</strong>
                                    <span>These fields support notifications, identity display, and secure access to the coordinator panel.</span>
                                </div>
                                <div class="section-chip">Account</div>
                            </div>

                            <div class="field-grid">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" value="<?php echo htmlspecialchars($profile['username'] ?? $username); ?>" readonly>
                                    <small>Username is fixed and cannot be changed.</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="adviser_email">Email Address</label>
                                    <input type="email" id="adviser_email" name="adviser_email" value="<?php echo htmlspecialchars($profile['adviser_email'] ?? ''); ?>" placeholder="name@example.com">
                                    <div class="form-note">Used for system messages and coordinator notifications.</div>
                                </div>

                                <div class="form-group">
                                    <label for="program">Program</label>
                                    <input type="text" id="program" name="program" value="<?php echo htmlspecialchars($profile['program'] ?? ''); ?>" placeholder="Assigned academic program">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" placeholder="Leave blank to keep current password">
                                    <small>Only fill this in when you want to change your password.</small>
                                </div>
                            </div>
                        </section>
                    </div>
                    
                    <div class="form-actions">
                        <div class="actions-copy">
                            Save once you've reviewed your details. Profile updates are applied immediately to your coordinator account and dashboard identity.
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </section>
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
