<?php
// Adviser view of student profile (editable)
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';
require_once __DIR__ . '/../includes/student_profile_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

elsInfo('Adviser student profile view/edit', [], 'adviser_student_profile');

// Check if adviser is logged in
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

// Get adviser name for header display
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

// Get student_id from URL parameter
if (!isset($_GET['student_id'])) {
    echo "<script>alert('No student ID provided.'); window.history.back();</script>";
    exit();
}
$student_id = $_GET['student_id'];

// Database connection
$conn = getDBConnection();

// Handle form submission for profile update
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    elsInfo('Adviser profile update initiated', ['student_id' => $student_id], 'adviser_student_profile');
    
    // Validate required fields
    $requiredFields = ['last_name', 'first_name', 'email', 'contact_no', 'address', 'admission_date'];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        $message = 'Please fill in all required fields.';
        $message_type = 'error';
        elsWarning('Adviser profile update: missing required fields', ['student_id' => $student_id, 'missing' => $missingFields], 'adviser_student_profile');
    } else {
        // Validate profile fields
        $validationResult = spsValidateProfileUpdate($conn, $_POST);
        if (!$validationResult['valid']) {
            $message = $validationResult['error'];
            $message_type = 'error';
            elsWarning('Adviser profile update validation failed', ['student_id' => $student_id, 'error' => $validationResult['error']], 'adviser_student_profile');
        } else {
            $bridgeHandled = false;
            $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

            if ($useLaravelBridge) {
                $payloadFields = $_POST;
                $payloadFields['profile_context'] = 'adviser';

                $bridgeData = null;
                if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
                    $bridgeData = postLaravelMultipartBridge(
                        'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                        $payloadFields,
                        [
                            'picture' => [
                                'path' => (string) $_FILES['picture']['tmp_name'],
                                'name' => (string) $_FILES['picture']['name'],
                                'mime' => (string) ($_FILES['picture']['type'] ?? 'application/octet-stream'),
                            ],
                        ]
                    );
                } else {
                    $bridgeData = postLaravelJsonBridge(
                        'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                        $payloadFields
                    );
                }

                if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
                    $bridgeHandled = true;
                    if (!empty($bridgeData['success'])) {
                        if (!empty($bridgeData['warning'])) {
                            $message = (string) ($bridgeData['message'] ?? 'Profile updated with warning.');
                            $message_type = 'warning';
                        } else {
                            $message = (string) ($bridgeData['message'] ?? 'Student profile updated successfully!');
                            $message_type = 'success';
                        }

                        elsInfo('Adviser profile update successful via Laravel', [
                            'student_id' => $student_id,
                            'picture_updated' => !empty($bridgeData['picture_path']),
                            'warning' => !empty($bridgeData['warning']),
                        ], 'adviser_student_profile');
                    } else {
                        $message = (string) ($bridgeData['message'] ?? 'Error updating profile.');
                        $message_type = 'error';
                        elsWarning('Adviser profile update failed via Laravel', [
                            'student_id' => $student_id,
                            'error' => $message,
                        ], 'adviser_student_profile');
                    }
                }
            }

            if (!$bridgeHandled) {
                // Update profile
                $updateResult = spsUpdateStudentProfile($conn, $student_id, $validationResult['validated_fields']);
                if (!$updateResult['success']) {
                    $message = 'Error updating profile: ' . $updateResult['error'];
                    $message_type = 'error';
                    elsError('Adviser profile update failed', ['student_id' => $student_id, 'error' => $updateResult['error']], 'adviser_student_profile');
                } else {
                    // Handle picture upload if provided
                    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
                        $pictureResult = spsUpdateProfilePicture($student_id, $_FILES['picture'], $conn);
                        if (!$pictureResult['success']) {
                            $message = 'Profile updated (with warning: ' . $pictureResult['error'] . ')';
                            $message_type = 'warning';
                            elsWarning('Adviser picture upload failed', ['student_id' => $student_id, 'error' => $pictureResult['error']], 'adviser_student_profile');
                        } else {
                            $message = 'Student profile updated successfully!';
                            $message_type = 'success';
                            elsInfo('Adviser profile update successful', ['student_id' => $student_id, 'picture_updated' => true], 'adviser_student_profile');
                        }
                    } else {
                        $message = 'Student profile updated successfully!';
                        $message_type = 'success';
                        elsInfo('Adviser profile update successful', ['student_id' => $student_id, 'picture_updated' => false], 'adviser_student_profile');
                    }
                }
            }
        }
    }
}

