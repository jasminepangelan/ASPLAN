<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/vite_legacy.php';

// Check if the student is logged in or if student_id is provided via URL parameter

// Get database connection
$conn = getDBConnection();

if (!function_exists('ensureStudentChecklistColumn')) {
    function ensureStudentChecklistColumn($conn, $columnName, $definition)
    {
        $safeColumnName = preg_replace('/[^A-Za-z0-9_]/', '', (string) $columnName);
        if ($safeColumnName === '') {
            return;
        }

        $result = $conn->query("SHOW COLUMNS FROM student_checklists LIKE '" . $safeColumnName . "'");
        $exists = $result && $result->num_rows > 0;

        if (!$exists) {
            $conn->query("ALTER TABLE student_checklists ADD COLUMN {$safeColumnName} {$definition}");
        }
    }
}

// Ensure 2nd and 3rd attempt grade columns exist in a MySQL-version-safe way
ensureStudentChecklistColumn($conn, 'final_grade_2', 'VARCHAR(20) DEFAULT NULL');
ensureStudentChecklistColumn($conn, 'evaluator_remarks_2', 'VARCHAR(50) DEFAULT NULL');
ensureStudentChecklistColumn($conn, 'final_grade_3', 'VARCHAR(20) DEFAULT NULL');
ensureStudentChecklistColumn($conn, 'evaluator_remarks_3', 'VARCHAR(50) DEFAULT NULL');

// Get student_id from URL parameter if available, otherwise use session
$student_id = '';
$last_name = '';
$first_name = '';
$middle_name = '';
$picture = '';
$contact_no = '';
$address = '';
$admission_date = '';

if (isset($_GET['student_id'])) {
    // If student_id is provided via URL parameter, fetch student details from database
    $student_id = $_GET['student_id'];
    
    $student_stmt = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, picture, program, curriculum_year FROM student_info WHERE student_number = ?");
    $student_stmt->bind_param("s", $student_id);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    
    if ($student_result->num_rows > 0) {
        $student_data = $student_result->fetch_assoc();
        $last_name = htmlspecialchars($student_data['last_name'] ?? '');
        $first_name = htmlspecialchars($student_data['first_name'] ?? '');
        $middle_name = htmlspecialchars($student_data['middle_name'] ?? '');
        $picture = resolveScopedPictureSrc($student_data['picture'] ?? '', '../', 'pix/anonymous.jpg');
        $contact_no = htmlspecialchars($student_data['contact_no'] ?? '');
        $address = htmlspecialchars($student_data['address'] ?? '');
        $admission_date = htmlspecialchars($student_data['admission_date'] ?? '');
        $student_program = $student_data['program'] ?? '';
    }
    $student_stmt->close();
} else {
    // Use session data if available
    $last_name = htmlspecialchars($_SESSION['last_name'] ?? '');
    $first_name = htmlspecialchars($_SESSION['first_name'] ?? '');
    $middle_name = htmlspecialchars($_SESSION['middle_name'] ?? '');
    $picture = resolveScopedPictureSrc($_SESSION['picture'] ?? '', '../', 'pix/anonymous.jpg');
    $student_id = htmlspecialchars($_SESSION['student_id'] ?? '');
    $contact_no = htmlspecialchars($_SESSION['contact_no'] ?? '');
    $address = htmlspecialchars($_SESSION['address'] ?? '');
    $admission_date = htmlspecialchars($_SESSION['admission_date'] ?? '');
    $student_program = $_SESSION['program'] ?? '';
}

  // Always refresh program from DB so checklist view reflects latest approved shift.
  if ($student_id !== '') {
    $program_stmt = $conn->prepare("SELECT program, curriculum_year FROM student_info WHERE student_number = ? LIMIT 1");
    if ($program_stmt) {
      $program_stmt->bind_param('s', $student_id);
      $program_stmt->execute();
      $program_result = $program_stmt->get_result();
      if ($program_result && $program_result->num_rows > 0) {
        $program_row = $program_result->fetch_assoc();
        $student_program = (string)($program_row['program'] ?? $student_program);
      }
      $program_stmt->close();
    }
  }

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

$program_abbr = resolveProgramAbbreviation($student_program);
if ($program_abbr === null) {
  $program_abbr = '';
}

$available_program_views = [];
if ($program_abbr !== '') {
  $available_program_views[$program_abbr] = trim((string)$student_program);
}

if ($student_id !== '') {
  $shift_stmt = $conn->prepare(
    "SELECT current_program, requested_program
     FROM program_shift_requests
     WHERE student_number = ? AND status = 'approved'
     ORDER BY id DESC"
  );
  if ($shift_stmt) {
    $shift_stmt->bind_param('s', $student_id);
    $shift_stmt->execute();
    $shift_result = $shift_stmt->get_result();
    while ($shift_result && ($shift_row = $shift_result->fetch_assoc())) {
      $candidates = [
        (string)($shift_row['current_program'] ?? ''),
        (string)($shift_row['requested_program'] ?? ''),
      ];
      foreach ($candidates as $candidate_program) {
        $candidate_program = trim($candidate_program);
        if ($candidate_program === '') {
          continue;
        }
        $candidate_abbr = resolveProgramAbbreviation($candidate_program);
        if ($candidate_abbr === null || $candidate_abbr === '') {
          continue;
        }
        if (!isset($available_program_views[$candidate_abbr])) {
          $available_program_views[$candidate_abbr] = $candidate_program;
        }
      }
    }
    $shift_stmt->close();
  }
}

$selected_program_view = trim((string)($_GET['program_view'] ?? ''));
if ($selected_program_view === '' || !isset($available_program_views[$selected_program_view])) {
  $selected_program_view = $program_abbr;
}
if ($selected_program_view === '' && !empty($available_program_views)) {
  $keys = array_keys($available_program_views);
  $selected_program_view = (string)$keys[0];
}

$selected_program_label = $available_program_views[$selected_program_view] ?? (string)$student_program;
$academic_hold = ahsGetStudentAcademicHold($conn, (string)$student_id);
$academic_hold_courses_text = ahsFormatHoldCourseList($academic_hold['courses'] ?? []);

$popup_program_options = [];
foreach ($available_program_views as $abbr => $label) {
  $suffix = ((string)$abbr === (string)$program_abbr) ? ' (Current)' : ' (Previous)';
  $popup_program_options[(string)$abbr] = (string)$label . $suffix;
}

