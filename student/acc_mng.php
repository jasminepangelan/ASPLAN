<?php

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/vite_legacy.php';
require_once __DIR__ . '/../includes/csrf.php';

$password_display = '********';
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

// If admin is logged in and ?student_id is set, load that student's data from DB
$is_admin = isset($_SESSION['admin_id']);
$view_student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;

if ($is_admin && $view_student_id) {
  $row = null;
  if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
      'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/view',
      [
        'bridge_authorized' => true,
        'profile_context' => 'admin',
        'admin_id' => (string) ($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? ''),
        'student_id' => $view_student_id,
      ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
      $row = $bridgeData['student'];
    }
  }

  if (!$row) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, email, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date, picture FROM student_info WHERE student_number = ?");
    $stmt->bind_param("s", $view_student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
      $last_name = htmlspecialchars($row['last_name']);
      $first_name = htmlspecialchars($row['first_name']);
      $middle_name = htmlspecialchars($row['middle_name']);
      $email = htmlspecialchars($row['email'] ?? '');
      $picture = resolvePublicUploadPath($row['picture'] ?? '', 'pix/anonymous.jpg');
      $student_id = htmlspecialchars($row['student_id']);
      $contact_no = htmlspecialchars($row['contact_no']);
      $address = htmlspecialchars($row['address']);
      $admission_date = htmlspecialchars($row['admission_date']);
    } else {
      die("Student not found.");
    }
    $stmt->close();
    closeDBConnection($conn);
  } else {
    $last_name = htmlspecialchars($row['last_name'] ?? '');
    $first_name = htmlspecialchars($row['first_name'] ?? '');
    $middle_name = htmlspecialchars($row['middle_name'] ?? '');
    $email = htmlspecialchars($row['email'] ?? '');
    $picture = resolvePublicUploadPath($row['picture'] ?? '', 'pix/anonymous.jpg');
    $student_id = htmlspecialchars((string) ($row['student_id'] ?? ''));
    $contact_no = htmlspecialchars((string) ($row['contact_no'] ?? ''));
    $address = htmlspecialchars((string) ($row['address'] ?? ''));
    $admission_date = htmlspecialchars((string) ($row['admission_date'] ?? ''));
  }
} else {
  // Student viewing their own profile (default)
  if (!isset($_SESSION['student_id'])) {
  header("Location: ../index.php");
    exit();
  }
  $row = null;
  if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
      'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/view',
      [
        'bridge_authorized' => true,
        'profile_context' => 'student',
        'session_student_id' => (string) $_SESSION['student_id'],
        'student_id' => (string) $_SESSION['student_id'],
      ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
      $row = $bridgeData['student'];
    }
  }

  if ($row) {
    $last_name = htmlspecialchars($row['last_name'] ?? '');
    $first_name = htmlspecialchars($row['first_name'] ?? '');
    $middle_name = htmlspecialchars($row['middle_name'] ?? '');
    $email = htmlspecialchars($row['email'] ?? '');
    $picture = resolvePublicUploadPath($row['picture'] ?? '', 'pix/anonymous.jpg');
    $student_id = htmlspecialchars((string) ($row['student_id'] ?? ''));
    $contact_no = htmlspecialchars((string) ($row['contact_no'] ?? ''));
    $address = htmlspecialchars((string) ($row['address'] ?? ''));
    $admission_date = htmlspecialchars((string) ($row['admission_date'] ?? ''));
  } else {
    $conn = getDBConnection();
    $currentStudentId = (string) $_SESSION['student_id'];
    $stmt = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, email, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date, picture FROM student_info WHERE student_number = ?");
    $stmt->bind_param("s", $currentStudentId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($dbRow = $result->fetch_assoc()) {
      $last_name = htmlspecialchars($dbRow['last_name'] ?? '');
      $first_name = htmlspecialchars($dbRow['first_name'] ?? '');
      $middle_name = htmlspecialchars($dbRow['middle_name'] ?? '');
      $email = htmlspecialchars($dbRow['email'] ?? '');
      $picture = resolvePublicUploadPath($dbRow['picture'] ?? '', 'pix/anonymous.jpg');
      $student_id = htmlspecialchars((string) ($dbRow['student_id'] ?? ''));
      $contact_no = htmlspecialchars((string) ($dbRow['contact_no'] ?? ''));
      $address = htmlspecialchars((string) ($dbRow['address'] ?? ''));
      $admission_date = htmlspecialchars((string) ($dbRow['admission_date'] ?? ''));
      $_SESSION['picture'] = $dbRow['picture'] ?? '';
    } else {
      $last_name = htmlspecialchars($_SESSION['last_name']);
      $first_name = htmlspecialchars($_SESSION['first_name']);
      $middle_name = htmlspecialchars($_SESSION['middle_name']);
      $email = htmlspecialchars($_SESSION['email'] ?? '');
      $picture = resolvePublicUploadPath($_SESSION['picture'] ?? '', 'pix/anonymous.jpg');
      $student_id = htmlspecialchars($_SESSION['student_id']);
      $contact_no = htmlspecialchars($_SESSION['contact_no']);
      $address = htmlspecialchars($_SESSION['address']);
      $admission_date = htmlspecialchars($_SESSION['admission_date']);
    }

    $stmt->close();
    closeDBConnection($conn);
  }
}

