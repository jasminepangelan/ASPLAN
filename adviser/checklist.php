<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/vite_legacy.php';
$csrfToken = getCSRFToken();

// Check if the adviser is logged in
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

// Get adviser name for header display
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

// Database connection
$conn = getDBConnection();
// Get student_id from URL parameter instead of session
if (isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];
    
    // Fetch student details for this specific student
    $stmt = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, program, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date FROM student_info WHERE student_number = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    
    if ($student_result->num_rows > 0) {
        $student_data = $student_result->fetch_assoc();
        // Assign values from database instead of session
        $last_name = htmlspecialchars($student_data['last_name'] ?? '');
        $first_name = htmlspecialchars($student_data['first_name'] ?? '');
        $middle_name = htmlspecialchars($student_data['middle_name'] ?? '');
        $contact_no = htmlspecialchars($student_data['contact_no'] ?? '');
        $address = htmlspecialchars($student_data['address'] ?? '');
        $admission_date = htmlspecialchars($student_data['admission_date'] ?? ''); 
        $student_program = $student_data['program'] ?? '';
        
        // Format full name properly handling missing middle name
        $full_name = $last_name . ", " . $first_name . (!empty($middle_name) ? " " . $middle_name : ""); 
    } else {
        die("Student not found");
    }
} else {
    die("No student ID provided");
}

// Normalize the program name (trim whitespace)
$student_program_normalized = trim($student_program);

function normalizeProgramLabel($value) {
  $value = strtoupper(trim((string)$value));
  $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
  return trim(preg_replace('/\s+/', ' ', $value));
}

function resolveProgramAbbreviation($programName) {
  $normalized = normalizeProgramLabel($programName);
  if ($normalized === '') {
    return null;
  }

  $abbrAliases = [
    'BSBA MM' => 'BSBA-MM',
    'BSBA HRM' => 'BSBA-HRM',
    'BSCPE' => 'BSCpE',
    'BSCS' => 'BSCS',
    'BSHM' => 'BSHM',
    'BSINDT' => 'BSIndT',
    'BSIT' => 'BSIT',
    'BSED ENGLISH' => 'BSEd-English',
    'BSED MATH' => 'BSEd-Math',
    'BSED SCIENCE' => 'BSEd-Science',
  ];
  if (isset($abbrAliases[$normalized])) {
    return $abbrAliases[$normalized];
  }

  if (strpos($normalized, 'BUSINESS ADMINISTRATION') !== false && strpos($normalized, 'MARKETING') !== false) {
    return 'BSBA-MM';
  }
  if (strpos($normalized, 'BUSINESS ADMINISTRATION') !== false &&
    (strpos($normalized, 'HUMAN RESOURCE') !== false || strpos($normalized, 'HRM') !== false)) {
    return 'BSBA-HRM';
  }

  $programMap = [
    'Bachelor of Science in Business Administration - Major in Marketing Management' => 'BSBA-MM',
    'Bachelor of Science in Business Administration - Major in Human Resource Management' => 'BSBA-HRM',
    'Bachelor of Science in Computer Engineering' => 'BSCpE',
    'Bachelor of Science in Computer Science' => 'BSCS',
    'Bachelor of Science in Hospitality Management' => 'BSHM',
    'Bachelor of Science in Industrial Technology' => 'BSIndT',
    'Bachelor of Science in Information Technology' => 'BSIT',
    'Bachelor of Secondary Education major in English' => 'BSEd-English',
    'Bachelor of Secondary Education major Math' => 'BSEd-Math',
    'Bachelor of Secondary Education major in Science' => 'BSEd-Science',
  ];

  foreach ($programMap as $label => $abbr) {
    if (normalizeProgramLabel($label) === $normalized) {
      return $abbr;
    }
  }

  return null;
}

$program_abbr = resolveProgramAbbreviation($student_program_normalized);
if ($program_abbr === null) {
  $program_abbr = '';
}