$archived_popup_default = '';
if (!empty($popup_program_options)) {
  if (isset($popup_program_options[$selected_program_view])) {
    $archived_popup_default = $selected_program_view;
  } else {
    foreach ($popup_program_options as $abbr => $label) {
      $archived_popup_default = (string)$abbr;
      break;
    }
  }
}

// Helper: returns true when a grade is failing and unlocks the next attempt column
function isFailingGrade($grade) {
    return in_array(trim($grade ?? ''), ['4.00', '5.00', 'Failed', 'INC', 'DRP', 'US']);
}

$all_courses = [];
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
  $bridgeData = postLaravelJsonBridge(
    'http://localhost/ASPLAN_v10/laravel-app/public/api/checklist/view',
    [
      'student_id' => $student_id,
      'program_view' => $selected_program_view,
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
    (string)$selected_program_label,
    (string)$selected_program_view
  );
}

// Group courses by year level for pagination
$year_groups = [];
foreach ($all_courses as $row) {
    $year_groups[$row['year']][] = $row;
}

// Create pages: 2 year levels per page
$year_keys = array_keys($year_groups);
$pages = array_chunk($year_keys, 2);
$total_pages = count($pages);
if ($total_pages === 0) $total_pages = 1;

$studentShellPayload = htmlspecialchars(json_encode([
    'title' => 'Checklist Workspace',
    'description' => 'Review your course checklist, encode grades, and monitor evaluation remarks with the existing PHP saving flow still in place behind the page.',
    'accent' => 'slate',
    'pageKey' => 'checklist',
    'stats' => [
        ['label' => 'Program View', 'value' => (string)($selected_program_view !== '' ? $selected_program_view : 'Current')],
        ['label' => 'Courses', 'value' => (string)count($all_courses)],
        ['label' => 'Pages', 'value' => (string)$total_pages],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$studentChecklistWorkspacePayload = htmlspecialchars(json_encode([
    'title' => 'Checklist Control Deck',
    'note' => 'Navigate checklist pages, jump into archived checklist views, and use the existing print flow without disturbing the current grade-encoding and autosave logic.',
    'programLabel' => (string)($selected_program_label !== '' ? $selected_program_label : 'Current Program'),
    'stats' => [
        ['label' => 'Student ID', 'value' => (string)$student_id],
        ['label' => 'Program View', 'value' => (string)($selected_program_view !== '' ? $selected_program_view : 'Current')],
        ['label' => 'Courses', 'value' => (string)count($all_courses)],
    ],
    'initialPage' => 1,
    'totalPages' => (int)$total_pages,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title>Checklist</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
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
        z-index: 10000;
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

    body {
        font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
        background-color: #f0f0f0;
        margin: 0;
        padding: 0;
        overflow: hidden;
        height: 100vh;
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

    /* Main content styling */
    .main-content {
      margin-left: 200px;
      height: calc(100vh - 51px);
      overflow-y: auto;
      transition: margin-left 0.3s ease;
      padding: 20px;
      box-sizing: border-box;
    }

    .main-content.expanded {
      margin-left: 0;
    }

    .content-wrapper {
      display: flex;
      flex-direction: row;
      gap: 20px;
      align-items: flex-start;
      justify-content: center;
      width: 100%;
      max-width: 1200px;
      margin: 0 auto;
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

    .container {
        width: 100%;
        margin-top: 10px;
        margin-bottom: 40px;
        max-width: 794px;
        padding: 40px;
        background-color: #fff;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
        border-radius: 5px;
        overflow-x: visible;
        position: relative;
        flex-shrink: 1;
        transition: max-width 0.3s ease;
    }
    
    .main-content.expanded .container {
        max-width: 1000px;
    }
    
    .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -20px;
        padding: 0 20px;
    }
    .header {
        text-align: center;
        margin-bottom: 20px;
        position: relative;
    }

    .action-buttons {
        position: fixed;
        right: 50px;
        top: 76px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        width: 140px;
        z-index: 998;
    }

    .action-buttons button {
        padding: 12px 16px;
        background-color: #4CAF50;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s ease;
        width: 100%;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .action-buttons button:hover {
        background-color: #45a049;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }

    .archive-checklist-group {
      position: relative;
      width: 100%;
    }

    .archive-checklist-popup {
      display: none;
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      width: 250px;
      background: #ffffff;
      border: 1px solid #dbe3ee;
      border-radius: 8px;
      box-shadow: 0 10px 22px rgba(0, 0, 0, 0.16);
      padding: 10px;
      z-index: 999;
    }

    .archive-checklist-popup.open {
      display: block;
    }

    .archive-checklist-form {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .archive-checklist-form label {
      color: #1f2e45;
      font-size: 12px;
    }

    .archive-checklist-form select {
      border: 1px solid #b9c2cf;
      border-radius: 6px;
      padding: 7px 8px;
      font-size: 12px;
      background: #fff;
    }

    .archive-view-btn {
      background: #206018;
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 9px 10px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
    }

    .archive-view-btn:hover {
      background: #2d8f22;
    }

    .header-buttons {
        display: none;
    }
    .CvSUlogo .logo img {
      display: none;
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
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 15px;
    }

    .header h3 .logo-inline {
        width: 40px;
        height: 40px;
        flex-shrink: 0;
    }
    .info {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        margin-top: 20px;
        margin-bottom: 20px;
        gap: 20px;
        flex-wrap: nowrap;
    }
    .info-left {
        flex: 1;
        min-width: 0;
    }
    .info-right {
        flex: 1;
        text-align: right;
        min-width: 0;
    }
    table {
        width: 100%;
        max-width: 100%;
        border-collapse: collapse;
        margin: 0 auto 20px auto;
        font-size: 10px;
        page-break-inside: auto !important;
        break-inside: auto !important;
        table-layout: auto;
    }
    tr, td, th {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        box-decoration-break: clone;
    }
    th, td {
        border: 1px solid #000;
        padding: 2px 4px;
        text-align: center;
        font-size: 9px;
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
    @media (max-width: 768px) {
        .sidebar {
          transform: translateX(-100%);
          z-index: 1000;
        }
        
        .sidebar:not(.collapsed) {
          transform: translateX(0);
        }
        
        .main-content {
          margin-left: 0;
          padding: 5px 5px 5px 15px;
          overflow-x: auto;
          overflow-y: auto;
        }

        .content-wrapper {
          flex-direction: row;
          width: 100%;
          gap: 5px;
          align-items: flex-start;
        }

        .action-buttons {
          position: fixed;
          right: 5px;
          top: 45px;
          flex-direction: column;
          width: 60px;
          gap: 5px;
          z-index: 998;
        }

        .action-buttons button {
          padding: 8px 4px;
          font-size: 9px;
          width: 100%;
          border-radius: 4px;
          word-wrap: break-word;
          line-height: 1.2;
        }

        .archive-checklist-popup {
          width: 190px;
          right: 0;
          padding: 8px;
        }

        .archive-checklist-form label,
        .archive-checklist-form select,
        .archive-view-btn {
          font-size: 10px;
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

        .container {
          width: calc(100vw - 100px);
          max-width: none;
          padding: 10px;
          margin-top: 3px;
          margin-bottom: 15px;
          overflow-x: visible;
          overflow-y: visible;
          box-sizing: border-box;
          flex-shrink: 0;
          min-width: 0;
        }
        
        .table-wrapper {
          overflow-x: auto;
          -webkit-overflow-scrolling: touch;
          margin: 0 -8px;
          padding: 0 8px;
          max-width: 100%;
        }

        .header {
          padding: 3px;
        }

        .header h1 {
          font-size: 9px;
          margin-top: 3px;
          margin-bottom: 2px;
        }

        .header h2 {
          font-size: 9px;
          margin: 1px 0;
        }

        .header h3 {
          font-size: 9px;
          margin-top: 8px;
          gap: 8px;
        }

        .header h3 .logo-inline {
          width: 30px;
          height: 30px;
        }

        .header-buttons {
          flex-direction: column;
          gap: 6px;
          margin-top: 10px;
        }

        .header-buttons button {
          width: 100%;
          padding: 8px 12px;
          font-size: 11px;
        }

        .CvSUlogo .logo img {
          display: none;
        }

        .info {
          flex-direction: row;
          font-size: 8px;
          margin-top: 8px;
          margin-bottom: 8px;
          gap: 10px;
          justify-content: space-between;
        }

        .info-left {
          text-align: left;
          flex: 1;
        }

        .info-right {
          text-align: right;
          flex: 1;
        }

        .info p {
          margin: 2px 0;
          word-wrap: break-word;
          line-height: 1.3;
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
          padding: 3px 2px;
          font-size: 8px;
          border: 1px solid #000;
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

        .print-spacer-row { 
          display: none; 
        }

        .pagination-controls {
          margin: 10px 0;
          padding: 8px 10px;
          gap: 8px;
        }

        .pagination-btn {
          padding: 6px 12px;
          font-size: 12px;
        }

        .pagination-info {
          font-size: 12px;
          padding: 5px 10px;
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
        background: white !important;
        background-image: none !important;
        overflow: visible !important;
        height: auto !important;
        margin: 0;
        padding: 0;
        font-size: 9px;
      }

      .print-spacer-row { 
        display: table-row; 
      }
      .print-spacer-row:first-child { 
        display: none !important; 
      }

      .action-buttons,
      .header-buttons,
      .sidebar,
      .title-bar,
      .notification,
      .pagination-controls {
        display: none !important;
      }

      /* Show all pages when printing */
      .page-content {
        display: block !important;
      }

      .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        height: auto !important;
        display: block !important;
      }

      .content-wrapper {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
      }

      .container {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        overflow: visible !important;
      }

      .table-wrapper {
        overflow: visible !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      /* Hide top logo in print */
      .CvSUlogo .logo img {
        display: none !important;
      }

      .header h1 {
        font-size: 10px;
        margin-top: 3px;
      }

      .header h2 {
        font-size: 10px;
      }

      .header h3 {
        font-size: 11px;
        margin-top: 10px;
      }

      .header h3 .logo-inline {
        display: inline-block !important;
        width: 40px;
        height: 40px;
      }

      .info {
        font-size: 10px;
        margin-top: 12px;
        margin-bottom: 12px;
      }

      table {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        border-collapse: collapse !important;
        font-size: 8px !important;
        table-layout: auto !important;
        page-break-inside: auto !important;
      }

      thead {
        display: table-header-group;
      }

      tr {
        page-break-inside: avoid !important;
      }

      th, td {
        font-size: 8px !important;
        padding: 3px 4px !important;
        border: 1px solid #000 !important;
        word-wrap: break-word;
      }

      th {
        background-color: #f2f2f2 !important;
        font-weight: bold;
      }

      .semester-title td {
        background-color: #f2f2f2 !important;
        font-weight: bold !important;
        font-size: 9px !important;
      }

      /* Convert inputs to plain text in print */
      input[type="text"] {
        border: none !important;
        border-bottom: 1px solid #000 !important;
        background: transparent !important;
        font-size: 8px !important;
        padding: 0 !important;
        box-shadow: none !important;
      }

      select {
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        appearance: none !important;
        border: none !important;
        background: transparent !important;
        font-size: 8px !important;
        padding: 0 !important;
        box-shadow: none !important;
      }

      /* Approved grade styling in print */
      select:disabled {
        color: #000 !important;
        opacity: 1 !important;
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

      .main-content {
        padding: 3px 3px 3px 12px;
        overflow-x: auto;
        overflow-y: auto;
      }

      .content-wrapper {
        gap: 4px;
        width: 100%;
      }

      .action-buttons {
        position: fixed;
        right: 3px;
        top: 40px;
        width: 55px;
        gap: 4px;
        z-index: 998;
      }

      .action-buttons button {
        padding: 6px 2px;
        font-size: 8px;
        line-height: 1.1;
      }

      .container {
        width: calc(100vw - 90px);
        padding: 8px;
        margin-top: 2px;
        flex-shrink: 0;
        min-width: 0;
      }

      .header {
        padding: 3px;
      }

      .header h1, .header h2, .header h3 {
        font-size: 8px;
      }

      .header h3 {
        margin-top: 6px;
        gap: 6px;
      }

      .header h3 .logo-inline {
        width: 25px;
        height: 25px;
      }

      .header-buttons button {
        padding: 6px 10px;
        font-size: 10px;
      }

      .info {
        flex-direction: row;
        font-size: 7px;
        margin-top: 6px;
        margin-bottom: 6px;
        gap: 8px;
        justify-content: space-between;
      }

      .info-left {
        text-align: left;
        flex: 1;
      }

      .info-right {
        text-align: right;
        flex: 1;
      }

      .info p {
        font-size: 7px;
        margin: 1px 0;
        line-height: 1.2;
      }

      .table-wrapper {
        margin: 0 -6px;
        padding: 0 6px;
      }

      table {
        font-size: 8px;
        min-width: 600px;
        margin-bottom: 10px;
      }

      th, td {
        font-size: 7px;
        padding: 2px 1px;
      }

      th {
        line-height: 1.1;
      }

      input[type="text"],
      select {
        font-size: 7px !important;
        min-width: 60px;
        padding: 1px;
      }

      .semester-title td {
        font-size: 8px;
        padding: 3px;
      }
    }


    select[name^="final_grade"],
    select[name^="evaluator_remarks"] {
        transition: background-color 0.3s ease;
    }

    /* Pagination Controls */
    .pagination-controls {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 15px;
        margin: 15px 0;
        padding: 10px 15px;
        background: #f8f9fa;
        border-radius: 8px;
    }

    .pagination-btn {
        background: #206018;
        color: #ffffff;
        border: none;
        padding: 8px 18px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.3s ease;
    }

    .pagination-btn:hover:not(:disabled) {
        background: #2d8f22;
        transform: translateY(-1px);
    }

    .pagination-btn:disabled {
        background: #ccc;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .pagination-info {
        font-size: 13px;
        font-weight: 600;
        color: #333;
        padding: 6px 14px;
        background: #fff;
        border-radius: 6px;
        border: 1px solid #ddd;
    }

    .page-content {
        display: none;
    }

    .page-content.active {
        display: block;
    }

    select[name^="final_grade"]:disabled,
    select[name^="evaluator_remarks"]:disabled {
        background-color: #f5f5f5;
        cursor: not-allowed;
    }

    select[name^="final_grade"]:disabled {
        background-color: #fff;
        color: #206018;
        font-weight: bold;
        opacity: 1;
        border: 1px solid #206018;
        cursor: not-allowed;
        box-shadow: none;
    }
    </style>
    <?= renderLegacyViteTags(['resources/js/student-shell.jsx', 'resources/js/student-checklist-workspace.jsx']) ?>
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
      <img src="<?= $picture ?>" alt="Profile Picture">
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
        <li><a href="#" class="active"><img src="../pix/update.png" alt="Checklist"> Update Checklist</a></li>
        <li><a href="study_plan.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan</a></li>
        <li><a href="program_shift_request.php"><img src="../pix/checklist.png" alt="Program Shift"> Program Shift</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Account</div>
        <li><a href="acc_mng.php"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
        <li><a href="../auth/signout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
      </div>
    </ul>
  </div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <div data-student-shell="<?= $studentShellPayload ?>"></div>
    <div data-student-checklist-workspace="<?= $studentChecklistWorkspacePayload ?>"></div>
    <div class="content-wrapper">
      <div class="container">
        <div class="header">
            <div class="CvSUlogo">
                <div class="logo">
                  <img src="../img/cav.png" alt="CvSU Logo"/>
                </div>
            </div>
            <h1>Republic of the Philippines</h1>
            <h2>CAVITE STATE UNIVERSITY - CARMONA</h2>
            <h2>Carmona Cavite</h2>
            <h3>
                <img src="../img/cav.png" alt="CvSU Logo" class="logo-inline"/>
              <?php echo htmlspecialchars(strtoupper($selected_program_label ?: 'BACHELOR OF SCIENCE IN COMPUTER SCIENCE')); ?>
            </h3>
            
            <div class="header-buttons">
                <button id="saveButton">Save</button>
                <button onclick="window.location.href='home_page_student.php'">Back</button>
            </div>
        </div>
        <?php if (!empty($academic_hold['active'])): ?>
        <div style="margin: 0 0 14px; padding: 14px 16px; border-radius: 10px; border-left: 5px solid #b71c1c; background: linear-gradient(135deg, #fff4f4, #ffe2e2); color: #5f1b1b; box-shadow: 0 2px 10px rgba(183, 28, 28, 0.12);">
            <div style="font-size: 16px; font-weight: 700; color: #8e1c1c; margin-bottom: 6px;">
                <?= htmlspecialchars((string)$academic_hold['title']) ?>
            </div>
            <div style="font-size: 13px; line-height: 1.5;">
                <?= htmlspecialchars((string)$academic_hold['message']) ?>
            </div>
            <?php if ($academic_hold_courses_text !== ''): ?>
            <div style="margin-top: 8px; font-size: 12px; font-weight: 600;">
                Courses at the limit: <?= htmlspecialchars($academic_hold_courses_text) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="info">
            <div class="info-left">
                <p><strong>Name: <?= htmlspecialchars($last_name . ', ' . $first_name . (!empty($middle_name) ? ' ' . $middle_name : '')) ?></p>
                <p><strong>Student #: <?= htmlspecialchars("$student_id") ?></p>
                <p><strong>Address: <?= htmlspecialchars("$address") ?></p>
            </div>
            <div class="info-right">
                <p><strong>Admission Date: <?= htmlspecialchars("$admission_date") ?></strong></p>
                <p><strong>Contact #: <?= htmlspecialchars("$contact_no") ?></strong></p>
                <p><strong>Adviser: <span style="display: inline-block; border-bottom: 2px solid #000; width: 90px; min-height: 14px;">&nbsp;</span></strong></p>
            </div>
        </div>
        <div class="table-wrapper">
            <!-- Pagination Controls (Top) -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-controls" id="paginationTop">
                <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)" disabled>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                    Previous
                </button>
                <span class="pagination-info">
                    Page <span id="currentPage">1</span> of <?= $total_pages ?>
                </span>
                <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">
                    Next
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>
            <?php endif; ?>

            <?php if (count($all_courses) > 0): ?>
                <?php foreach ($pages as $page_index => $page_year_keys): ?>
                <div class="page-content <?= $page_index === 0 ? 'active' : '' ?>" data-page="<?= $page_index + 1 ?>">
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
                        foreach ($page_year_keys as $year_key) {
                            foreach ($year_groups[$year_key] as $row) {
                                if ($currentYear != $row['year'] || $currentSemester != $row['semester']) {
                                    echo "<tr class='semester-title'>
                                            <td colspan='13'>{$row['year']} - {$row['semester']}</td>
                                        </tr>";
                                    $currentYear = $row['year'];
                                    $currentSemester = $row['semester'];
                                }

                                $grades = ['', 'No Grade', '1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '4.00', '5.00', 'Passed', 'Failed', 'US', 'S', 'INC', 'DRP'];
                                $grade1_val  = $row['final_grade']         ?? '';
                                $grade2_val  = $row['final_grade_2']       ?? '';
                                $grade3_val  = $row['final_grade_3']       ?? '';
                                $remarks1    = $row['evaluator_remarks']   ?? '';
                                $remarks2    = $row['evaluator_remarks_2'] ?? '';
                                $remarks3    = $row['evaluator_remarks_3'] ?? '';
                                $approvedBy  = strtolower(trim((string)($row['approved_by'] ?? '')));
                                $submittedBy = strtolower(trim((string)($row['submitted_by'] ?? '')));
                                $isCredited  =
                                  (stripos((string)$remarks1, 'credited') !== false) ||
                                  (stripos((string)$remarks2, 'credited') !== false) ||
                                  (stripos((string)$remarks3, 'credited') !== false) ||
                                  ($approvedBy === 'shift_engine') ||
                                  ($submittedBy === 'shift_engine');
                                $show_2nd    = isFailingGrade($grade1_val);
                                $show_3rd    = $show_2nd && isFailingGrade($grade2_val);

                                echo "<tr>
                                <td>{$row['course_code']}</td>
                                <td>{$row['course_title']}</td>
                                <td>{$row['credit_unit_lec']}</td>
                                <td>{$row['credit_unit_lab']}</td>
                                <td>{$row['contact_hrs_lec']}</td>
                                <td>{$row['contact_hrs_lab']}</td>
                                <td>{$row['pre_requisite']}</td>
                                <td>{$row['semester']} {$row['year']}</td>
                                <td><input type='text' name='professor_instructor[{$row['course_code']}]' value='" . (!empty($row['professor_instructor']) ? htmlspecialchars($row['professor_instructor']) : "") . "' style='border: none; font-size: 8px; border-bottom: 1px solid #000000; width: 80px; max-width: 100%;' " . ($isCredited ? "readonly" : "") . "></td>
                                <td id='grade1_{$row['course_code']}'>"; // 1st attempt
                                if ($remarks1 === 'Approved' || $isCredited) {
                                    echo "<span style='font-size: 10px; color: #000; font-weight: bold;'>{$grade1_val}</span>";
                                } else {
                                    $ps1 = ($remarks1 === 'Pending') ? 'color:#000;font-weight:bold;' : '';
                                    echo "<select name='final_grade[{$row['course_code']}]' style='border:none;font-size:8px;width:70px;max-width:100%;{$ps1}'>";
                                    foreach ($grades as $g) {
                                        $sel = ($g === $grade1_val) ? 'selected' : '';
                                        $dt  = ($g === '') ? '-- Select --' : $g;
                                        echo "<option value='{$g}' {$sel}>{$dt}</option>";
                                    }
                                    echo "</select>";
                                }
                                // 2nd attempt
                                echo "</td><td id='grade2_{$row['course_code']}'>";
                                if ($isCredited) {
                                  echo ($grade2_val !== '' ? "<span style='font-size:10px;color:#000;font-weight:bold;'>{$grade2_val}</span>" : "<span style='color:#ccc;font-size:10px;'>&#8212;</span>");
                                } elseif ($show_2nd) {
                                    if ($remarks2 === 'Approved') {
                                        echo "<span style='font-size:10px;color:#000;font-weight:bold;'>{$grade2_val}</span>";
                                    } else {
                                        $ps2 = ($remarks2 === 'Pending') ? 'color:#000;font-weight:bold;' : '';
                                        echo "<select name='final_grade_2[{$row['course_code']}]' style='border:none;font-size:8px;width:70px;max-width:100%;{$ps2}'>";
                                        foreach ($grades as $g) {
                                            $sel = ($g === $grade2_val) ? 'selected' : '';
                                            $dt  = ($g === '') ? '-- Select --' : $g;
                                            echo "<option value='{$g}' {$sel}>{$dt}</option>";
                                        }
                                        echo "</select>";
                                    }
                                } else {
                                    echo "<span style='color:#ccc;font-size:10px;'>&#8212;</span>";
                                }
                                // 3rd attempt
                                echo "</td><td id='grade3_{$row['course_code']}'>";
                                if ($isCredited) {
                                  echo ($grade3_val !== '' ? "<span style='font-size:10px;color:#000;font-weight:bold;'>{$grade3_val}</span>" : "<span style='color:#ccc;font-size:10px;'>&#8212;</span>");
                                } elseif ($show_3rd) {
                                    if ($remarks3 === 'Approved') {
                                        echo "<span style='font-size:10px;color:#000;font-weight:bold;'>{$grade3_val}</span>";
                                    } else {
                                        $ps3 = ($remarks3 === 'Pending') ? 'color:#000;font-weight:bold;' : '';
                                        echo "<select name='final_grade_3[{$row['course_code']}]' style='border:none;font-size:8px;width:70px;max-width:100%;{$ps3}'>";
                                        foreach ($grades as $g) {
                                            $sel = ($g === $grade3_val) ? 'selected' : '';
                                            $dt  = ($g === '') ? '-- Select --' : $g;
                                            echo "<option value='{$g}' {$sel}>{$dt}</option>";
                                        }
                                        echo "</select>";
                                    }
                                } else {
                                    echo "<span style='color:#ccc;font-size:10px;'>&#8212;</span>";
                                }
                                $anyPendingRemark = ($remarks1 === 'Pending' || $remarks2 === 'Pending' || $remarks3 === 'Pending');
                                echo "</td><td id='remarks_{$row['course_code']}'>";
                                if ($anyPendingRemark) {
                                    echo "<span style='background: #ff9800; color: white; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold;'>Pending</span>";
                                } elseif ($remarks1) {
                                    echo htmlspecialchars($remarks1);
                                }
                                echo "</td></tr>";
                            }
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="page-content active" data-page="1">
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
                            <tr><td colspan='13'>No courses available.</td></tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Pagination Controls (Bottom) -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-controls" id="paginationBottom">
                <button class="pagination-btn" id="prevBtnBottom" onclick="changePage(-1)" disabled>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                    Previous
                </button>
                <span class="pagination-info">
                    Page <span id="currentPageBottom">1</span> of <?= $total_pages ?>
                </span>
                <button class="pagination-btn" id="nextBtnBottom" onclick="changePage(1)">
                    Next
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>
            <?php endif; ?>
        </div>

      </div>
      
      <!-- Action Buttons on the Right -->
      <div class="action-buttons">
          <button id="saveButton">Save</button>
          <button id="printChecklist" onclick="window.print()">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 2px;">
                  <polyline points="6 9 6 2 18 2 18 9"></polyline>
                  <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                  <rect x="6" y="14" width="12" height="8"></rect>
              </svg>
              Print
          </button>
          <button onclick="window.location.href='home_page_student.php'">Back</button>
            <?php if (!empty($popup_program_options)): ?>
          <div class="archive-checklist-group" id="archiveChecklistGroup">
              <button type="button" id="archiveChecklistToggle" onclick="toggleArchiveChecklistPopup(event)">Archived Checklist</button>
              <div class="archive-checklist-popup" id="archiveChecklistPopup">
                  <form method="get" class="archive-checklist-form">
                      <?php if (isset($_GET['student_id']) && $_GET['student_id'] !== ''): ?>
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars((string)$_GET['student_id']) ?>">
                      <?php endif; ?>
                      <label for="archive_program_view"><strong>Checklist:</strong></label>
                      <select id="archive_program_view" name="program_view">
                        <?php foreach ($popup_program_options as $abbr => $label): ?>
                          <option value="<?= htmlspecialchars($abbr) ?>" <?= $archived_popup_default === $abbr ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="archive-view-btn">View Archived Checklist</button>
                  </form>
              </div>
          </div>
          <?php endif; ?>
      </div>
    </div>
  </div>

<script>
    function refreshChecklist() {
        location.reload();
    }

    function toggleArchiveChecklistPopup(event) {
      if (event) {
        event.stopPropagation();
      }
      const popup = document.getElementById('archiveChecklistPopup');
      if (!popup) {
        return;
      }
      popup.classList.toggle('open');
    }

    document.addEventListener('student-checklist:action', function(event) {
      const action = event && event.detail ? event.detail.action : '';
      if (action === 'prev-page') {
        changePage(-1);
      } else if (action === 'next-page') {
        changePage(1);
      } else if (action === 'print') {
        window.print();
      } else if (action === 'archive') {
        toggleArchiveChecklistPopup();
      }
    });

    // Pagination functionality
    let currentPage = 1;
    const totalPages = <?= $total_pages ?>;

    function changePage(direction) {
        const newPage = currentPage + direction;
        if (newPage >= 1 && newPage <= totalPages) {
            currentPage = newPage;
            updatePageDisplay();
        }
    }

    function updatePageDisplay() {
        // Hide all pages
        document.querySelectorAll('.page-content').forEach(function(pageEl) {
            pageEl.classList.remove('active');
        });

        // Show current page
        const activePage = document.querySelector('.page-content[data-page="' + currentPage + '"]');
        if (activePage) {
            activePage.classList.add('active');
        }

        // Update page numbers
        const currentPageEl = document.getElementById('currentPage');
        const currentPageBottomEl = document.getElementById('currentPageBottom');
        if (currentPageEl) currentPageEl.textContent = currentPage;
        if (currentPageBottomEl) currentPageBottomEl.textContent = currentPage;

        document.dispatchEvent(new CustomEvent('student-checklist:page-change', {
            detail: {
                currentPage: currentPage,
                totalPages: totalPages
            }
        }));

        // Update button states
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const prevBtnBottom = document.getElementById('prevBtnBottom');
        const nextBtnBottom = document.getElementById('nextBtnBottom');

        if (prevBtn) prevBtn.disabled = currentPage === 1;
        if (nextBtn) nextBtn.disabled = currentPage === totalPages;
        if (prevBtnBottom) prevBtnBottom.disabled = currentPage === 1;
        if (nextBtnBottom) nextBtnBottom.disabled = currentPage === totalPages;

        // Scroll to top of container
        const container = document.querySelector('.container');
        if (container) {
            container.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    const academicHold = <?= json_encode($academic_hold, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    function applyAcademicReadOnlyState() {
        if (!academicHold.active) {
            return;
        }

        document.querySelectorAll('#saveButton').forEach(btn => {
            btn.disabled = true;
            btn.textContent = 'View Only';
            btn.title = academicHold.short_message || academicHold.message || 'Read-only mode';
            btn.style.opacity = '0.7';
            btn.style.cursor = 'not-allowed';
        });

        document.querySelectorAll('select[name^="final_grade"]').forEach(select => {
            select.disabled = true;
            select.title = academicHold.short_message || academicHold.message || 'Read-only mode';
            select.style.backgroundColor = '#f4f4f4';
            select.style.cursor = 'not-allowed';
        });

        document.querySelectorAll('input[name^="professor_instructor"]').forEach(input => {
            input.readOnly = true;
            input.title = academicHold.short_message || academicHold.message || 'Read-only mode';
            input.style.backgroundColor = '#f4f4f4';
        });
    }

    function hasSubmittedGradeValue(value) {
        const normalized = String(value || '').trim();
        return normalized !== '' && normalized !== 'No Grade';
    }

    function setChecklistRemarksBadge(courseCode, status) {
        const remarksCell = document.getElementById(`remarks_${courseCode}`);
        if (!remarksCell) return;

        if (status === 'Pending') {
            remarksCell.innerHTML = "<span style='background: #ff9800; color: white; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold;'>Pending</span>";
            return;
        }

        if (status === 'Approved') {
            remarksCell.innerHTML = "<span style='background: #4CAF50; color: white; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold;'>Approved</span>";
            return;
        }

        remarksCell.textContent = status || '';
    }

    // Add event listeners to all Save buttons
    document.querySelectorAll('#saveButton').forEach(btn => {
        btn.addEventListener('click', function () {
            if (academicHold.active) {
                showNotification('error', academicHold.title || 'Academic Hold', academicHold.message || 'Your account is currently read-only.');
                return;
            }

            let formData = new FormData();
            let professorInputs = document.querySelectorAll('[name^="professor_instructor"]');

            // Collect all course data (all 3 grade attempts) per course
            professorInputs.forEach(function (profInput) {
                let courseCode = profInput.name.split('[')[1].split(']')[0];
                let professorValue = profInput.value;

                let gradeInput  = document.querySelector(`[name='final_grade[${courseCode}]']`);
                let gradeInput2 = document.querySelector(`[name='final_grade_2[${courseCode}]']`);
                let gradeInput3 = document.querySelector(`[name='final_grade_3[${courseCode}]']`);
                let finalGrade  = gradeInput  ? gradeInput.value  : '';
                let finalGrade2 = gradeInput2 ? gradeInput2.value : '';
                let finalGrade3 = gradeInput3 ? gradeInput3.value : '';

                let remarksCell = document.getElementById(`remarks_${courseCode}`);
                let currentRemarks = remarksCell ? remarksCell.textContent.trim() : '';

                let evaluatorRemark = (
                    hasSubmittedGradeValue(finalGrade) ||
                    hasSubmittedGradeValue(finalGrade2) ||
                    hasSubmittedGradeValue(finalGrade3)
                ) ? 'Pending' : currentRemarks;

                formData.append('courses[]', courseCode);
                formData.append('final_grades[]', finalGrade);
                formData.append('final_grades_2[]', finalGrade2);
                formData.append('final_grades_3[]', finalGrade3);
                formData.append('professor_instructors[]', professorValue);
                formData.append('evaluator_remarks[]', evaluatorRemark);
            }); // end professorInputs.forEach

            formData.append('student_id', '<?= $student_id ?>');

            fetch('save_checklist_stud.php', {
                method: 'POST',
                body: formData,
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    showSuccessModal('Data saved successfully!');
                    professorInputs.forEach(function (profInput) {
                        let courseCode = profInput.name.split('[')[1].split(']')[0];
                        let gradeInput  = document.querySelector(`[name='final_grade[${courseCode}]']`);
                        let gradeInput2 = document.querySelector(`[name='final_grade_2[${courseCode}]']`);
                        let gradeInput3 = document.querySelector(`[name='final_grade_3[${courseCode}]']`);
                        let hasSubmittedGrade = (
                            hasSubmittedGradeValue(gradeInput ? gradeInput.value : '') ||
                            hasSubmittedGradeValue(gradeInput2 ? gradeInput2.value : '') ||
                            hasSubmittedGradeValue(gradeInput3 ? gradeInput3.value : '')
                        );

                        if (hasSubmittedGrade) {
                            setChecklistRemarksBadge(courseCode, 'Pending');
                        } else {
                            setChecklistRemarksBadge(courseCode, '');
                        }
                    });
                    fetchAndUpdateChecklist();
                }
            });
        }); // end addEventListener
    }); // end querySelectorAll.forEach

// Success modal function
function showSuccessModal(message) {
    // Remove any existing modal
    const oldModal = document.getElementById('success-modal');
    if (oldModal) oldModal.remove();

    // Create modal overlay
    const modal = document.createElement('div');
    modal.id = 'success-modal';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.width = '100vw';
    modal.style.height = '100vh';
    modal.style.background = 'rgba(32,96,24,0.15)';
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    modal.style.zIndex = '9999';
    modal.style.animation = 'fadeIn 0.3s';

    // Modal container
    const container = document.createElement('div');
    container.style.background = '#fff';
    container.style.borderRadius = '16px';
    container.style.boxShadow = '0 8px 32px rgba(32,96,24,0.18), 0 1.5px 8px rgba(0,0,0,0.08)';
    container.style.padding = '36px 32px 28px 32px';
    container.style.minWidth = '320px';
    container.style.maxWidth = '90vw';
    container.style.textAlign = 'center';
    container.style.position = 'relative';
    container.style.animation = 'popIn 0.3s';

    // Icon
    const icon = document.createElement('div');
    icon.innerHTML = '<svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="28" cy="28" r="28" fill="#4CAF50"/><path d="M16 29.5L24.5 38L40 22.5" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    icon.style.marginBottom = '12px';

    // Title
    const title = document.createElement('div');
    title.textContent = message;
    title.style.color = '#206018';
    title.style.fontSize = '22px';
    title.style.fontWeight = '700';
    title.style.marginBottom = '10px';
    title.style.letterSpacing = '0.5px';

    // Description
    const desc = document.createElement('div');
    desc.textContent = 'Your checklist has been updated.';
    desc.style.color = '#444';
    desc.style.fontSize = '15px';
    desc.style.marginBottom = '8px';

    container.appendChild(icon);
    container.appendChild(title);
    container.appendChild(desc);
    modal.appendChild(container);
    document.body.appendChild(modal);

    setTimeout(function() {
        container.style.transition = 'opacity 0.4s';
        container.style.opacity = '0';
        setTimeout(function() { modal.remove(); }, 400);
    }, 1500);
}

// Function to fetch and update checklist data
let isChecklistRefreshRunning = false;
let checklistLiveRefreshTimer = null;

function bindChecklistFieldListeners(root = document) {
    root.querySelectorAll('select[name^="final_grade"]').forEach(function(gradeSelect) {
        if (gradeSelect.dataset.liveBound === '1') {
            return;
        }

        gradeSelect.dataset.liveBound = '1';
        gradeSelect.addEventListener('change', function() {
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
                applyAcademicReadOnlyState();
                updatePageDisplay();
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
        if (document.hidden || academicHold.active) {
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

// Function to update checklist fields
// Modify the updateChecklistFields function in checklist_stud.php:

    function isCreditedRemark(value) {
      return (String(value || '').toLowerCase().indexOf('credited') !== -1);
    }

    function updateChecklistFields(courses) {
    courses.forEach(course => {
      const isCredited = isCreditedRemark(course.evaluator_remarks) ||
        isCreditedRemark(course.evaluator_remarks_2) ||
        isCreditedRemark(course.evaluator_remarks_3);

        // Update professor/instructor
        const professorInput = document.querySelector(`input[name="professor_instructor[${course.course_code}]"]`);
        if (professorInput) {
            professorInput.value = course.professor_instructor || '';
        professorInput.readOnly = isCredited || academicHold.active;
        }
        // Update 1st attempt grade
        const gradeSelect = document.querySelector(`select[name="final_grade[${course.course_code}]"]`);
        if (gradeSelect) {
        if (course.evaluator_remarks === 'Approved' || isCredited) {
                const td = gradeSelect.parentElement;
                td.innerHTML = `<span style='font-size: 10px; color: #000; font-weight: bold;'>${course.final_grade || ''}</span>`;
            } else {
                gradeSelect.value = course.final_grade || '';
                gradeSelect.disabled = !!academicHold.active;
            }
        }
        // Update 2nd attempt grade cell
        const grade2Cell = document.getElementById(`grade2_${course.course_code}`);
        if (grade2Cell) {
          if (course.evaluator_remarks_2 === 'Approved' || isCredited) {
                grade2Cell.innerHTML = `<span style='font-size:10px;color:#000;font-weight:bold;'>${course.final_grade_2 || ''}</span>`;
            } else {
                const sel2 = grade2Cell.querySelector('select');
                if (sel2 && course.final_grade_2) sel2.value = course.final_grade_2;
                if (sel2) sel2.disabled = !!academicHold.active;
                if (sel2 && course.evaluator_remarks_2 === 'Pending') {
                    sel2.style.fontWeight = 'bold'; sel2.style.color = '#000';
                }
            }
        }
        // Update 3rd attempt grade cell
        const grade3Cell = document.getElementById(`grade3_${course.course_code}`);
        if (grade3Cell) {
          if (course.evaluator_remarks_3 === 'Approved' || isCredited) {
                grade3Cell.innerHTML = `<span style='font-size:10px;color:#000;font-weight:bold;'>${course.final_grade_3 || ''}</span>`;
            } else {
                const sel3 = grade3Cell.querySelector('select');
                if (sel3 && course.final_grade_3) sel3.value = course.final_grade_3;
                if (sel3) sel3.disabled = !!academicHold.active;
                if (sel3 && course.evaluator_remarks_3 === 'Pending') {
                    sel3.style.fontWeight = 'bold'; sel3.style.color = '#000';
                }
            }
        }
        // Update evaluator remarks — show Pending if any attempt is pending
        const remarksElement = document.getElementById(`remarks_${course.course_code}`);
        if (remarksElement) {
            const r1 = course.evaluator_remarks   || '';
            const r2 = course.evaluator_remarks_2 || '';
            const r3 = course.evaluator_remarks_3 || '';
            const anyPending = r1 === 'Pending' || r2 === 'Pending' || r3 === 'Pending';
          if (isCredited) {
            remarksElement.textContent = r1 || 'Credited (Shift Equivalency)';
          } else if (anyPending) {
                remarksElement.innerHTML = "<span style='background: #ff9800; color: white; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold;'>Pending</span>";
            } else if (r1 === 'Approved') {
                remarksElement.innerHTML = "<span style='background: #4CAF50; color: white; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: bold;'>Approved</span>";
            } else {
                remarksElement.textContent = r1;
            }
        }
    });
}

// Auto-save functionality for final grades
let autoSaveTimeout;
let isSaving = false;

function autoSaveGrade(courseCode) {
    if (academicHold.active) {
        showNotification('error', academicHold.title || 'Academic Hold', academicHold.message || 'Your account is currently read-only.');
        return;
    }

    if (isSaving) return;
    
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        isSaving = true;
        
        let formData = new FormData();
        let gradeInput  = document.querySelector(`[name='final_grade[${courseCode}]']`);
        let gradeInput2 = document.querySelector(`[name='final_grade_2[${courseCode}]']`);
        let gradeInput3 = document.querySelector(`[name='final_grade_3[${courseCode}]']`);
        let profInput   = document.querySelector(`[name='professor_instructor[${courseCode}]']`);
        let remarksCell = document.getElementById(`remarks_${courseCode}`);
        
        let finalGrade  = gradeInput  ? gradeInput.value  : '';
        let finalGrade2 = gradeInput2 ? gradeInput2.value : '';
        let finalGrade3 = gradeInput3 ? gradeInput3.value : '';
        let professorValue  = profInput   ? profInput.value   : '';
        let currentRemarks  = remarksCell ? remarksCell.textContent.trim() : '';
        
        let evaluatorRemark = (
            hasSubmittedGradeValue(finalGrade) ||
            hasSubmittedGradeValue(finalGrade2) ||
            hasSubmittedGradeValue(finalGrade3)
        ) ? 'Pending' : currentRemarks;
        
        formData.append('courses[]', courseCode);
        formData.append('final_grades[]', finalGrade);
        formData.append('final_grades_2[]', finalGrade2);
        formData.append('final_grades_3[]', finalGrade3);
        formData.append('professor_instructors[]', professorValue);
        formData.append('evaluator_remarks[]', evaluatorRemark);
        formData.append('student_id', '<?= $student_id ?>');
        
        fetch('save_checklist_stud.php', {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            isSaving = false;
            if (data.status === 'success') {
                console.log('Auto-saved grade for ' + courseCode);
                showNotification('success', 'Auto-saved', 'Grade saved successfully');
                let hasNewGrade = hasSubmittedGradeValue(finalGrade) ||
                                  hasSubmittedGradeValue(finalGrade2) ||
                                  hasSubmittedGradeValue(finalGrade3);
                if (hasNewGrade) {
                    setChecklistRemarksBadge(courseCode, 'Pending');
                } else {
                    setChecklistRemarksBadge(courseCode, '');
                }
                fetchAndUpdateChecklist();
            } else {
                console.error('Auto-save failed:', data.message);
                showNotification('error', 'Auto-save Failed', data.message || 'Unable to save grade');
            }
        })
        .catch(error => {
            isSaving = false;
            console.error('Auto-save error:', error);
            showNotification('error', 'Auto-save Error', 'Network error occurred');
        });
    }, 1000);
}

// Notification function
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
    }, 2400);
}

// Hide '-- Select --' selects during printing
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

// Attach auto-save listeners to all grade selects
document.addEventListener('DOMContentLoaded', function() {
    applyAcademicReadOnlyState();
    updatePageDisplay();
    startChecklistLiveRefresh();
    fetchAndUpdateChecklist();
    bindChecklistFieldListeners();

    const archiveGroup = document.getElementById('archiveChecklistGroup');
    const archivePopup = document.getElementById('archiveChecklistPopup');
    if (archiveGroup && archivePopup) {
      document.addEventListener('click', function(event) {
        if (!archiveGroup.contains(event.target)) {
          archivePopup.classList.remove('open');
        }
      });
    }

    if (academicHold.active) {
        showNotification('error', academicHold.title || 'Academic Hold', academicHold.short_message || academicHold.message || 'Your account is currently read-only.');
    }
});

document.addEventListener('visibilitychange', () => {
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

</script>
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