$studentShellPayload = htmlspecialchars(json_encode([
  'title' => 'Profile & Account',
  'description' => 'Manage your personal information, keep your contact details current, and update your student-facing account settings without leaving the legacy workflow.',
  'accent' => 'emerald',
  'pageKey' => 'profile',
  'stats' => [
    ['label' => 'Student ID', 'value' => (string)$student_id],
    ['label' => 'Email', 'value' => $email !== '' ? (string)$email : 'Not set'],
    ['label' => 'Admission', 'value' => $admission_date !== '' ? (string)$admission_date : 'Not set'],
  ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$studentProfileWorkspacePayload = htmlspecialchars(json_encode([
  'studentName' => trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name),
  'roleLabel' => 'Student profile center',
  'note' => 'This page keeps your current PHP save flow intact while giving you faster access to the profile actions you use most often.',
  'chips' => [
    ['label' => 'Student ID', 'value' => (string)$student_id],
    ['label' => 'Email', 'value' => $email !== '' ? (string)$email : 'Not set'],
    ['label' => 'Contact', 'value' => $contact_no !== '' ? (string)$contact_no : 'Not set'],
    ['label' => 'Admission', 'value' => $admission_date !== '' ? (string)$admission_date : 'Not set'],
  ],
  'actionCards' => [
    ['key' => 'picture', 'title' => 'Update photo', 'description' => 'Choose a new profile image using the existing upload flow.'],
    ['key' => 'email', 'title' => 'Edit email', 'description' => 'Jump directly to the email field and enable editing.'],
    ['key' => 'contact', 'title' => 'Edit contact number', 'description' => 'Quickly unlock your contact field for updates.'],
    ['key' => 'password', 'title' => 'Change password', 'description' => 'Open the password panel without hunting through the form.'],
    ['key' => 'save', 'title' => 'Save all changes', 'description' => 'Run the current PHP save handler with your latest form values.'],
  ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$emailVerificationStatus = [
  'variant' => 'neutral',
  'label' => 'Email status unavailable',
  'headline' => 'Unable to read verification state',
  'description' => 'Refresh the page after updating your profile if the email verification state does not appear right away.',
];
$showVerificationBanner = false;

$studentIdForVerification = html_entity_decode((string) $student_id, ENT_QUOTES, 'UTF-8');
$emailForVerification = html_entity_decode((string) $email, ENT_QUOTES, 'UTF-8');

if ($studentIdForVerification !== '' && $emailForVerification !== '') {
  $verificationConn = getDBConnection();
  $emailVerificationStatus = sevGetStatusMeta($verificationConn, $studentIdForVerification, $emailForVerification);
  closeDBConnection($verificationConn);

  if (($emailVerificationStatus['variant'] ?? '') === 'pending' && function_exists('sevIsCvsuEmail') && sevIsCvsuEmail($emailForVerification)) {
    sevSetSessionRequirement($emailForVerification);
    $_SESSION['student_email_verification_notice'] = 'Please verify your CvSU email address to complete your student account setup.';
    $showVerificationBanner = true;
  }
}

$csrfToken = getCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profile</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <style>
    * {
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      background: linear-gradient(180deg, #eef3ee 0%, #f7faf7 52%, #edf2f6 100%);
      min-height: 100vh;
      overflow: hidden;
    }

    /* Title bar styling */
    .title-bar {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #ffffffff;
      padding: 5px 15px;
      text-align: left;
      font-size: 18px;
      font-weight: 800;
      position: sticky;
      top: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      z-index: 1000;
    }

    .title-content {
      display: flex;
      align-items: center;
    }

    .student-info {
      font-size: 16px;
      font-weight: 600;
      color: #facc41;
      font-family: 'Segoe UI', Arial, sans-serif;
      letter-spacing: 0.5px;
      background: rgba(250, 204, 65, 0.15);
      padding: 5px 15px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border: 1px solid rgba(250, 204, 65, 0.3);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .student-info img {
      width: 27px !important;
      height: 25px !important;
      border-radius: 50%;
    }

    .title-bar img {
      height: 32px;
      width: auto;
      margin-right: 12px;
      vertical-align: middle;
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
    }

    .menu-toggle:hover {
      background: rgba(255, 255, 255, 0.22);
    }

    /* Sidebar styling */
    .sidebar {
      width: 250px;
      height: calc(100vh - 32px);
      background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
      color: white;
      position: fixed;
      left: 0;
      top: 32px;
      padding: 20px 0;
      box-shadow: 2px 0 5px rgba(0,0,0,0.1);
      overflow-y: hidden;
      transition: transform 0.3s ease;
      z-index: 999;
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

    .main-content {
      margin-left: 250px;
      height: calc(100vh - 51px);
      overflow-y: auto;
      transition: margin-left 0.3s ease;
      padding: 28px 24px 40px;
    }

    .main-content.expanded {
      margin-left: 0;
    }

    .container {
      max-width: 1150px;
      margin: 0 auto;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(249, 252, 248, 0.97));
      backdrop-filter: blur(8px);
      border-radius: 30px;
      box-shadow: 0 26px 58px rgba(33, 61, 34, 0.14), 0 8px 18px rgba(33, 61, 34, 0.07);
      overflow: hidden;
      border: 1px solid rgba(32, 96, 24, 0.07);
    }

    .title {
      background: linear-gradient(135deg, #1d5a16 0%, #2c8b23 55%, #4f9d38 100%);
      color: white;
      padding: 24px 30px 12px;
      text-align: left;
      font-size: 30px;
      font-weight: 700;
      letter-spacing: 0.5px;
      margin: 0;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
    }

    .subtitle {
      color: #486445;
      font-size: 15px;
      margin: 0 0 22px;
      letter-spacing: 0.2px;
      font-weight: 500;
    }

    .content-wrapper {
      padding: 30px 30px 34px;
    }

    .profile-summary {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 22px;
    }

    .summary-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      background: #f3f8f2;
      border: 1px solid #d9e7d6;
      color: #244722;
      font-size: 13px;
      font-weight: 600;
      box-shadow: 0 3px 10px rgba(32, 96, 24, 0.06);
    }

    .summary-pill strong {
      color: #1c351a;
    }

    .profile {
      display: flex;
      gap: 24px;
      align-items: flex-start;
      justify-content: stretch;
      flex-wrap: nowrap;
    }

    .profile .photo {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      min-width: 280px;
      width: 300px;
      padding: 28px 24px 26px;
      border-radius: 24px;
      background: linear-gradient(180deg, #fbfdfb 0%, #eff7ed 100%);
      border: 1px solid #dce9d8;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.9), 0 14px 30px rgba(38, 79, 32, 0.08);
    }

    .profile .photo .photo-container {
      position: relative;
      border-radius: 50%;
      padding: 6px;
      background: linear-gradient(135deg, #206018, #2d8f22);
      box-shadow: 0 10px 30px rgba(32, 96, 24, 0.3);
    }

    .profile .photo img {
      width: 140px;
      height: 140px;
      border-radius: 50%;
      object-fit: cover;
      background: #f8f9fa;
      border: 4px solid white;
    }

    .photo-caption {
      text-align: center;
    }

    .photo-caption strong {
      display: block;
      font-size: 18px;
      color: #1f401c;
      margin-bottom: 4px;
    }

    .photo-caption span {
      display: block;
      font-size: 13px;
      color: #5f785c;
      line-height: 1.5;
    }

    .verification-panel {
      width: 100%;
      padding: 14px 16px;
      border-radius: 18px;
      border: 1px solid #d7e6d5;
      background: #ffffff;
      box-shadow: 0 8px 18px rgba(38, 79, 32, 0.07);
    }

    .verification-label {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.4px;
      text-transform: uppercase;
      color: #1f6a1b;
      background: #eef8ec;
      border: 1px solid #cde2c9;
    }

    .verification-label::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: currentColor;
      opacity: 0.9;
    }

    .verification-panel strong {
      display: block;
      margin-top: 12px;
      font-size: 16px;
      line-height: 1.3;
      color: #1e3d1b;
    }

    .verification-panel p {
      margin: 8px 0 0;
      font-size: 12px;
      line-height: 1.5;
      color: #637860;
    }

    .verification-panel.pending {
      background: linear-gradient(180deg, #fff9ef 0%, #fff4df 100%);
      border-color: #efd2a3;
    }

    .verification-panel.pending .verification-label {
      color: #9a5b00;
      background: #fff4dd;
      border-color: #f0d39d;
    }

    .verification-panel.pending strong {
      color: #6f4300;
    }

    .verification-panel.neutral {
      background: linear-gradient(180deg, #f7faf7 0%, #f0f5ef 100%);
      border-color: #dce7da;
    }

    .verification-panel.neutral .verification-label {
      color: #50654d;
      background: #eef2ed;
      border-color: #d9e1d7;
    }

    .verification-banner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin: 0 0 22px;
      padding: 16px 18px;
      border-radius: 18px;
      border: 1px solid #f0d39d;
      background: linear-gradient(135deg, #fff8eb 0%, #fff3dd 100%);
      box-shadow: 0 10px 24px rgba(130, 91, 22, 0.08);
    }

    .verification-banner-copy {
      min-width: 0;
    }

    .verification-banner-copy strong {
      display: block;
      font-size: 15px;
      color: #7a4a00;
      margin-bottom: 6px;
    }

    .verification-banner-copy p {
      margin: 0;
      font-size: 13px;
      line-height: 1.55;
      color: #6f5a2d;
    }

    .verification-banner-action {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 124px;
      padding: 12px 16px;
      border-radius: 14px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #fff;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.2px;
      box-shadow: 0 10px 18px rgba(32, 96, 24, 0.2);
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .verification-banner-action:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 22px rgba(32, 96, 24, 0.26);
    }

    .profile .photo button {
      padding: 12px 25px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      border: none;
      border-radius: 25px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
    }
    .profile .photo button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(32, 96, 24, 0.4);
    }

    .profile .details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
      width: 100%;
      min-width: 280px;
      padding: 22px;
      border-radius: 26px;
      background: linear-gradient(180deg, #ffffff 0%, #fbfdfb 100%);
      border: 1px solid #e4eee1;
      box-shadow: 0 16px 34px rgba(38, 79, 32, 0.08);
      align-content: start;
    }

    .details-heading {
      grid-column: 1 / -1;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      padding-bottom: 4px;
      border-bottom: 1px solid #eef3ed;
      margin-bottom: 2px;
    }

    .details-heading h3 {
      margin: 0;
      font-size: 22px;
      color: #1f401c;
      line-height: 1.2;
    }

    .details-heading p {
      margin: 6px 0 0;
      font-size: 13px;
      color: #6a7d68;
      line-height: 1.5;
    }

    .details-badge {
      flex-shrink: 0;
      padding: 8px 12px;
      border-radius: 999px;
      background: #edf8eb;
      color: #1f6a1b;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #cfe5cb;
    }

    .profile .details .field {
      display: flex;
      flex-direction: column;
      position: relative;
      padding: 14px;
      border-radius: 18px;
      background: linear-gradient(180deg, #fcfefc 0%, #f6faf5 100%);
      border: 1px solid #e8f0e5;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.85);
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }

    .profile .details .field:hover {
      transform: translateY(-1px);
      border-color: #d8e6d4;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.88), 0 8px 18px rgba(38, 79, 32, 0.06);
    }

    .profile .details .field label {
      margin-bottom: 8px;
      font-weight: 600;
      color: #206018;
      font-size: 14px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }

    .field-note {
      margin-top: 8px;
      font-size: 11px;
      color: #7b8d78;
      line-height: 1.45;
    }

    .field-input-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .profile .details .field input {
      width: 100%;
      padding: 13px 15px;
      border: 2px solid #e1e5e9;
      border-radius: 14px;
      font-size: 14px;
      background: #ffffff;
      transition: all 0.3s ease;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
      min-width: 0;
    }

    .profile .details .field input:focus {
      border-color: #206018;
      outline: none;
      box-shadow: 0 4px 20px rgba(32, 96, 24, 0.15);
      transform: translateY(-1px);
    }

    .profile .details .field input:disabled {
      background: #f8f9fa;
      color: #6c757d;
      cursor: not-allowed;
    }

    .edit-trigger {
      flex: 0 0 40px;
      width: 40px;
      height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #d7e7d3;
      border-radius: 12px;
      background: linear-gradient(180deg, #ffffff 0%, #eef6ec 100%);
      color: #206018;
      cursor: pointer;
      box-shadow: 0 6px 14px rgba(32, 96, 24, 0.08);
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, color 0.2s ease;
    }

    .edit-trigger:hover {
      transform: translateY(-1px);
      color: #2d8f22;
      border-color: #bfd7ba;
      box-shadow: 0 10px 20px rgba(32, 96, 24, 0.14);
    }

    .edit-trigger i {
      font-size: 14px;
    }

    .profile .details .field button[type="button"] {
      margin-top: 10px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 12px 0;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
    }
    .profile .details .field button[type="button"]:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(32, 96, 24, 0.4);
    }

    #change-password-container {
      margin-top: 15px;
      background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
      border-radius: 16px;
      padding: 16px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      border: 1px solid #dee2e6;
      grid-column: 1 / -1;
    }

    .buttons {
      grid-column: 1 / -1;
      text-align: center;
      margin-top: 4px;
      display: flex;
      gap: 15px;
      justify-content: flex-end;
      flex-wrap: wrap;
      padding-top: 8px;
      border-top: 1px solid #eef3ed;
    }

    #student-profile-actions {
      flex: 0 0 168px;
      align-self: flex-start;
      position: sticky;
      top: 18px;
      margin-top: 10px;
      padding: 0;
      border-top: none;
      gap: 14px;
      flex-direction: column;
      justify-content: flex-start;
    }

    #student-profile-actions button {
      width: 100%;
      min-width: 0;
      padding: 18px 20px;
      border-radius: 14px;
      font-size: 15px;
      line-height: 1.1;
      box-shadow: 0 10px 22px rgba(32, 96, 24, 0.18);
    }

    #student-profile-actions button:hover {
      transform: translateY(-2px);
    }

    .buttons button {
      padding: 15px 35px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      letter-spacing: 0.5px;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
      min-width: 120px;
    }

    .buttons button:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(32, 96, 24, 0.4);
    }

    .buttons button[style*="background-color: #888"] {
      background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
    }

    /* Responsive design */
    @media (max-width: 768px) {
      body {
        overflow: auto;
        overflow-x: hidden;
      }

      .sidebar {
        transform: translateX(-100%);
        z-index: 1000;
      }
      
      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
        height: auto;
        min-height: calc(100vh - 51px);
        overflow-y: visible;
        padding: 12px 12px 28px;
      }
      
      .menu-toggle {
        display: inline-flex;
      }

      .title-bar {
        font-size: 12px;
        padding: 5px 8px;
      }

      .title-bar img {
        height: 22px !important;
        margin-right: 6px !important;
      }

      .student-info {
        font-size: 10px;
        padding: 3px 6px;
      }

      .student-info img {
        width: 18px !important;
        height: 18px !important;
      }

      .profile {
        flex-direction: column;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
      }

      .profile .photo {
        width: 100%;
        min-width: 0;
      }
      
      .profile .details {
        grid-template-columns: 1fr;
        max-width: 100%;
        width: 100%;
        gap: 15px;
        padding: 18px;
      }

      .profile .photo img {
        width: 120px;
        height: 120px;
      }

      .profile .photo button {
        padding: 10px 20px;
        font-size: 13px;
      }

      .profile .details .field label {
        font-size: 12px;
      }

      .profile .details .field input {
        font-size: 13px;
        padding: 10px 12px;
      }

      #change-password-container {
        padding: 15px;
        margin-top: 10px;
      }
      
      .buttons {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        justify-content: stretch;
      }

      #student-profile-actions {
        position: static;
        width: 100%;
        margin-top: 0;
      }

      .buttons button {
        width: 100%;
        padding: 12px 20px;
        font-size: 14px;
        margin-bottom: 5px;
      }
      
      .container {
        margin-bottom: 30px;
        border-radius: 22px;
      }

      .title {
        font-size: 24px;
        padding: 20px 18px 10px;
      }

      .subtitle {
        font-size: 14px;
        margin: 0 0 18px;
      }
      
      .content-wrapper {
        padding: 15px;
        padding-bottom: 40px;
      }
    }

    @media (max-width: 480px) {
      .title-bar {
        font-size: 10px;
        padding: 4px 6px;
      }

      .student-info span {
        font-size: 8px;
      }

      .student-info {
        padding: 2px 5px;
      }

      .title {
        font-size: 18px;
      }

      .subtitle {
        font-size: 14px;
      }

      .container {
        border-radius: 18px;
      }

      .content-wrapper {
        padding: 10px;
      }
    }

    /* Modal styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 2000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(5px);
      padding-top: 60px;
    }

    .modal-content {
      background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
      margin: 5% auto;
      padding: 30px;
      border: none;
      width: 80%;
      max-width: 450px;
      border-radius: 20px;
      box-shadow: 0 20px 40px rgba(32, 96, 24, 0.2);
      text-align: center;
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .close {
      color: #6c757d;
      float: right;
      font-size: 28px;
      font-weight: bold;
      position: absolute;
      right: 20px;
      top: 15px;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .close:hover,
    .close:focus {
      color: #206018;
      text-decoration: none;
      transform: scale(1.1);
    }

    .modal-icon {
      text-align: center;
      margin-bottom: 20px;
    }

    .modal-icon img {
      width: 60px;
      height: 60px;
      filter: none;
    }

    .modal-title {
      font-size: 24px;
      font-weight: 700;
      color: #206018;
      margin: 0 0 15px 0;
      text-align: center;
    }

    .modal-message {
      font-size: 16px;
      color: #495057;
      margin-bottom: 25px;
      text-align: center;
      line-height: 1.5;
    }

    .modal-button {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      border: none;
      border-radius: 12px;
      padding: 15px 30px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: block;
      width: 100%;
      text-align: center;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
    }

    .modal-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(32, 96, 24, 0.4);
    }

    .success-modal .modal-content {
      border-top: 4px solid #28a745;
    }

    .error-modal .modal-content {
      border-top: 4px solid #d93025;
    }

    .error-modal .modal-icon {
      background: linear-gradient(135deg, #d93025 0%, #ef5350 100%);
      font-size: 30px;
      font-weight: 800;
      color: #fff;
    }
  </style>
  <?= renderLegacyViteTags(['resources/js/student-shell.jsx', 'resources/js/student-profile-workspace.jsx']) ?>
</head>
<body>
  <!-- Title Bar -->
  <div class="title-bar">
    <div class="title-content">
      <button type="button" class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle sidebar" title="Toggle sidebar">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" style="height: 32px; width: auto; margin-right: 12px; cursor: pointer;" onclick="toggleSidebar()">
      <span style="color: #d9e441; font-weight: 800;">ASPLAN</span>
    </div>
    <div class="student-info">
      <img src="<?= htmlspecialchars(resolveScopedPictureSrc($picture, '../', 'pix/anonymous.jpg')) ?>" alt="Profile Picture">
      <span><?= $last_name . ', ' . $first_name . (!empty($middle_name) ? ' ' . $middle_name : '') ?> | Student</span>
    </div>
  </div>

  <!-- Sidebar Navigation -->
  <div class="sidebar collapsed" id="sidebar">
    <div class="sidebar-header">
      <h3>Student Panel</h3>
    </div>
    <ul class="sidebar-menu">
      <div class="menu-group">
        <div class="menu-group-title">Dashboard</div>
        <li><a href="home_page_student.php"><img src="../pix/home1.png" alt="Home" style="filter: brightness(0) invert(1);"> Home</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Academic</div>
        <li><a href="checklist_stud.php"><img src="../pix/update.png" alt="Checklist"> Update Checklist</a></li>
        <li><a href="study_plan.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan</a></li>
        <li><a href="program_shift_request.php"><img src="../pix/checklist.png" alt="Program Shift"> Program Shift</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Account</div>
        <li><a href="#" class="active"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
        <li><a href="../auth/signout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
      </div>
    </ul>
  </div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
  <div data-student-shell="<?= $studentShellPayload ?>"></div>

  <div class="container">
    <div class="title">Student Profile</div>
    <div class="content-wrapper">
      <div data-student-profile-workspace="<?= $studentProfileWorkspacePayload ?>"></div>
      <div class="subtitle">View and manage your account details</div>
      <?php if ($showVerificationBanner): ?>
        <div class="verification-banner">
          <div class="verification-banner-copy">
            <strong>CvSU email verification is still required</strong>
            <p>Your student account is using <strong><?= htmlspecialchars($emailForVerification) ?></strong>. Verify this CvSU email to keep account recovery and official notices active.</p>
          </div>
          <a class="verification-banner-action" href="verify_cvsu_email.php">Verify Email</a>
        </div>
      <?php endif; ?>
      <div class="profile" id="student-profile-form">
        <div class="photo" id="student-profile-photo-panel">
          <div class="photo-container">
            <img id="profile-pic" src="<?= htmlspecialchars(resolveScopedPictureSrc($picture, '../', 'pix/anonymous.jpg')) ?>" alt="Profile Photo" />
          </div>
          <div class="photo-caption">
            <strong><?= $first_name . (!empty($middle_name) ? ' ' . $middle_name : '') . ' ' . $last_name ?></strong>
            <span>Keep your profile current so your records and account recovery details stay accurate.</span>
          </div>
          <form id="pic-form" enctype="multipart/form-data" style="display:inline;">
            <input id="file-input" name="picture" type="file" accept="image/*" style="display: none;" onchange="previewImage(event)">
          </form>
          <button type="button" onclick="document.getElementById('file-input').click()">Change Picture</button>
          <div class="verification-panel <?= htmlspecialchars($emailVerificationStatus['variant']) ?>">
            <span class="verification-label"><?= htmlspecialchars($emailVerificationStatus['label']) ?></span>
            <strong><?= htmlspecialchars($emailVerificationStatus['headline']) ?></strong>
            <p><?= htmlspecialchars($emailVerificationStatus['description']) ?></p>
            <?php if (($emailVerificationStatus['variant'] ?? '') === 'pending' && function_exists('sevIsCvsuEmail') && sevIsCvsuEmail($emailForVerification)): ?>
              <a class="verification-banner-action" href="verify_cvsu_email.php" style="margin-top: 14px; width: 100%;">Verify CvSU Email</a>
            <?php endif; ?>
          </div>
        </div>
      <div class="details" id="student-profile-details-panel">
        <div class="details-heading">
          <div>
            <h3>Account Details</h3>
            <p>Update your personal information and review the details currently stored in your student profile.</p>
          </div>
          <div class="details-badge">Profile Center</div>
        </div>
        <div class="field editable-field">
          <label for="last_name">Last Name</label>
          <div class="field-input-row">
            <input id="last_name" type="text" value="<?= $last_name ?>" disabled>
            <button type="button" class="edit-trigger" onclick="toggleEdit('last_name')" aria-label="Edit last name">
              <i class="fas fa-edit" aria-hidden="true"></i>
            </button>
          </div>
          <div class="field-note">Use your official surname as it appears in school records.</div>
        </div>
        <div class="field editable-field">
          <label for="first_name">First Name</label>
          <div class="field-input-row">
            <input id="first_name" type="text" value="<?= $first_name ?>" disabled>
            <button type="button" class="edit-trigger" onclick="toggleEdit('first_name')" aria-label="Edit first name">
              <i class="fas fa-edit" aria-hidden="true"></i>
            </button>
          </div>
          <div class="field-note">This name is shown across your student dashboard and documents.</div>
        </div>
        <div class="field editable-field">
          <label for="middle_name">Middle Name</label>
          <div class="field-input-row">
            <input id="middle_name" type="text" value="<?= $middle_name ?>" disabled>
            <button type="button" class="edit-trigger" onclick="toggleEdit('middle_name')" aria-label="Edit middle name">
              <i class="fas fa-edit" aria-hidden="true"></i>
            </button>
          </div>
          <div class="field-note">Leave as is if you do not use a middle name in your records.</div>
        </div>
        <div class="field">
          <label for="username">Student Number</label>
          <input id="username" type="text" value="<?= $student_id ?>" disabled>
          <div class="field-note">This is your permanent student identifier and cannot be edited here.</div>
        </div>
        <div class="field editable-field">
          <label for="email">Email Address</label>
          <div class="field-input-row">
            <input id="email" type="email" value="<?= $email ?>" disabled>
            <button type="button" class="edit-trigger" onclick="toggleEdit('email')" aria-label="Edit email address">
              <i class="fas fa-edit" aria-hidden="true"></i>
            </button>
          </div>
          <div class="field-note">Student accounts must use an official @cvsu.edu.ph email address for notices and recovery.</div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" type="password" value="<?= $password_display ?>" disabled>
            <div class="field-note">For security, your password is hidden. Change it regularly if needed.</div>
            <button type="button" onclick="togglePasswordForm()">Change Password</button>

            <div id="change-password-container" style="display: none;">
                <div class="field">
                    <label for="current_password">Current Password</label>
                    <input id="current_password" type="password" required>
                </div>
                <div class="field">
                    <label for="new_password">New Password</label>
                    <input id="new_password" type="password" required>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input id="confirm_password" type="password" required>
                </div>
                <div class="buttons">
                    <button onclick="changePassword()">Save New Password</button>
                </div>
            </div>

            <script>
                function togglePasswordForm() {
                    const container = document.getElementById('change-password-container');
                    container.style.display = container.style.display === 'none' ? 'block' : 'none';
                }

                function changePassword() {
                    const currentPassword = document.getElementById('current_password').value;
                    const newPassword = document.getElementById('new_password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;

                    if (newPassword !== confirmPassword) {
                        showFeedbackModal('error', 'Password Update Failed', 'New passwords do not match.');
                        return;
                    }

                    const formData = new FormData();
                    formData.append("student_id", "<?= $student_id ?>");
                    formData.append("current_password", currentPassword);
                    formData.append("new_password", newPassword);

                    fetch("../auth/change_password.php", {
                        method: "POST",
                        body: formData,
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showFeedbackModal('success', 'Password Updated', 'Your password has been changed successfully.');
                            // Optionally reset the form or close it
                            document.getElementById('change-password-container').style.display = 'none';
                        } else {
                            showFeedbackModal('error', 'Password Update Failed', data.message || 'Unable to update your password right now.');
                        }
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        showFeedbackModal('error', 'Password Update Failed', 'A network error occurred while updating your password.');
                    });
                }
            </script>

        </div>
        <div class="field editable-field">
          <label for="contact_no">Contact Number</label>
          <div class="field-input-row">
            <input id="contact_no" type="text" value="<?= $contact_no ?>" disabled>
            <button type="button" class="edit-trigger" onclick="toggleEdit('contact_no')" aria-label="Edit contact number">
              <i class="fas fa-edit" aria-hidden="true"></i>
            </button>
          </div>
          <div class="field-note">Enter a reachable number in case the school needs to contact you.</div>
        </div>
        <div class="field">
          <label for="admission_date">Date of Admission</label>
          <input id="admission_date" type="text" value="<?= $admission_date ?>" disabled>
          <div class="field-note">This comes from your student record and is shown for reference only.</div>
        </div>
        <div class="field editable-field">
          <label for="address">Address</label>
          <div class="field-input-row">
            <input id="address" type="text" value="<?= $address ?>" disabled>
            <button type="button" class="edit-trigger" onclick="toggleEdit('address')" aria-label="Edit address">
              <i class="fas fa-edit" aria-hidden="true"></i>
            </button>
          </div>
          <div class="field-note">Keep your current residence updated for student communications.</div>
        </div>
      </div>
      <div class="buttons" id="student-profile-actions">
        <button onclick="saveChanges()">SAVE CHANGES</button>
        <button type="button" onclick="window.history.back();" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">BACK</button>
      </div>
    </div>
  </div>

<!-- Success Modal -->
<div id="successModal" class="modal">
  <div class="modal-content success-modal">
    <div class="modal-icon">
      <img src="../pix/account.png" alt="Success" style="width: 40px; height: 40px; filter: brightness(0) invert(1);">
    </div>
    <div class="modal-title">Profile Updated Successfully</div>
    <div class="modal-message">Your profile has been updated.</div>
    <button class="modal-button" onclick="closeModal('successModal')">OK</button>
  </div>
</div>

<div id="feedbackModal" class="modal">
  <div class="modal-content error-modal">
    <div class="modal-icon" id="feedbackModalIcon">!</div>
    <div class="modal-title" id="feedbackModalTitle">Something went wrong</div>
    <div class="modal-message" id="feedbackModalMessage">Please try again.</div>
    <button class="modal-button" onclick="closeModal('feedbackModal')">OK</button>
  </div>
</div>

  <script>
    function previewImage(event) {
      const file = event.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          document.getElementById('profile-pic').src = e.target.result;
        };
        reader.readAsDataURL(file);
      }
    }

    function toggleEdit(fieldId) {
      const field = document.getElementById(fieldId);
      field.disabled = !field.disabled;
      if (!field.disabled) field.focus();
    }

    function showSuccessModal() {
  document.getElementById('successModal').style.setProperty('display', 'block', 'important');
}

function closeModal(modalId) {
  document.getElementById(modalId).style.setProperty('display', 'none', 'important');
}

    function showFeedbackModal(type, title, message) {
      const modal = document.getElementById('feedbackModal');
      const modalContent = modal.querySelector('.modal-content');
      const icon = document.getElementById('feedbackModalIcon');
      const titleNode = document.getElementById('feedbackModalTitle');
      const messageNode = document.getElementById('feedbackModalMessage');

      modalContent.className = 'modal-content ' + (type === 'success' ? 'success-modal' : 'error-modal');
      icon.textContent = type === 'success' ? 'OK' : '!';
      titleNode.textContent = title;
      messageNode.textContent = message;
      modal.style.setProperty('display', 'block', 'important');
    }

    function saveChanges() {
      const formData = new FormData();
      formData.append("student_id", "<?= $student_id ?>");
      formData.append("csrf_token", "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>");
      formData.append("last_name", document.getElementById("last_name").value);
      formData.append("first_name", document.getElementById("first_name").value);
      formData.append("middle_name", document.getElementById("middle_name").value);
      formData.append("email", document.getElementById("email").value);
      formData.append("contact_no", document.getElementById("contact_no").value);
      formData.append("address", document.getElementById("address").value);

      const fileInput = document.getElementById("file-input");
      if (fileInput.files.length > 0) {
        formData.append("picture", fileInput.files[0]);
        console.log('Picture file:', fileInput.files[0]);
      } else {
        console.log('No picture selected');
      }

      fetch("save_profile.php", {
        method: "POST",
        body: formData,
      })
        .then(response => response.text())
        .then(text => {
          console.log('Server response:', text);
          let data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            showFeedbackModal('error', 'Profile Update Failed', 'The server returned an unexpected response. Please try again.');
            return;
          }
          if (data.success) {
            showSuccessModal();
            setTimeout(() => location.reload(), 1500);
          } else {
            showFeedbackModal('error', 'Profile Update Failed', data.message || 'Unable to update your profile right now.');
          }
        })
        .catch(error => {
          console.error("Error:", error);
          showFeedbackModal('error', 'Profile Update Failed', 'A network error occurred while saving your profile.');
        });
    }

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
          sidebar && !sidebar.contains(event.target) && 
          (!menuToggle || !menuToggle.contains(event.target)) &&
          (!logo || !logo.contains(event.target))) {
        sidebar.classList.add('collapsed');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
          mainContent.classList.add('expanded');
        }
      }
    });

    // Initialize sidebar state on page load
    window.addEventListener('DOMContentLoaded', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.querySelector('.main-content');
      
      if (window.innerWidth <= 768) {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('expanded');
      } else {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
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
      } else {
        // On mobile, keep sidebar collapsed
        sidebar.classList.add('collapsed');
        mainContent.classList.add('expanded');
      }
    });
  </script>
</body>
</html>