// Fetch student details
$row = null;
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/account-management/student-profile',
        ['student_id' => $student_id]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
        $row = $bridgeData['student'];
    }
}

if (!$row) {
    $stmt = $conn->prepare("SELECT * FROM student_info WHERE student_number = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        echo "<script>alert('Student not found.'); window.history.back();</script>";
        exit();
    }
    $row = $result->fetch_assoc();
}

$last_name = htmlspecialchars($row['last_name'] ?? '');
$first_name = htmlspecialchars($row['first_name'] ?? '');
$middle_name = htmlspecialchars($row['middle_name'] ?? '');
$email = htmlspecialchars($row['email'] ?? '');
$picture = htmlspecialchars(resolveScopedPictureSrc((string)($row['picture'] ?? ''), '../', 'pix/anonymous.jpg'));
$contact_no = htmlspecialchars($row['contact_number'] ?? '');
$address = htmlspecialchars($row['house_number_street'] ?? '');
$admission_date = htmlspecialchars($row['date_of_admission'] ?? '');
$program = htmlspecialchars($row['program'] ?? '');
$student_display_name = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Student Profile (Adviser)</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      color: #333;
      overflow-x: hidden;
      min-height: 100vh;
    }

    /* Title bar styling */
    .title-bar {
      background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
      color: #fff;
      padding: 7px 16px;
      text-align: left;
      font-size: 18px;
      font-weight: 800;
      width: 100%;
      position: fixed;
      top: 0;
      left: 0;
      z-index: 1000;
      display: flex;
      align-items: center;
      box-shadow: 0 4px 20px rgba(32, 96, 24, 0.3);
      backdrop-filter: blur(10px);
      justify-content: space-between;
    }

    .title-content {
      display: flex;
      align-items: center;
    }

    .adviser-name {
      font-size: 16px;
      font-weight: 600;
      color: #facc41;
      font-family: 'Segoe UI', Arial, sans-serif;
      letter-spacing: 0.5px;
      background: rgba(250, 204, 65, 0.15);
      padding: 8px 16px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border: 1px solid rgba(250, 204, 65, 0.3);
    }

    .title-bar img {
      height: 32px;
      width: auto;
      margin-right: 12px;
      vertical-align: middle;
      cursor: pointer;
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
      font-size: 18px;
      cursor: pointer;
      margin-right: 10px;
      border-radius: 6px;
      transition: all 0.2s ease;
      line-height: 1;
      padding: 0;
    }

    .menu-toggle:hover {
      background: rgba(255, 255, 255, 0.22);
    }

    /* Sidebar styling */
    .sidebar {
      width: 250px;
      height: calc(100vh - 46px);
      background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
      color: white;
      position: fixed;
      left: 0;
      top: 46px;
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
      margin-bottom: 10px;
    }

    .sidebar-header h3 {
      margin: 0;
      font-size: 20px;
      font-weight: 600;
    }

    .sidebar-menu {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .sidebar-menu li {
      margin: 0;
    }

    .sidebar-menu a {
      display: flex;
      align-items: center;
      padding: 15px 20px;
      color: white;
      text-decoration: none;
      transition: all 0.3s ease;
      border-left: 3px solid transparent;
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
      margin-right: 12px;
      filter: invert(1);
    }

    .menu-group {
      margin-bottom: 20px;
    }

    .menu-group-title {
      padding: 10px 20px 5px;
      font-size: 12px;
      text-transform: uppercase;
      color: rgba(255,255,255,0.7);
      font-weight: 600;
      letter-spacing: 1px;
    }

    /* Main content styling */
    .main-content {
      margin-left: 250px;
      min-height: calc(100vh - 46px);
      background-color: transparent;
      width: calc(100vw - 250px);
      overflow-x: hidden;
      transition: margin-left 0.3s ease, width 0.3s ease;
    }

    .main-content.expanded {
      margin-left: 0;
      width: 100vw;
    }

    .icon-bar {
      position: absolute;
      top: 10px;
      right: 15px;
      display: flex;
      gap: 15px;
    }

    .icon-bar img {
      width: 25px;
      height: 25px;
      cursor: pointer;
    }

    .container {
      max-width: 1160px;
      margin: 38px auto 28px;
      padding: 0 18px;
    }

    .page-shell {
      background: rgba(255, 255, 255, 0.94);
      backdrop-filter: blur(14px);
      border-radius: 26px;
      box-shadow: 0 18px 44px rgba(32, 96, 24, 0.12);
      border: 1px solid rgba(32, 96, 24, 0.08);
      overflow: hidden;
    }

    .page-hero {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #fff;
      padding: 28px 34px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
    }

    .hero-copy {
      display: flex;
      flex-direction: column;
      gap: 10px;
      max-width: 720px;
    }

    .hero-eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.4px;
      color: rgba(255, 255, 255, 0.82);
    }

    .hero-copy h1 {
      margin: 0;
      font-size: 40px;
      font-weight: 800;
      line-height: 1.05;
    }

    .hero-copy p {
      margin: 0;
      font-size: 15px;
      line-height: 1.6;
      color: rgba(255, 255, 255, 0.84);
    }

    .hero-back {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 18px;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.22);
      color: #fff;
      text-decoration: none;
      font-weight: 700;
      transition: all 0.2s ease;
    }

    .hero-back:hover {
      background: rgba(255, 255, 255, 0.22);
      transform: translateY(-1px);
    }

    .content-wrapper {
      padding: 28px 34px 34px;
    }

    .profile-layout {
      display: grid;
      grid-template-columns: minmax(270px, 320px) minmax(0, 1fr);
      gap: 28px;
      align-items: start;
    }

    .profile-card {
      background: linear-gradient(180deg, #f8fdf8 0%, #eef8ef 100%);
      border: 1px solid rgba(32, 96, 24, 0.12);
      border-radius: 22px;
      padding: 28px 24px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: 16px;
    }

    .photo-container {
      position: relative;
      width: 156px;
      height: 156px;
      border-radius: 50%;
      padding: 6px;
      background: linear-gradient(135deg, #206018 0%, #7cc86f 100%);
      box-shadow: 0 16px 30px rgba(32, 96, 24, 0.22);
    }

    .photo-container img {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      object-fit: cover;
      display: block;
      background: #f4f7f4;
      border: 4px solid #fff;
    }

    .profile-card h2 {
      margin: 2px 0 0;
      font-size: 30px;
      line-height: 1.05;
      font-weight: 800;
      color: #1e3f1a;
    }

    .profile-card .profile-role {
      margin: 0;
      color: #426b3e;
      font-size: 15px;
      line-height: 1.6;
    }

    .form-card {
      background: #fff;
      border-radius: 22px;
      border: 1px solid rgba(32, 96, 24, 0.12);
      box-shadow: 0 12px 34px rgba(32, 96, 24, 0.08);
      overflow: hidden;
    }

    .form-card-header {
      padding: 24px 28px 18px;
      border-bottom: 1px solid #e4efe2;
    }

    .form-card-header h3 {
      margin: 0 0 6px;
      font-size: 24px;
      font-weight: 800;
      color: #1d3f18;
    }

    .form-card-header p {
      margin: 0;
      color: #4f6e4c;
      font-size: 14px;
      line-height: 1.6;
    }

    .details {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 22px 24px;
      padding: 26px 28px 8px;
    }

    .field {
      display: flex;
      flex-direction: column;
      position: relative;
    }

    .field.field-span {
      grid-column: 1 / -1;
    }

    .field label {
      margin-bottom: 8px;
      font-weight: 700;
      color: #206018;
      font-size: 13px;
      letter-spacing: 0.7px;
      text-transform: uppercase;
    }

    .field input {
      width: 100%;
      padding: 12px 15px;
      border: 2px solid #e1e5e9;
      border-radius: 12px;
      font-size: 14px;
      background: white;
      transition: all 0.3s ease;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
      color: #495057;
    }

    .field input:focus {
      border-color: #206018;
      outline: none;
      box-shadow: 0 4px 20px rgba(32, 96, 24, 0.15);
    }

    .field input:read-only {
      background: #f8f9fa;
      color: #6c757d;
      cursor: not-allowed;
    }

    .buttons {
      margin-top: 10px;
      display: flex;
      gap: 15px;
      justify-content: flex-end;
      flex-wrap: wrap;
      padding: 0 28px 28px;
    }

    .buttons button {
      padding: 15px 35px;
      border: none;
      border-radius: 12px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      letter-spacing: 0.5px;
      transition: all 0.3s ease;
      min-width: 120px;
    }

    .btn-save {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
    }

    .btn-save:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(32, 96, 24, 0.4);
    }

    .btn-back {
      background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
      color: white;
      box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
    }

    .btn-back:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
    }

    .message {
      margin: 0 0 22px;
      padding: 15px 20px;
      border-radius: 12px;
      font-weight: 600;
      text-align: center;
      font-size: 14px;
    }

    .message.success {
      background: #d4edda;
      color: #155724;
      border: 2px solid #c3e6cb;
    }

    .message.error {
      background: #f8d7da;
      color: #721c24;
      border: 2px solid #f5c6cb;
    }

    .photo-upload {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
    }

    .photo-upload input[type="file"] {
      display: none;
    }

    .photo-upload label {
      display: inline-block;
      padding: 12px 20px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      border-radius: 12px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 700;
      transition: all 0.3s ease;
      box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
    }

    .photo-upload label:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
    }

    .photo-help {
      font-size: 12px;
      color: #587453;
      line-height: 1.5;
      max-width: 220px;
    }

    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
      }
      
      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
        width: 100vw;
      }
      
      .menu-toggle {
        display: block;
      }

      .profile-layout,
      .details {
        grid-template-columns: 1fr;
      }
      
      .buttons {
        flex-direction: column;
        align-items: stretch;
      }
      
      .container {
        margin: 18px auto 24px;
        padding: 0 12px;
      }
      
      .content-wrapper {
        padding: 18px 18px 24px;
      }

      .page-hero,
      .form-card-header,
      .details,
      .buttons {
        padding-left: 18px;
        padding-right: 18px;
      }

      .hero-copy h1 {
        font-size: 32px;
      }
    }
  </style>