$adviserShellPayload = htmlspecialchars(json_encode([
    'title' => 'Student Checklist Review',
    'description' => 'Review academic standing, validate course attempts, and keep the adviser checklist workflow moving without changing the underlying evaluation logic.',
    'accent' => 'evergreen',
    'pageKey' => 'student-list',
    'stats' => [
        ['label' => 'Adviser', 'value' => html_entity_decode($adviser_name, ENT_QUOTES, 'UTF-8')],
        ['label' => 'Student ID', 'value' => (string) $student_id],
        ['label' => 'Program', 'value' => (string) ($program_abbr !== '' ? $program_abbr : $student_program_normalized)],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$adviserChecklistWorkspacePayload = htmlspecialchars(json_encode([
    'heading' => 'Checklist command deck',
    'description' => 'Use these shortcuts to move between the student list, print the checklist, and jump directly to the academic details already rendered by the legacy page.',
    'actions' => [
        ['key' => 'back', 'title' => 'Back to student list', 'description' => 'Return to the adviser list of students and choose another record.', 'href' => 'checklist_eval.php'],
        ['key' => 'print', 'title' => 'Print checklist', 'description' => 'Open the browser print flow for the current checklist view.', 'type' => 'print'],
        ['key' => 'overview', 'title' => 'Jump to overview', 'description' => 'Scroll to the student information summary at the top of the checklist.', 'type' => 'scroll', 'selector' => '.info'],
        ['key' => 'table', 'title' => 'Jump to checklist table', 'description' => 'Move directly to the subject matrix below the profile summary.', 'type' => 'scroll', 'selector' => '.table-wrapper'],
    ],
    'notes' => [
        'All grade handling and evaluator actions still use the original PHP and JavaScript logic.',
        'This shell only adds quicker navigation for adviser review work.',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');


// Helper: returns true when a grade is failing and unlocks the next attempt column
function isFailingGrade($grade) {
    return in_array(trim($grade ?? ''), ['4.00', '5.00', 'Failed', 'INC', 'DRP', 'US']);
}

// Updated SQL query
$all_courses = [];
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/checklist/view',
        [
            'student_id' => $student_id,
            'program_view' => $program_abbr,
        ]
    );

    if (is_array($bridgeData) && ($bridgeData['status'] ?? '') === 'success' && isset($bridgeData['courses']) && is_array($bridgeData['courses'])) {
        $all_courses = $bridgeData['courses'];
    }
}

if (empty($all_courses)) {
    $all_courses = psFetchChecklistCourses(
        $conn,
        (string)$student_id,
        (string)$student_program_normalized,
        (string)$program_abbr
    );
}

// Optional: You can also fetch additional student details here if needed




?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Checklist - Adviser</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <style>
    /* Notification styles */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes fadeOut {
        from {
            opacity: 1;
        }
        to {
            opacity: 0;
        }
    }

    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        background: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 1000;
        animation: slideInRight 0.4s ease-out, fadeOut 0.4s ease-out 2s forwards;
        font-family: Arial, sans-serif;
    }

    .notification.success {
        border-left: 4px solid #4CAF50;
    }

    .notification.error {
        border-left: 4px solid #f44336;
    }

    .notification-icon {
        width: 24px;
        height: 24px;
        flex-shrink: 0;
    }

    .notification-content {
        display: flex;
        flex-direction: column;
    }

    .notification-title {
        font-weight: 600;
        font-size: 16px;
        color: #333;
        margin-bottom: 4px;
    }

    .notification-message {
        font-size: 14px;
        color: #666;
    }

    /* Existing body styles */
    body {
        font-family: Arial, sans-serif;
        background-color: #f5f7f4;
        background: #f5f7f4;
        margin: 0;
        padding: 0;
        padding-top: 50px;
        min-height: 100vh;
    }
    :root {
      --sidebar-width: 250px;
      --content-gap: 28px;
      --action-rail-width: 190px;
    }
    .container {
        width: min(1200px, 100%);
        margin: 16px auto 40px;
        padding: 20px;
        background-color: #fff;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        border-radius: 5px;
        overflow: visible;
    }
    .header {
        text-align: center;
        margin-bottom: 20px;
        position: relative;
        padding-top: 10px;
        min-height: 180px;
        overflow: visible;
    }
    .CvSUlogo .logo img {
      width: 85px;
      height: 85px;
      position: relative;
      left: 100px;
      top: -190px;
    }
    .header h1 {
        margin-top: 5px;
        font-size: 9px;
    }
    .header h2 {
        margin-bottom: 1px 0;
        font-size: 9px;
    }
    .header h3 {
        margin-top: 30px;
        font-size: 9px;
    }
    .info {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        margin-top: -150px;
        margin-bottom: 10px;
    }
    .info-left {
        width: 45%;
        margin-left: 50px;
    }
    .info-right {
        width: 30%;
        text-wrap: nowrap;
        position: relative;
        left: -70px;
    }
    table {
        width: 100%;
        height: auto;
        position: relative;
        top: -10px;
        border-collapse: collapse;
        margin-bottom: 20px;
        font-size: 11px;
    }
    th, td {
        border: 1px solid #000;
        padding: 4.50px;
        text-align: center;
        font-size: 8px;
    }
    th {
        background-color: #f2f2f2;
    }
    .semester-title {
        text-align: center;
        font-weight: bold;
        background-color: #f2f2f2;
        
    }
    .total {
        font-weight: bold;
    }

    select[name^="final_grade"],
    select[name^="evaluator_remarks"] {
        transition: background-color 0.3s ease;
    }

    select[name^="final_grade"]:disabled,
    select[name^="evaluator_remarks"]:disabled {
        background-color: #f5f5f5;
        cursor: not-allowed;
    }

    /* Title bar styling */
    .title-bar {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #fff;
      padding: 6px 15px;
      text-align: left;
      font-size: 18px;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 1000;
      height: 44px;
      box-sizing: border-box;
    }

    .title-content {
      display: flex;
      align-items: center;
    }

    .adviser-name {
      position: absolute;
      right: 20px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 18px;
      font-weight: 600;
      color: #facc41;
      font-family: 'Segoe UI', Arial, sans-serif;
      letter-spacing: 1px;
      background: rgba(32,96,24,0.15);
      padding: 6px 18px;
      border-radius: 14px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border: 1px solid rgba(250, 204, 65, 0.3);
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

    .title-bar img {
      height: 32px;
      width: auto;
      margin-right: 12px;
      vertical-align: middle;
    }

    /* Sidebar styling */
    .sidebar {
      width: var(--sidebar-width);
      height: calc(110vh - 46px);
      background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
      color: white;
      position: fixed;
      left: 0;
      top: 44px;
      padding: 20px 0;
      box-shadow: 2px 0 5px rgba(0,0,0,0.1);
      overflow-y: auto;
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

    /* Main content styling */
    .main-content {
      margin-left: var(--sidebar-width);
      min-height: calc(100vh - 46px);
      width: calc(100vw - var(--sidebar-width));
      overflow-x: auto;
      transition: margin-left 0.3s ease, width 0.3s ease;
      padding: 24px calc(var(--action-rail-width) + var(--content-gap)) 32px var(--content-gap);
      box-sizing: border-box;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      gap: 0;
    }

    .main-content.expanded {
      margin-left: 0;
      width: 100vw;
      padding-left: var(--content-gap);
      padding-right: calc(var(--action-rail-width) + var(--content-gap));
    }

    /* Container wrapper */
    .container {
      flex: 0 1 1200px;
    }

    /* Action buttons panel */
    .action-buttons {
      position: fixed;
      right: 28px;
      top: 74px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      width: 140px;
      z-index: 998;
    }

    .action-buttons button {
      padding: 12px 16px;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      width: 100%;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      transition: all 0.3s ease;
      white-space: nowrap;
    }

    .action-buttons button:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .action-buttons .btn-primary {
      background-color: #4CAF50;
    }

    .action-buttons .btn-primary:hover {
      background-color: #45a049;
    }

    .action-buttons .btn-secondary {
      background-color: #2196F3;
    }

    .action-buttons .btn-secondary:hover {
      background-color: #0b7dda;
    }

    /* Specific styling for Approve Multiple button */
    .action-buttons #showApproveMultiple {
      white-space: normal;
      word-wrap: break-word;
      line-height: 1.2;
      min-height: 45px;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    /* Responsive design */
    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
        z-index: 1001;
        top: 44px;
        height: calc(100vh - 44px);
      }

      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
        width: 100vw;
        padding: 10px 5px;
        flex-direction: row;
        justify-content: flex-start;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }

      /* Keep action buttons on the right side on mobile */
      .action-buttons {
        position: fixed;
        top: 45px;
        right: 5px;
        display: flex;
        flex-direction: column;
        gap: 5px;
        width: 60px;
        z-index: 998;
      }

      .action-buttons button {
        width: 100%;
        font-size: 9px;
        padding: 8px 4px;
        white-space: normal;
        word-wrap: break-word;
        line-height: 1.2;
      }

      .title-bar {
        padding: 5px 8px;
        font-size: 12px;
        height: 36px;
      }

      .title-bar img {
        height: 22px !important;
        margin-right: 6px !important;
      }

      .adviser-name {
        font-size: 10px;
        padding: 4px 8px;
        right: 10px;
      }

      .container {
        width: calc(100vw - 20px);
        max-width: none;
        padding: 15px 5px;
        margin: 10px 0;
        flex: 0 0 auto;
      }

      .header {
        min-height: auto;
        padding-top: 60px;
      }

      .header h1, .header h2, .header h3 {
        font-size: 8px;
      }

      .CvSUlogo .logo img {
        width: 60px;
        height: 60px;
        left: 50px;
        top: -120px;
      }

      .info {
        flex-direction: row;
        justify-content: space-between;
        margin-top: -80px;
        margin-left: -5px;
        margin-right: -5px;
        font-size: 8px;
        gap: 10px;
      }

      .info-left {
        text-align: left;
        flex: 1;
        padding-left: 5px;
        margin-left: 0;
        width: auto !important;
      }

      .info-right {
        text-align: right;
        flex: 1;
        padding-right: 5px;
        margin-right: 0;
        width: auto !important;
      }

      .info-right p {
        margin: 2px 0 2px auto;
        word-wrap: break-word;
        line-height: 1.3;
        text-align: right;
        padding-right: 0;
        display: block;
        width: 100%;
      }

      .info-right p strong {
        display: inline-block;
        text-align: right;
        width: 100%;
      }

      .info-left p {
        margin: 2px 0;
        word-wrap: break-word;
        line-height: 1.3;
        text-align: left;
        padding-left: 0;
      }

      .info p {
        margin: 2px 0;
        word-wrap: break-word;
        line-height: 1.3;
      }

      .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -5px;
        padding: 0 5px;
        max-width: 100%;
      }

      table {
        width: 100%;
        max-width: 100%;
        min-width: 650px;
        border-collapse: collapse;
        margin-bottom: 15px;
        font-size: 9px;
      }

      th, td {
        font-size: 8px;
        padding: 3px 2px;
        border: 1px solid #000;
        word-wrap: break-word;
      }

      th {
        font-size: 8px;
        background-color: #f2f2f2;
        line-height: 1.2;
      }

      input[type="text"],
      select {
        font-size: 8px !important;
        min-width: 70px;
        padding: 2px;
      }

      .semester-title td {
        font-size: 9px;
        font-weight: bold;
        padding: 4px;
      }

      #bulkApproveButton {
        font-size: 11px !important;
        padding: 6px 12px !important;
        width: calc(100% - 20px);
        max-width: 300px;
      }
    }

    @media (max-width: 480px) {
      .title-bar {
        font-size: 10px;
        padding: 4px 6px;
        height: 32px;
      }

      .title-bar img {
        height: 20px !important;
        margin-right: 4px !important;
      }

      .adviser-name {
        font-size: 8px;
        padding: 2px 5px;
      }

      .sidebar {
        top: 32px;
        height: calc(100vh - 32px);
      }

      .container {
        padding: 10px 5px;
      }

      .action-buttons {
        top: 36px;
        right: 5px;
        width: 55px;
      }

      .action-buttons button {
        font-size: 8px;
        padding: 6px 3px;
      }

      table {
        font-size: 6px;
      }

      th, td {
        font-size: 6px;
        padding: 2px 1px;
      }
    }

    @media print {
      @page {
        size: A4 portrait;
        margin: 8mm 6mm 8mm 6mm;
      }

      * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
      }

      body {
        background: #fff !important;
        background-image: none !important;
        margin: 0 !important;
        padding: 0 !important;
        min-height: auto !important;
        overflow: visible !important;
        font-size: 9px !important;
      }

      .title-bar,
      .sidebar,
      .action-buttons,
      .notification,
      .menu-toggle,
      .adviser-react-shell-slot,
      .adviser-react-workspace-slot,
      #bulkApproveButton,
      #approveColHeader,
      .approve-col,
      .semester-selectall-row {
        display: none !important;
      }

      .main-content {
        display: block !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        min-height: auto !important;
        overflow: visible !important;
      }

      .container {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        overflow: visible !important;
      }

      .table-wrapper {
        overflow: visible !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      .CvSUlogo .logo img {
        display: none !important;
      }

      .header {
        min-height: auto !important;
        margin-bottom: 12px !important;
        padding-top: 0 !important;
      }

      .header h1 {
        font-size: 10px !important;
        margin-top: 3px !important;
      }

      .header h2 {
        font-size: 10px !important;
      }

      .header h3 {
        font-size: 11px !important;
        margin-top: 10px !important;
      }

      .info {
        margin-top: 12px !important;
        margin-bottom: 12px !important;
        font-size: 10px !important;
      }

      .info-left {
        width: 48% !important;
        margin-left: 0 !important;
      }

      .info-right {
        width: 34% !important;
        left: 0 !important;
      }

      table {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        top: 0 !important;
        margin: 0 auto 16px auto !important;
        border-collapse: collapse !important;
        font-size: 8px !important;
        table-layout: auto !important;
        page-break-inside: auto !important;
      }

      thead {
        display: table-header-group !important;
      }

      tr {
        page-break-inside: avoid !important;
      }

      th,
      td {
        border: 1px solid #000 !important;
        padding: 3px 4px !important;
        font-size: 8px !important;
        word-wrap: break-word !important;
      }

      th {
        background-color: #f2f2f2 !important;
      }

      .semester-title td {
        background-color: #f2f2f2 !important;
        font-weight: bold !important;
        font-size: 9px !important;
      }

      input[type="text"] {
        border: none !important;
        border-bottom: 1px solid #000 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 0 !important;
        font-size: 8px !important;
      }

      select {
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        appearance: none !important;
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 0 !important;
        font-size: 8px !important;
      }
    }
  </style>
  <?= renderLegacyViteTags([
      'resources/js/adviser-shell.jsx',
      'resources/js/adviser-checklist-workspace.jsx',
  ], '../laravel-app/public/build/') ?>
</head>
<body>
  <!-- Title Bar -->
  <div class="title-bar">
    <div class="title-content">
      <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" style="cursor: pointer;" onclick="toggleSidebar()">
      <span style="color: #d9e441;">ASPLAN</span>
    </div>
    <div class="adviser-name"><?= $adviser_name ; echo " | Adviser " ?></div>
    <div class="icon-bar">
    </div>
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
  <div class="main-content">
    <div class="adviser-react-shell-slot" data-adviser-shell="<?= $adviserShellPayload ?>"></div>
    <div class="adviser-react-workspace-slot" data-adviser-checklist-workspace="<?= $adviserChecklistWorkspacePayload ?>"></div>
    
    <div class="container">
        <div class="header">
            <h1>Republic of the Philippines</h1>
            <h2>CAVITE STATE UNIVERSITY - CARMONA</h2>
            <h2>Carmona Cavite</h2>
            <h3><?= strtoupper($student_program) ?></h3>
        </div>
        <div class="CvSUlogo">
            <div class="logo">
              <img src="../img/cav.png" alt="CvSU Logo" height="150"/>
            </div>
        </div>
        <div class="info">
            <div class="info-left">
                <p><strong>Name: <?= htmlspecialchars($full_name) ?></p>
                <p><strong>Student #: <?= htmlspecialchars("$student_id") ?></p>
                <p><strong>Address: <?= htmlspecialchars("$address") ?></p>
            </div>
            <div class="info-right">
                <p><strong>Admission Date: <?= htmlspecialchars("$admission_date") ?></p>
                <p><strong>Contact #: <?= htmlspecialchars("$contact_no") ?></p>
                <p><strong>Adviser: <input type="text"  style="border: none; font-size: 8px; border-bottom: 1px solid #000; width: 140px;" readonly></strong></p>
            </div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">COURSE CODE</th>
                        <th rowspan="2">COURSE TITLE</th>
                        <th colspan="2">CREDIT UNIT</th>
                        <th colspan="2">CONTACT HRS</th>
                        <th rowspan="2">PRE<br>REQUISITE</th>
                        <th rowspan="2">SEM/YR<br>TAKEN</th>
                        <th rowspan="2">PROFESSOR/<br>INSTRUCTOR</th>
                        <th colspan="3">FINAL<br>GRADE</th>
                        <th rowspan="2">EVALUATOR<br>REMARKS</th>
                        <th rowspan="2" id="approveColHeader" style="display:none">Approve</th>
                    </tr>
                    <tr>
                        <th>Lec</th>
                        <th>Lab</th>
                        <th>Lec</th>
                        <th>Lab</th>
                        <th style="font-size:8px;">1st</th>
                        <th style="font-size:8px;">2nd<br>Attempt</th>
                        <th style="font-size:8px;">3rd<br>Attempt</th>
                    </tr>
                </thead>
                <tbody>
                <?php
    $currentSemester = "";
    $currentYear = "";

    if (!empty($all_courses)) {
        foreach ($all_courses as $row) {
            if ($currentYear != ($row['year'] ?? '') || $currentSemester != ($row['semester'] ?? '')) {
                $semesterKey = ($row['year'] ?? '') . '-' . ($row['semester'] ?? '');
                echo "<tr class='semester-title'>
                        <td colspan='12' style='text-align:center;'>
                            <span>" . htmlspecialchars((string)($row['year'] ?? '')) . " - " . htmlspecialchars((string)($row['semester'] ?? '')) . "</span>
                        </td>
                        <td style='text-align:center; vertical-align:middle; padding:0;'>
                            <div class='semester-selectall-row' style='display:none;'>
                                <input type='checkbox' class='semester-approve-checkbox' data-semester='" . htmlspecialchars($semesterKey) . "' style='width:16px; height:16px; vertical-align:middle;' title='Select all'>
                                <label style='font-size:10px; margin-left:2px;'>Select all</label>
                            </div>
                        </td>
                    </tr>";
                $currentYear = $row['year'] ?? '';
                $currentSemester = $row['semester'] ?? '';
            }

            $grade1_val  = $row['final_grade'] ?? '';
            $grade2_val  = $row['final_grade_2'] ?? '';
            $grade3_val  = $row['final_grade_3'] ?? '';
            $remark1_val = $row['evaluator_remarks'] ?? '';
            $remark2_val = $row['evaluator_remarks_2'] ?? '';
            $remark3_val = $row['evaluator_remarks_3'] ?? '';
            $effectiveRemark = ($remark1_val === 'Pending' || $remark2_val === 'Pending' || $remark3_val === 'Pending')
                ? 'Pending'
                : $remark1_val;
            $show_2nd    = isFailingGrade($grade1_val);
            $show_3rd    = $show_2nd && isFailingGrade($grade2_val);
            $grade_opts  = ['', 'No Grade', '1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '4.00', '5.00', 'Passed', 'Failed', 'US', 'S', 'INC', 'DRP'];
            $remark_opts = ['', 'Approved', 'Pending', 'Disapproved'];
            $courseCode = (string)($row['course_code'] ?? '');
            echo "<tr data-semester='" . htmlspecialchars((string)$currentYear . '-' . (string)$currentSemester) . "'>
                    <td>" . htmlspecialchars($courseCode) . "</td>
                    <td>" . htmlspecialchars((string)($row['course_title'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($row['credit_unit_lec'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($row['credit_unit_lab'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($row['contact_hrs_lec'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($row['contact_hrs_lab'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($row['pre_requisite'] ?? '')) . "</td>
                    <td>" . htmlspecialchars((string)($row['semester'] ?? '')) . " " . htmlspecialchars((string)($row['year'] ?? '')) . "</td>
                    <td><input type='text' name='professor_instructor[" . htmlspecialchars($courseCode) . "]' value='" . (!empty($row['professor_instructor']) ? htmlspecialchars((string)$row['professor_instructor']) : "") . "' style='border: none; font-size: 8px; border-bottom: 1px solid #000; width: 100px;'></td>";
            echo "<td id='grade1_" . htmlspecialchars($courseCode) . "'><select name='final_grade[" . htmlspecialchars($courseCode) . "]' style='border:none;font-size:8px;width:80px;'>";
            foreach ($grade_opts as $g) {
                echo "<option value='" . htmlspecialchars((string)$g) . "'" . ($g === $grade1_val ? ' selected' : '') . ">" . ($g ?: '-- Select --') . "</option>";
            }
            echo "</select></td>";
            echo "<td id='grade2_" . htmlspecialchars($courseCode) . "'>";
            if ($show_2nd) {
                echo "<select name='final_grade_2[" . htmlspecialchars($courseCode) . "]' style='border:none;font-size:8px;width:70px;'>";
                foreach ($grade_opts as $g) {
                    echo "<option value='" . htmlspecialchars((string)$g) . "'" . ($g === $grade2_val ? ' selected' : '') . ">" . ($g ?: '-- Select --') . "</option>";
                }
                echo "</select>";
            } else {
                echo "<span style='color:#ccc;font-size:10px;'>&#8212;</span>";
            }
            echo "</td>";
            echo "<td id='grade3_" . htmlspecialchars($courseCode) . "'>";
            if ($show_3rd) {
                echo "<select name='final_grade_3[" . htmlspecialchars($courseCode) . "]' style='border:none;font-size:8px;width:70px;'>";
                foreach ($grade_opts as $g) {
                    echo "<option value='" . htmlspecialchars((string)$g) . "'" . ($g === $grade3_val ? ' selected' : '') . ">" . ($g ?: '-- Select --') . "</option>";
                }
                echo "</select>";
            } else {
                echo "<span style='color:#ccc;font-size:10px;'>&#8212;</span>";
            }
            echo "</td>";
            echo "<td><select name='evaluator_remarks[" . htmlspecialchars($courseCode) . "]' style='border:none;font-size:8px;width:100px;'>";
            foreach ($remark_opts as $ro) {
                echo "<option value='" . htmlspecialchars((string)$ro) . "'" . ($ro === $effectiveRemark ? ' selected' : '') . ">" . htmlspecialchars((string)$ro) . "</option>";
            }
            echo "</select></td>";
            echo "<td style='display:none' class='approve-col'><input type='checkbox' class='approve-checkbox' value='" . htmlspecialchars($courseCode) . "'></td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='13'>No courses available.</td></tr>";
    }
    ?>
            </tbody>
        </table>
        </div>
        <button id="bulkApproveButton" style="display:none; margin-left: 38px; margin-bottom: 20px; padding: 8px 16px; background-color: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer;">Approve Selected Grades</button>

    </div>

    <!-- Action Buttons Panel -->
    <div class="action-buttons">
      <button id="downloadPDF" class="btn-primary">Print</button>
      <button class="btn-primary" onclick="window.location.href='checklist_eval.php'">Back</button>
      <button id="saveButton" class="btn-primary">Save</button>
      <button id="showApproveMultiple" class="btn-secondary">Approve Multiple</button>
    </div>

  </div>

<script>
// Show approve checkboxes and bulk approve button when Approve Multiple is clicked
let approveMultipleActive = false;
function applyApproveMultipleViewState() {
    document.querySelectorAll('.approve-col').forEach(function(td) {
        td.style.display = approveMultipleActive ? '' : 'none';
        if (!approveMultipleActive) {
            var cb = td.querySelector('.approve-checkbox');
            if (cb) cb.checked = false;
        }
    });
    document.getElementById('bulkApproveButton').style.display = approveMultipleActive ? '' : 'none';
    // Show/hide semester select all rows
    document.querySelectorAll('.semester-selectall-row').forEach(function(row) {
        row.style.display = approveMultipleActive ? '' : 'none';
        if (!approveMultipleActive) {
            var cb = row.querySelector('.semester-approve-checkbox');
            if (cb) cb.checked = false;
        }
    });
    // Toggle Approve column header
    var approveHeader = document.getElementById('approveColHeader');
    if (approveHeader) {
        approveHeader.style.display = approveMultipleActive ? '' : 'none';
    }
}

document.getElementById('showApproveMultiple').addEventListener('click', function() {
    approveMultipleActive = !approveMultipleActive;
    applyApproveMultipleViewState();
    if (approveMultipleActive) {
        document.querySelector('table').scrollIntoView({behavior: 'smooth'});
    }
});

// Semester approve checkbox logic
function setSemesterApproved(semesterKey, checked) {
    // Check/uncheck all approve-checkboxes for this semester, but only for rows with grades
    document.querySelectorAll(`tr[data-semester='${semesterKey}'] .approve-checkbox`).forEach(function(cb) {
        let courseCode = cb.value;
        let gradeSelect = document.querySelector(`[name='final_grade[${courseCode}]']`);
        
        // Only check/uncheck if the row has a grade filled in
        if (gradeSelect && gradeSelect.value && gradeSelect.value.trim() !== '') {
            cb.checked = checked;
            // Set evaluator remarks to Approved if checked, else leave as is
            let remarksSelect = document.querySelector(`[name='evaluator_remarks[${courseCode}]']`);
            if (remarksSelect && checked) {
                remarksSelect.value = 'Approved';
            }
        } else {
            // If no grade, ensure checkbox is unchecked
            cb.checked = false;
        }
    });
}
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('semester-approve-checkbox')) {
        setSemesterApproved(e.target.dataset.semester, e.target.checked);
    }
});

// Hide approve checkboxes and bulk approve button after approval
    function hideApproveCheckboxes() {
        document.querySelectorAll('.approve-checkbox').forEach(function(cb) {
            cb.checked = false;
            cb.parentElement.style.display = 'none';
        });
        document.getElementById('bulkApproveButton').style.display = 'none';
    }

    // Toggle sidebar function
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

    // Save button functionality
    document.getElementById('saveButton').addEventListener('click', function() {
    
    let formData = new FormData();

    // Create arrays (only iterate 1st-attempt selects to avoid duplicates from grade_2/grade_3)
    let courses = [];
    let final_grades = [];
    let final_grades_2 = [];
    let final_grades_3 = [];
    let remarks = [];
    let professors = [];

    document.querySelectorAll('[name^="final_grade"]').forEach(function(course) {
        if (!/^final_grade\[/.test(course.name)) return; // skip final_grade_2 and final_grade_3
        let courseCode = course.name.match(/\[(.*?)\]/)[1];
        let finalGrade = course.value;
        let evaluatorRemark = document.querySelector(`[name="evaluator_remarks[${courseCode}]"]`).value;
        let professorInstructor = document.querySelector(`[name="professor_instructor[${courseCode}]"]`).value;
        let grade2el = document.querySelector(`[name="final_grade_2[${courseCode}]"]`);
        let grade3el = document.querySelector(`[name="final_grade_3[${courseCode}]"]`);

        // If checkbox for this course is checked, set remark to Approved
        let checkbox = document.querySelector(`.approve-checkbox[value="${courseCode}"]`);
        if (checkbox && checkbox.checked) {
            evaluatorRemark = 'Approved';
        }
        courses.push(courseCode);
        final_grades.push(finalGrade);
        final_grades_2.push(grade2el ? grade2el.value : '');
        final_grades_3.push(grade3el ? grade3el.value : '');
        remarks.push(evaluatorRemark);
        professors.push(professorInstructor);
    });

    // Append arrays to FormData
    formData.append('student_id', '<?php echo $_GET['student_id']; ?>');
    formData.append('courses', JSON.stringify(courses));
    formData.append('final_grades', JSON.stringify(final_grades));
    formData.append('final_grades_2', JSON.stringify(final_grades_2));
    formData.append('final_grades_3', JSON.stringify(final_grades_3));
    formData.append('evaluator_remarks', JSON.stringify(remarks));
    formData.append('professor_instructors', JSON.stringify(professors));
    formData.append('csrf_token', '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8"); ?>');

    fetch('../save_checklist.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.status === 'success') {
                showNotification('success', 'Success', 'Your changes have been saved successfully');
                setTimeout(() => location.reload(), 1500);
            } else {
                showNotification('error', 'Error', 'Failed to save data: ' + (data.message || 'Unknown error'));
                console.error('Error data:', data);
            }
        } catch (e) {
            console.error('JSON parse error:', e);
            showNotification('error', 'Error', 'Server returned invalid response');
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        showNotification('error', 'Error', 'An unexpected error occurred while saving: ' + error.message);
    });
});

// Function to fetch and update checklist data
let isChecklistRefreshRunning = false;
let checklistLiveRefreshTimer = null;

function bindChecklistFieldListeners(root = document) {
    root.querySelectorAll('[name^="final_grade"]').forEach(function(gradeSelect) {
        if (gradeSelect.dataset.liveBound === '1') return;
        gradeSelect.dataset.liveBound = '1';
        gradeSelect.addEventListener('change', function() {
            let courseCode = this.name.match(/\[(.*?)\]/)[1];
            autoSaveGrade(courseCode);
        });
    });

    root.querySelectorAll('[name^="evaluator_remarks"]').forEach(function(remarksSelect) {
        if (remarksSelect.dataset.liveBound === '1') return;
        remarksSelect.dataset.liveBound = '1';
        remarksSelect.addEventListener('change', function() {
            let courseCode = this.name.match(/\[(.*?)\]/)[1];
            autoSaveGrade(courseCode);
        });
    });
}

function fetchAndUpdateChecklist() {
    if (isChecklistRefreshRunning) {
        return;
    }

    isChecklistRefreshRunning = true;

    const separator = window.location.search.includes('?') ? '&' : '?';
    const requestUrl = `${window.location.pathname}${window.location.search}${separator}_=${Date.now()}`;

    fetch(requestUrl, {
        cache: 'no-store',
        headers: {
            'Cache-Control': 'no-cache',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const nextDocument = parser.parseFromString(html, 'text/html');
            const nextWrapper = nextDocument.querySelector('.table-wrapper');
            const currentWrapper = document.querySelector('.table-wrapper');

            if (nextWrapper && currentWrapper) {
                currentWrapper.innerHTML = nextWrapper.innerHTML;
                bindChecklistFieldListeners(currentWrapper);
                applyApproveMultipleViewState();
            }
        })
        .catch(error => console.error('Error fetching checklist data:', error))
        .finally(() => {
            isChecklistRefreshRunning = false;
        });
}

function startChecklistLiveRefresh() {
    if (checklistLiveRefreshTimer !== null) {
        return;
    }

    checklistLiveRefreshTimer = window.setInterval(() => {
        if (document.hidden || isSaving) {
            return;
        }

        fetchAndUpdateChecklist();
    }, 8000);
}

function stopChecklistLiveRefresh() {
    if (checklistLiveRefreshTimer === null) {
        return;
    }

    window.clearInterval(checklistLiveRefreshTimer);
    checklistLiveRefreshTimer = null;
}

function showNotification(type, title, message) {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    
    const icon = type === 'success' 
        ? `<svg class="notification-icon" viewBox="0 0 24 24" fill="none" stroke="#4CAF50" stroke-width="2">
             <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
           </svg>`
        : `<svg class="notification-icon" viewBox="0 0 24 24" fill="none" stroke="#f44336" stroke-width="2">
             <path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
           </svg>`;
    
    notification.innerHTML = `
        ${icon}
        <div class="notification-content">
            <div class="notification-title">${title}</div>
            <div class="notification-message">${message}</div>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.addEventListener('animationend', () => {
            notification.remove();
        });
    }, 2400); // Total duration: 2.4s (2s delay + 0.4s fade out)
}

// Auto-save functionality for final grades
let autoSaveTimeout;
let isSaving = false;

function autoSaveGrade(courseCode) {
    if (isSaving) {
        console.log('Auto-save already in progress, skipping...');
        return;
    }
    
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        console.log('Starting auto-save for course:', courseCode);
        isSaving = true;
        
        // Collect all course data (only 1st-attempt selects to avoid duplicates)
        let courses = [];
        let final_grades = [];
        let final_grades_2 = [];
        let final_grades_3 = [];
        let remarks = [];
        let professors = [];
        
        document.querySelectorAll('[name^="final_grade"]').forEach(function(gradeSelect) {
            if (!/^final_grade\[/.test(gradeSelect.name)) return; // skip grade_2 and grade_3
            let code = gradeSelect.name.match(/\[(.*?)\]/)[1];
            let finalGrade = gradeSelect.value;
            let evaluatorRemark = document.querySelector(`[name="evaluator_remarks[${code}]"]`).value;
            let professorInstructor = document.querySelector(`[name="professor_instructor[${code}]"]`).value;
            let grade2el = document.querySelector(`[name="final_grade_2[${code}]"]`);
            let grade3el = document.querySelector(`[name="final_grade_3[${code}]"]`);

            courses.push(code);
            final_grades.push(finalGrade);
            final_grades_2.push(grade2el ? grade2el.value : '');
            final_grades_3.push(grade3el ? grade3el.value : '');
            remarks.push(evaluatorRemark);
            professors.push(professorInstructor);
        });
        
        let formData = new FormData();
        formData.append('student_id', '<?php echo $_GET['student_id']; ?>');
        formData.append('courses', JSON.stringify(courses));
        formData.append('final_grades', JSON.stringify(final_grades));
        formData.append('final_grades_2', JSON.stringify(final_grades_2));
        formData.append('final_grades_3', JSON.stringify(final_grades_3));
        formData.append('evaluator_remarks', JSON.stringify(remarks));
        formData.append('professor_instructors', JSON.stringify(professors));
        formData.append('csrf_token', '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, "UTF-8"); ?>');
        
        fetch('../save_checklist.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Auto-save response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Auto-save response text:', text);
            try {
                const data = JSON.parse(text);
                isSaving = false;
                if (data.status === 'success') {
                    console.log('✓ Auto-saved successfully for ' + courseCode);
                    showNotification('success', 'Auto-saved', 'Final grade saved successfully');
                } else {
                    console.error('✗ Auto-save failed:', data.message);
                    showNotification('error', 'Auto-save Failed', data.message || 'Unable to save grade');
                }
            } catch (e) {
                isSaving = false;
                console.error('✗ Auto-save JSON parse error:', e);
                console.error('Response text:', text);
                showNotification('error', 'Auto-save Error', 'Server response error');
            }
        })
        .catch(error => {
            isSaving = false;
            console.error('✗ Auto-save fetch error:', error);
        });
    }, 1000);
}

// Attach auto-save listeners to all grade and remark selects
document.addEventListener('DOMContentLoaded', function() {
    startChecklistLiveRefresh();
    fetchAndUpdateChecklist();
    bindChecklistFieldListeners();
});

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopChecklistLiveRefresh();
    } else {
        fetchAndUpdateChecklist();
        startChecklistLiveRefresh();
    }
});

window.addEventListener('focus', function() {
    fetchAndUpdateChecklist();
});

// Bulk approve selected grades
document.getElementById('bulkApproveButton').addEventListener('click', function() {
    const checkedBoxes = document.querySelectorAll('.approve-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('Please select at least one course to approve.');
        return;
    }
    const selectedCourses = Array.from(checkedBoxes).map(cb => cb.value);
    const studentId = '<?php echo $_GET['student_id']; ?>';
    // Optionally, you can also send the current grade and professor values for each course
    let gradeData = {};
    let professorData = {};
    selectedCourses.forEach(courseCode => {
        const grade = document.querySelector(`select[name="final_grade[${courseCode}]"]`).value;
        const professor = document.querySelector(`input[name="professor_instructor[${courseCode}]"]`).value;
        gradeData[courseCode] = grade;
        professorData[courseCode] = professor;
    });
    fetch('../save_checklist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            bulk_approve: true,
            student_id: studentId,
            courses: selectedCourses,
            grades: gradeData,
            professors: professorData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('Selected grades approved successfully!');
            hideApproveCheckboxes(); location.reload();
        } else {
            alert('Failed to approve grades: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error approving grades:', error);
        alert('An unexpected error occurred while approving grades');
    });
});
</script>
<script>
document.getElementById('downloadPDF').addEventListener('click', function() {
    window.print();
});

window.addEventListener('beforeprint', function() {
    document.querySelectorAll('select[name^="final_grade"]').forEach(function(sel) {
        if (!sel.value || sel.value === '') {
            sel.setAttribute('data-print-hidden', 'true');
            sel.style.visibility = 'hidden';
        }
    });
});

window.addEventListener('afterprint', function() {
    document.querySelectorAll('select[data-print-hidden]').forEach(function(sel) {
        sel.removeAttribute('data-print-hidden');
        sel.style.visibility = '';
    });
});
</script>
</body>
</html>