</head>
<body>
  <!-- Title Bar -->
  <div class="title-bar">
    <div class="title-content">
      <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
      <span style="color: #d9e441; font-weight: 800;">ASPLAN</span>
    </div>
    <div class="adviser-name"><?= $adviser_name ?> | Adviser</div>
  </div>

  <!-- Sidebar Navigation -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h3>Adviser Panel</h3>
    </div>
    <ul class="sidebar-menu">
      <div class="menu-group">
        <div class="menu-group-title">Dashboard</div>
        <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Student Management</div>
        <li><a href="pending_accounts.php"><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
        <li><a href="checklist_eval.php"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
        <li><a href="study_plan_list.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
        <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>

      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Account</div>
        <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
      </div>
    </ul>
  </div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <div class="container">
      <div class="page-shell">
        <div class="page-hero">
          <div class="hero-copy">
            <span class="hero-eyebrow">Adviser Student Profile</span>
            <h1>Edit Student Profile</h1>
            <p>Review and update this student's core account details from the adviser workspace while keeping the familiar student-facing profile structure.</p>
          </div>
          <a href="javascript:window.history.back();" class="hero-back">Back to Student List</a>
        </div>
        <div class="content-wrapper">
          <?php if (!empty($message)): ?>
            <div class="message <?= $message_type ?>">
              <?= htmlspecialchars($message) ?>
            </div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data">
            <div class="profile-layout">
              <aside class="profile-card">
                <div class="photo-container">
                  <img id="profile-pic" src="<?= $picture ?>" alt="Profile Photo" />
                </div>
                <h2><?= htmlspecialchars($student_display_name !== '' ? $student_display_name : 'Student Profile') ?></h2>
                <p class="profile-role">Keep the student record current so checklist, study plan, and advising details stay aligned.</p>
                <div class="photo-upload">
                  <label for="picture">Change Photo</label>
                  <input type="file" id="picture" name="picture" accept="image/*" onchange="previewImage(this)">
                  <div class="photo-help">Upload a clear square image so the student profile stays consistent across the adviser and student views.</div>
                </div>
              </aside>

              <section class="form-card">
                <div class="form-card-header">
                  <h3>Account Details</h3>
                  <p>Edit the student-facing personal details below. Required fields stay aligned with the existing validation and save flow.</p>
                </div>
                <div class="details">
                  <div class="field">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" value="<?= $last_name ?>" required>
                  </div>
                  <div class="field">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="<?= $first_name ?>" required>
                  </div>
                  <div class="field">
                    <label>Middle Name <small>(Optional)</small></label>
                    <input type="text" name="middle_name" value="<?= $middle_name ?>" placeholder="Leave blank if no middle name">
                  </div>
                  <div class="field">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?= $email ?>" required>
                  </div>
                  <div class="field">
                    <label>Student Number</label>
                    <input type="text" value="<?= $student_id ?>" readonly>
                  </div>
                  <div class="field">
                    <label>Contact Number *</label>
                    <input type="text" name="contact_no" value="<?= $contact_no ?>" required>
                  </div>
                  <div class="field">
                    <label>Date of Admission *</label>
                    <input type="date" name="admission_date" value="<?= $admission_date ?>" required>
                  </div>
                  <div class="field">
                    <label>Address *</label>
                    <input type="text" name="address" value="<?= $address ?>" required>
                  </div>
                </div>
                <div class="buttons">
                  <button type="submit" name="update_profile" class="btn-save">Save Changes</button>
                  <button type="button" onclick="window.history.back();" class="btn-back">Back</button>
                </div>
              </section>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('expanded');
  }

  // Close sidebar when clicking outside on mobile
  document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    const logo = document.querySelector('.title-bar img');
    
    if (window.innerWidth <= 768 && 
        !sidebar.contains(event.target) && 
        !menuToggle.contains(event.target) &&
        !logo.contains(event.target)) {
      sidebar.classList.add('collapsed');
      document.querySelector('.main-content').classList.add('expanded');
    }
  });

  // Handle responsive behavior
  window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (window.innerWidth > 768) {
      // Reset to desktop view
      sidebar.classList.remove('collapsed');
      mainContent.classList.remove('expanded');
    }
  });

  // Preview uploaded image
  function previewImage(input) {
    if (input.files && input.files[0]) {
      const reader = new FileReader();
      
      reader.onload = function(e) {
        document.getElementById('profile-pic').src = e.target.result;
      };
      
      reader.readAsDataURL(input.files[0]);
    }
  }

  // Form validation
  document.querySelector('form').addEventListener('submit', function(e) {
    const requiredFields = ['last_name', 'first_name', 'contact_no', 'address', 'admission_date'];
    let isValid = true;
    
    requiredFields.forEach(function(fieldName) {
      const field = document.querySelector(`input[name="${fieldName}"]`);
      if (field && !field.value.trim()) {
        field.style.borderColor = '#dc3545';
        isValid = false;
      } else if (field) {
        field.style.borderColor = '#e1e5e9';
      }
    });
    
    if (!isValid) {
      e.preventDefault();
      alert('Please fill in all required fields.');
    }
  });

  // Real-time validation feedback
  document.querySelectorAll('input[required]').forEach(function(input) {
    input.addEventListener('blur', function() {
      if (!this.value.trim()) {
        this.style.borderColor = '#dc3545';
      } else {
        this.style.borderColor = '#206018';
      }
    });
    
    input.addEventListener('input', function() {
      if (this.value.trim()) {
        this.style.borderColor = '#206018';
      }
    });
  });
</script>
</body>
</html>
