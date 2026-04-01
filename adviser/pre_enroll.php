<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/vite_legacy.php';

// Check if adviser is logged in
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

// Get adviser name for header display
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

// Get database connection
$conn = getDBConnection();

// Get student_id from URL parameter if it exists, otherwise use session
$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : (isset($_SESSION['student_id']) ? $_SESSION['student_id'] : null);

if (!$student_id) {
    header("Location: checklist_eval.php");
    exit();
}

// Try the Laravel bootstrap first, then fall back to the legacy query block.
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$preEnrollmentBridgeData = null;
if ($useLaravelBridge) {
    $preEnrollmentBridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/bootstrap',
        [
            'student_id' => $student_id,
            'year' => isset($_GET['year']) ? $_GET['year'] : '1st Yr',
            'semester' => isset($_GET['semester']) ? $_GET['semester'] : '1st Sem',
        ]
    );
}

if (is_array($preEnrollmentBridgeData) && !empty($preEnrollmentBridgeData['success'])) {
    $student_data = (array) ($preEnrollmentBridgeData['student'] ?? []);
    $last_name = (string) ($student_data['last_name'] ?? '');
    $first_name = (string) ($student_data['first_name'] ?? '');
    $middle_name = (string) ($student_data['middle_name'] ?? '');
    $picture = (string) ($student_data['picture'] ?? '');
    $address = (string) ($student_data['address'] ?? '');
    $age = (string) ($student_data['age'] ?? 'N/A');
    $all_subjects = (array) ($preEnrollmentBridgeData['all_subjects'] ?? []);
    $preenroll_courses = (array) ($preEnrollmentBridgeData['preenroll_courses'] ?? []);
    $year_level = (string) ($preEnrollmentBridgeData['year_level'] ?? '');
    $next_year = (string) ($preEnrollmentBridgeData['next_year'] ?? '1st Yr');
    $next_semester = (string) ($preEnrollmentBridgeData['next_semester'] ?? '1st Sem');
    $failed_subjects = (array) ($preEnrollmentBridgeData['failed_subjects'] ?? []);
    $retention_status = (string) ($preEnrollmentBridgeData['retention_status'] ?? 'None');
    $retention_color = (string) ($preEnrollmentBridgeData['retention_color'] ?? '#28a745');
    $failed_percentage = (float) ($preEnrollmentBridgeData['failed_percentage'] ?? 0);
    $max_units = (int) ($preEnrollmentBridgeData['max_units'] ?? 0);
    $show_retention_status = (bool) ($preEnrollmentBridgeData['show_retention_status'] ?? false);
    $total_subjects_in_semester = (int) ($preEnrollmentBridgeData['total_subjects_in_semester'] ?? 0);
    $failed_subjects_in_semester = (int) ($preEnrollmentBridgeData['failed_subjects_in_semester'] ?? 0);
    $latest_year = (string) ($preEnrollmentBridgeData['latest_year'] ?? '');
    $latest_semester = (string) ($preEnrollmentBridgeData['latest_semester'] ?? '');
} else {
// Fetch student details
$stmt = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, picture, birthdate, program, curriculum_year, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address FROM student_info WHERE student_number = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student_result = $stmt->get_result();
    
if ($student_result->num_rows > 0) {
    $student_data = $student_result->fetch_assoc();
    $last_name = $student_data['last_name'];
    $first_name = $student_data['first_name'];
    $middle_name = $student_data['middle_name'];
    $picture = $student_data['picture'] ?? '';
    $address = isset($student_data['address']) ? $student_data['address'] : '';
    // Calculate age from birthdate if available, else 'N/A'
    $age = 'N/A';
    if (!empty($student_data['birthdate'])) {
        $dob = DateTime::createFromFormat('Y-m-d', $student_data['birthdate']);
        $now = new DateTime();
        if ($dob && $dob <= $now) {
            $age = $now->diff($dob)->y;
        }
    }
} else {
    header("Location: checklist_eval.php");
    exit();
}
$stmt->close();

// Get the selected year and semester from URL parameters
$year = isset($_GET['year']) ? $_GET['year'] : '1st Yr';
$semester = isset($_GET['semester']) ? $_GET['semester'] : '1st Sem';

// Function to get next semester
function getNextSemester($current_year, $current_semester) {
    $years = ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
    $semesters = ['1st Sem', '2nd Sem'];
    
    $year_index = array_search($current_year, $years);
    $sem_index = array_search($current_semester, $semesters);
    
    if ($sem_index == 0) {
        // If first semester, return same year but next semester
        return [$current_year, $semesters[1]];
    } else {
        // If second semester, return next year and first semester
        if ($year_index < count($years) - 1) {
            return [$years[$year_index + 1], $semesters[0]];
        } else {
            // If last year second sem, return same (no more next semester)
            return [$current_year, $current_semester];
        }
    }
}

// Get next semester for pre-enrollment
list($next_year, $next_semester) = getNextSemester($year, $semester);
$student_program = trim((string)($student_data['program'] ?? ''));
$student_curriculum_year = psResolveStudentCurriculumYear($conn, $student_id, $student_program);
$curriculum_rows = psFetchChecklistCourses($conn, $student_id, $student_program, '', $student_curriculum_year, $student_program);

$all_subjects = array();
$preenroll_courses = [];
$failed_subjects = array();
$failed_counts = array(
    '1st Yr' => array('1st Sem' => 0, '2nd Sem' => 0),
    '2nd Yr' => array('1st Sem' => 0, '2nd Sem' => 0),
    '3rd Yr' => array('1st Sem' => 0, '2nd Sem' => 0, 'Mid Year' => 0),
    '4th Yr' => array('1st Sem' => 0, '2nd Sem' => 0)
);
$year_level = '';
$year_map = [
    '1st Yr' => 1,
    '2nd Yr' => 2,
    '3rd Yr' => 3,
    '4th Yr' => 4
];
$results = array();

foreach ($curriculum_rows as $row) {
    $row_year = trim((string)($row['year'] ?? ''));
    $row_semester = trim((string)($row['semester'] ?? ''));
    $course_code = psNormalizeCourseCode($row['course_code'] ?? '');
    if ($row_year === '' || $row_semester === '' || $course_code === '') {
        continue;
    }

    $subject_row = [
        'course_code' => $course_code,
        'course_title' => (string)($row['course_title'] ?? ''),
        'credit_unit_lec' => (int)($row['credit_unit_lec'] ?? 0),
        'credit_unit_lab' => (int)($row['credit_unit_lab'] ?? 0),
        'total_units' => (int)($row['credit_unit_lec'] ?? 0) + (int)($row['credit_unit_lab'] ?? 0),
        'pre_requisite' => (string)($row['pre_requisite'] ?? 'NONE'),
        'final_grade' => (string)($row['final_grade'] ?? 'No Grade'),
        'evaluator_remarks' => (string)($row['evaluator_remarks'] ?? ''),
    ];

    if (!isset($all_subjects[$row_year])) {
        $all_subjects[$row_year] = array();
    }
    if (!isset($all_subjects[$row_year][$row_semester])) {
        $all_subjects[$row_year][$row_semester] = array();
    }
    $all_subjects[$row_year][$row_semester][] = $subject_row;

    if ($row_year === $next_year && $row_semester === $next_semester) {
        $preenroll_courses[] = [
            'course_code' => $course_code,
            'course_title' => (string)($row['course_title'] ?? ''),
            'credit_unit_lec' => (int)($row['credit_unit_lec'] ?? 0),
            'credit_unit_lab' => (int)($row['credit_unit_lab'] ?? 0),
        ];
    }

    if (!isset($failed_subjects[$row_year])) {
        $failed_subjects[$row_year] = array();
    }
    if (!isset($failed_subjects[$row_year][$row_semester])) {
        $failed_subjects[$row_year][$row_semester] = array();
    }
    $failed_subjects[$row_year][$row_semester][] = $subject_row;
    if (isset($failed_counts[$row_year][$row_semester])) {
        $failed_counts[$row_year][$row_semester]++;
    }

    $grade = trim((string)($subject_row['final_grade'] ?? ''));
    $remarks = trim((string)($subject_row['evaluator_remarks'] ?? ''));
    if ($grade !== '' && $grade !== 'No Grade' && $remarks !== '' && strcasecmp($remarks, 'Pending') !== 0) {
        $result_key = $row_year . '|' . $row_semester;
        if (!isset($results[$result_key])) {
            $results[$result_key] = array(
                'year' => $row_year,
                'semester' => $row_semester,
                'completed' => 0
            );
        }
        $results[$result_key]['completed']++;
    }
}

$highest_year = '1st Yr';
foreach ($results as $result) {
    $current = $result['year'];
    if (!isset($year_map[$current]) || !isset($year_map[$highest_year])) {
        continue;
    }
    if ($year_map[$current] > $year_map[$highest_year]) {
        $highest_year = $current;
    }
}

$pre_enroll_year_query = "SELECT year_level FROM pre_enrollments WHERE student_id = ? ORDER BY created_at DESC LIMIT 1";
$pre_stmt = $conn->prepare($pre_enroll_year_query);
$pre_stmt->bind_param("s", $student_id);
$pre_stmt->execute();
$pre_result = $pre_stmt->get_result();

if ($pre_result->num_rows > 0) {
    $year_level = $pre_result->fetch_assoc()['year_level'];
} else if (!empty($results)) {
    $year_level = $highest_year;
} else {
    $year_level = '1st Yr';
}
$pre_stmt->close();

// Calculate retention policy status based on failed subjects from the latest semester with APPROVED grades
$retention_status = 'None';
$retention_color = '#28a745'; // Green for None
$failed_percentage = 0;
$max_units = 0; // 0 means no limit
$total_subjects_in_semester = 0;
$failed_subjects_in_semester = 0;
$show_retention_status = false; // Only show when there are approved grades

// Find the latest semester with approved grades and calculate retention status
$latest_year = '';
$latest_semester = '';

// Iterate through failed_subjects to find the latest semester with approved grades
foreach ($failed_subjects as $yr => $semesters) {
    foreach ($semesters as $sem => $subjects) {
        $has_approved_grades = false;
        foreach ($subjects as $subject) {
            // Check if this subject has an approved grade (not 'No Grade' and not 'Pending')
            if ($subject['final_grade'] !== 'No Grade' && $subject['final_grade'] !== '' && $subject['final_grade'] !== null
                && $subject['evaluator_remarks'] !== 'Pending' && $subject['evaluator_remarks'] !== '') {
                $has_approved_grades = true;
                break;
            }
        }
        
        if ($has_approved_grades) {
            // Update latest year/semester based on ordering
            $year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
            $sem_order = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
            
            if ($latest_year === '' || 
                $year_order[$yr] > $year_order[$latest_year] || 
                ($year_order[$yr] === $year_order[$latest_year] && $sem_order[$sem] > $sem_order[$latest_semester])) {
                $latest_year = $yr;
                $latest_semester = $sem;
            }
        }
    }
}

// Calculate retention status based on the latest semester with APPROVED grades only
if ($latest_year !== '' && $latest_semester !== '') {
    $subjects_in_latest = $failed_subjects[$latest_year][$latest_semester] ?? [];
    $total_subjects_in_semester = count($subjects_in_latest);
    $failed_subjects_in_semester = 0;
    
    foreach ($subjects_in_latest as $subject) {
        $grade = $subject['final_grade'];
        $remarks = $subject['evaluator_remarks'];
        
        // Skip subjects without approved grades (must have grade AND not be Pending)
        if ($grade === 'No Grade' || $grade === '' || $grade === null) {
            continue;
        }
        if ($remarks === 'Pending' || $remarks === '') {
            continue; // Skip pending/unapproved grades
        }
        
        // Check if subject is failed
        // Failed grades: 4.0, 5.0, 0.00, INC, DRP, or any grade > 3.0
        $is_failed = false;
        
        if ($grade === 'INC' || $grade === 'DRP' || $grade === 'W') {
            $is_failed = true;
        } else {
            $numeric_grade = floatval($grade);
            if ($numeric_grade === 0.00 || $numeric_grade > 3.0) {
                $is_failed = true;
            }
        }
        
        if ($is_failed) {
            $failed_subjects_in_semester++;
        }
    }
    
    // Count only subjects with APPROVED grades for percentage calculation
    $subjects_with_approved_grades = 0;
    foreach ($subjects_in_latest as $subject) {
        if ($subject['final_grade'] !== 'No Grade' && $subject['final_grade'] !== '' && $subject['final_grade'] !== null
            && $subject['evaluator_remarks'] !== 'Pending' && $subject['evaluator_remarks'] !== '') {
            $subjects_with_approved_grades++;
        }
    }
    
    // Calculate failure percentage only if there are approved grades
    if ($subjects_with_approved_grades > 0) {
        $show_retention_status = true; // Show the retention status mark
        $failed_percentage = ($failed_subjects_in_semester / $subjects_with_approved_grades) * 100;
        
        // Determine retention status
        if ($failed_percentage >= 75) {
            $retention_status = 'Disqualified';
            $retention_color = '#dc3545'; // Red
        } else if ($failed_percentage >= 51) {
            $retention_status = 'Probationary';
            $retention_color = '#fd7e14'; // Orange
            $max_units = 15; // Can only enroll 15 units
        } else if ($failed_percentage >= 30) {
            $retention_status = 'Warning';
            $retention_color = '#ffc107'; // Yellow
        } else {
            $retention_status = 'None';
            $retention_color = '#28a745'; // Green
        }
    }
}

}

$conn->close();

$adviserShellPayload = htmlspecialchars(json_encode([
    'title' => 'Pre-Enrollment Assessment',
    'description' => 'Guide the student through course loading, verify the next academic term, and keep the pre-enrollment paperwork organized in one adviser workspace.',
    'accent' => 'amber',
    'pageKey' => 'student-list',
    'stats' => [
        ['label' => 'Adviser', 'value' => html_entity_decode($adviser_name, ENT_QUOTES, 'UTF-8')],
        ['label' => 'Student ID', 'value' => (string) $student_id],
        ['label' => 'Year Level', 'value' => (string) $year_level],
        ['label' => 'Next Term', 'value' => trim((string) $next_year . ' ' . (string) $next_semester)],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$adviserPreenrollWorkspacePayload = htmlspecialchars(json_encode([
    'heading' => 'Pre-enrollment command deck',
    'description' => 'Use these shortcuts to review the student summary, move to the subject grid, and print the current form without changing the enrollment flow underneath.',
    'actions' => [
        ['key' => 'back', 'title' => 'Back to student list', 'description' => 'Return to the adviser checklist list and choose another student.', 'href' => 'checklist_eval.php'],
        ['key' => 'print', 'title' => 'Print form', 'description' => 'Open the browser print dialog for the current pre-enrollment form.', 'type' => 'print'],
        ['key' => 'form', 'title' => 'Jump to student form', 'description' => 'Scroll to the student information block and the enrollment details fields.', 'type' => 'scroll', 'selector' => '.container'],
        ['key' => 'subjects', 'title' => 'Jump to subject table', 'description' => 'Move directly to the pre-enrollment subject list for faster review.', 'type' => 'scroll', 'selector' => 'table'],
    ],
    'notes' => [
        'The current PHP save and load handlers remain the source of truth for enrollment data.',
        'This enhancement only adds a cleaner adviser command layer on top.',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-Enrollment Form</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <?= renderLegacyViteTags([
        'resources/js/adviser-shell.jsx',
        'resources/js/adviser-preenroll-workspace.jsx',
    ], '../laravel-app/public/build/') ?>
    <style>
        /* Popup styles */
        .popup {
            display: none;
            position: fixed;
            left: 0;
            top: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0,0,0,0.5);
            z-index: 9999;
            overflow: auto;
        }

        .popup-content {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            background-color: #fff;
            padding: 40px 36px 36px 36px;
            border-radius: 16px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.22);
            min-width: 360px;
            max-width: 95vw;
            text-align: center;
        }

        .popup-title {
            font-size: 2rem;
            font-weight: bold;
            color: #206018;
            margin-bottom: 18px;
            letter-spacing: 1px;
        }

        .popup-message {
            font-size: 1.25rem;
            color: #222;
            margin-bottom: 32px;
        }

        .popup select {
            width: 100%;
            padding: 8px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .popup-buttons {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 24px;
        }

        .popup-btn {
            padding: 10px 28px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .popup-btn.confirm {
            background-color: #206018;
            color: white;
        }
        .popup-btn.confirm:hover {
            background-color: #2a7c24;
        }

        .popup-btn.cancel {
            background-color: #6c757d;
            color: white;
        }
        .popup-btn.cancel:hover {
            background-color: #495057;
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
            margin-right: 20px;
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
        }

        /* Add margin to body to account for fixed title bar */
        body {
            margin-top: 60px !important;
        }

        /* Position the logo */
        .logo {
            position: relative;
            top: -590px; /* Adjust as needed */
            left: 270px; /* Adjust as needed */
            width: 110px; /* Resize as needed */
            margin-top: 100px; /* Add more space above the logo */
        }

        body {
            font-family: Arial, sans-serif;
            background: url('../pix/school.jpg') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            padding: 0;
            min-height: 60vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-y: auto;
        }
        h1 {
            text-align: center;
            color: #333;
            margin: 5px 0;
        }
        
        .container {
            max-width: 1020px;
            width: calc(100% - 40px);
            padding: 20px 40px;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 1px 5px rgba(0, 0, 0, 0.1);
            height: fit-content;
            min-height: auto;
            position: relative;
            overflow-y: visible;
            margin: 10px auto;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        table {
            position: relative;
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            text-align: center;
            flex: 1;
            min-height: 50px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
            font-size: 10px;
        }
        th {
            background-color: #206018;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        tr:hover {
            background-color: #f1f1f1;
        }
        .btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 110px;
            padding: 10px 15px;
            text-align: center;
            background-color: #206018;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin: 0 2px;
            font-weight: bold;
            font-size: 14px;
            text-decoration: none;
            line-height: normal;
            box-sizing: border-box;
            white-space: nowrap;
        }
        .btn:hover {
            background-color: #2a7c24;
        }



        .CvSUlogo .logo img {
            width: 110px;
            height: 110px;
            position: relative;
            right: -200px;
            
        }
        
        .profile .pic img {
            width: 110px;
            height: 110px;
            position: relative;
            right: -200px;
            top: -100px;
        }

        h4 {
            text-align: center;
            color: #000000;
            margin-bottom: -20px;
            position: relative;
            top: -40px;
        }
        
        h3 {
            margin-top: -220px;
            margin-bottom: 40px;
            text-align: center;
        }

        h5 {
            margin-top: 30px;
            margin-left: 50px;
            margin-bottom: -20px;
        }
        
        .leftside .info h5,
        .leftside .info2 h5,
        .leftside .info3 h5 {
            text-align: right;
            text-wrap: nowrap;
            position: relative;
            top: -75px;
        }
        h1, h2 {
            margin-left: 0px; /* Add margin to avoid overlap with the logo */
        }
        
        .leftside .info h5 { right: 217px; }
        .leftside .info2 h5 { right: 200px; }
        .leftside .info3 h5 { right: 199px; }

        body, table, th, td {
            font-size: 9px;
        }
        th, td {
            padding: 4px;
        }
        .logo {
            margin-top: 40px;
        }
        h4, h1, h2 {
            margin-top: 5px;
            margin-bottom: 5px;
        }
        
        /* Adjust spacing for input groups */
        h2 input, h2 select {
            margin: 2px 0;
        }
        @media print {
            @page {
                margin: 8mm;
            }
        }

        /* Draggable Checklist Window Styles */
        .checklist-window {
            display: none;
            position: fixed;
            top: 50px;
            left: 50px;
            width: 900px;
            height: 600px;
            background: white;
            border: 2px solid #206018;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            z-index: 10000;
            overflow: hidden;
        }

        .checklist-window.dragging {
            user-select: none;
        }

        .checklist-header {
            background: #206018;
            color: white;
            padding: 12px 16px;
            cursor: move;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
        }

        .checklist-close {
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .checklist-close:hover {
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
        }

        .checklist-content {
            height: calc(100% - 48px);
            overflow: auto;
            padding: 0;
        }

        .checklist-iframe {
            width: 100%;
            height: 100%;
            border: none;
            pointer-events: auto;
        }

        /* Disable buttons and interactive elements in iframe */
        .checklist-iframe {
            position: relative;
        }

        .checklist-iframe::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        /* Resize handles */
        .resize-handle {
            position: absolute;
            background: transparent;
            transition: background-color 0.2s ease;
        }

        .resize-handle:hover {
            background-color: rgba(32, 96, 24, 0.3);
        }

        .resize-handle.se {
            bottom: 0;
            right: 0;
            width: 20px;
            height: 20px;
            cursor: se-resize;
            border-radius: 0 0 8px 0;
        }

        .resize-handle.se::after {
            content: '';
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-bottom: 8px solid #206018;
            opacity: 0.6;
        }

        .resize-handle.e {
            top: 20px;
            right: 0;
            width: 10px;
            height: calc(100% - 40px);
            cursor: e-resize;
        }

        .resize-handle.s {
            bottom: 0;
            left: 20px;
            width: calc(100% - 40px);
            height: 10px;
            cursor: s-resize;
        }

        /* Add visual indicators for resize handles */
        .resize-handle.e::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 3px;
            height: 20px;
            background: linear-gradient(to bottom, transparent, #206018, transparent);
            opacity: 0.5;
        }

        .resize-handle.s::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 3px;
            background: linear-gradient(to right, transparent, #206018, transparent);
            opacity: 0.5;
        }
    </style>
    <script>
        function editTable() {
        // Get all curriculum subjects data from PHP
        const allSubjects = <?php echo json_encode($all_subjects); ?>;
        const failedSubjects = <?php echo json_encode($failed_subjects); ?>;

        // Function to check if a subject is passed
        function isSubjectPassed(courseCode) {
            // Check all years and semesters in failedSubjects
            for (const year in failedSubjects) {
                for (const sem in failedSubjects[year]) {
                    const foundSubject = failedSubjects[year][sem].find(s => s.course_code === courseCode);
                    if (foundSubject) {
                        // Only consider as passed if evaluator has approved it
                        if (foundSubject.evaluator_remarks !== 'Approved') {
                            return false;
                        }
                        // If grade is "No Grade" and approved, consider it as passing
                        if (foundSubject.final_grade === 'No Grade') {
                            return true;
                        }
                        // Check if it's US or S grade (passing grades)
                        if (foundSubject.final_grade === 'US' || foundSubject.final_grade === 'S') {
                            return true;
                        }
                        const grade = parseFloat(foundSubject.final_grade);
                        // Check if it's a passing grade (1.0 to 3.0) and approved
                        if (!isNaN(grade) && grade >= 1.0 && grade <= 3.0) {
                            return true;
                        }
                        // If grade exists but is failing (0.00, 4.00, 5.00), return false
                        if (!isNaN(grade) && (grade === 0.00 || grade >= 4.0)) {
                            return false;
                        }
                        // If empty string or null, return false
                        if (foundSubject.final_grade === '' || foundSubject.final_grade === null) {
                            return false;
                        }
                    }
                }
            }
            // If subject not found in failedSubjects, it means no grade recorded, so not passed
            return false;
        }

        // Filter out passed subjects and count remaining subjects
        const filteredSubjects = {};
        for (const [year, semesters] of Object.entries(allSubjects)) {
            filteredSubjects[year] = {};
            for (const [sem, subjects] of Object.entries(semesters)) {
                // Filter subjects based on their grades using the same logic as isSubjectPassed
                const availableSubjects = subjects.filter(subject => {
                    return !isSubjectPassed(subject.course_code);
                });

                if (availableSubjects.length > 0) {
                    filteredSubjects[year][sem] = availableSubjects;
                }
            }
        }

        // Pre-calculate counts for each year/semester to avoid scope issues in template literal
        const dropdownOptions = [];
        for (const [year, semesters] of Object.entries(allSubjects || {})) {
            for (const [sem, subjects] of Object.entries(semesters || {})) {
                // Count all subjects that are not passed (including special subjects that should be shown but disabled)
                const availableSubjects = subjects.filter(subject => {
                    return !isSubjectPassed(subject.course_code);
                });
                const count = availableSubjects.length;
                dropdownOptions.push(`<option value="${year}_${sem}">${year} - ${sem} (${count})</option>`);
            }
        }

        const popup = document.createElement('div');
        popup.className = 'popup';
        popup.innerHTML = `
            <div class="popup-content" style="width: 80%; max-width: 800px;">
                <h3 style="margin-top: 0; font-size: 24px; color: #206018;">Select Year and Semester</h3>
                <select id="yearSemSelect" onchange="updateSubjectsTable(this.value)" style="margin-bottom: 15px;">
                    <option value="">Select Year and Semester</option>
                    ${dropdownOptions.join('')}
                </select>
                <div id="subjectsTableContainer" style="margin-top: 20px; max-height: 400px; overflow-y: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white; text-align: center;">Select</th>
                                <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white; text-align: center;">Course Code</th>
                                <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white; text-align: center;">Course Title</th>
                                <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white; text-align: center;">Units</th>
                                <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white; text-align: center;">Pre-requisite</th>
                            </tr>
                        </thead>
                        <tbody id="subjectsTableBody">
                        </tbody>
                    </table>
                </div>
                <div class="popup-buttons" style="margin-top: 15px;">
                    <button class="popup-btn cancel" onclick="closePopup()">Cancel</button>
                    <button class="popup-btn confirm" onclick="confirmSelection()">Confirm</button>
                </div>
            </div>
        `;
        document.body.appendChild(popup);
        popup.style.display = 'block';
    }


        function closePopup() {
            const popup = document.querySelector('.popup');
            if (popup) {
                popup.remove();
            }
        }

        // Global function to check prerequisites (moved outside editTable for scope access)
        function checkPrerequisites(subject, failedSubjects, allSubjects) {
            // Helper function to check if a subject is passed
            function isSubjectPassedInCheck(courseCode) {
                for (const year in failedSubjects) {
                    for (const sem in failedSubjects[year]) {
                        const foundSubject = failedSubjects[year][sem].find(s => s.course_code === courseCode);
                        if (foundSubject) {
                            // Only consider as passed if evaluator has approved it
                            if (foundSubject.evaluator_remarks !== 'Approved') {
                                return false;
                            }
                            // If grade is "No Grade" and approved, consider it as passing
                            if (foundSubject.final_grade === 'No Grade') {
                                return true;
                            }
                            // If grade is "No Grade", consider it as passing
                            if (foundSubject.final_grade === 'No Grade') {
                                return true;
                            }
                            // Check if it's US or S grade (passing grades)
                            if (foundSubject.final_grade === 'US' || foundSubject.final_grade === 'S') {
                                return true;
                            }
                        }
                        if (foundSubject) {
                            const grade = parseFloat(foundSubject.final_grade);
                            // Check if it's a passing grade (1.0 to 3.0)
                            if (!isNaN(grade) && grade >= 1.0 && grade <= 3.0) {
                                return true;
                            }
                            // If grade exists but is failing (0.00, 4.00, 5.00), return false
                            if (!isNaN(grade) && (grade === 0.00 || grade >= 4.0)) {
                                return false;
                            }
                            // If grade is "No Grade" or empty string, return false
                            if (foundSubject.final_grade === 'No Grade' || foundSubject.final_grade === '' || foundSubject.final_grade === null) {
                                return false;
                            }
                        }
                    }
                }
                // If subject not found in failedSubjects, it means no grade recorded, so not passed
                return false;
            }

            // Helper function to check if all subjects in specified years/semesters are passed
            function areAllSubjectsPassedInCheck(yearsToCheck) {
                for (const year of yearsToCheck.years) {
                    const sems = yearsToCheck.semesters[year] || ['1st Sem', '2nd Sem'];
                    for (const sem of sems) {
                        if (allSubjects[year] && allSubjects[year][sem]) {
                            for (const subj of allSubjects[year][sem]) {
                                if (!isSubjectPassedInCheck(subj.course_code)) {
                                    return false; // Found an unpassed subject
                                }
                            }
                        }
                    }
                }
                return true; // All subjects are passed
            }

            // Special case for DCIT 60 (Methods of Research)
            if (subject.course_code === 'DCIT 60') {
                return areAllSubjectsPassedInCheck({
                    years: ['1st Yr', '2nd Yr'],
                    semesters: {
                        '1st Yr': ['1st Sem', '2nd Sem'],
                        '2nd Yr': ['1st Sem', '2nd Sem']
                    }
                });
            }
            
            // Special case for COSC 199 (Practicum)
            if (subject.course_code === 'COSC 199') {
                return areAllSubjectsPassedInCheck({
                    years: ['1st Yr', '2nd Yr', '3rd Yr'],
                    semesters: {
                        '1st Yr': ['1st Sem', '2nd Sem'],
                        '2nd Yr': ['1st Sem', '2nd Sem'],
                        '3rd Yr': ['1st Sem', '2nd Sem']
                    }
                });
            }

            // Special case for COSC 200A (Undergraduate Thesis 1)
            if (subject.course_code === 'COSC 200A') {
                return areAllSubjectsPassedInCheck({
                    years: ['1st Yr', '2nd Yr', '3rd Yr'],
                    semesters: {
                        '1st Yr': ['1st Sem', '2nd Sem'],
                        '2nd Yr': ['1st Sem', '2nd Sem'],
                        '3rd Yr': ['1st Sem', '2nd Sem', 'Mid Year']
                    }
                });
            }

            // Regular prerequisite checking for other subjects
            if (!subject.pre_requisite || subject.pre_requisite === '' || subject.pre_requisite.toUpperCase() === 'NONE') {
                return true; // No prerequisites or "NONE" - can be selected
            }
            
            // Split prerequisites if there are multiple (assuming they're comma-separated)
            const prerequisites = subject.pre_requisite.split(',').map(p => p.trim());
            
            // Check each prerequisite
            for (const prereq of prerequisites) {
                // Skip empty prerequisites or "NONE"
                if (prereq === '' || prereq.toUpperCase() === 'NONE') {
                    continue;
                }
                
                // Check if this prerequisite is passed
                if (!isSubjectPassedInCheck(prereq)) {
                    return false; // Prerequisites not met
                }
            }
            return true; // All prerequisites are met
        }

        function updateSubjectsTable(value) {
        if (!value) {
            // If "Select" option is chosen, clear the table
            const tbody = document.getElementById('subjectsTableBody');
            tbody.innerHTML = '';
            return;
        }
        
        const [year, sem] = value.split('_');
        const allSubjects = <?php echo json_encode($all_subjects); ?>;
        const failedSubjects = <?php echo json_encode($failed_subjects); ?>;
        const tbody = document.getElementById('subjectsTableBody');
        tbody.innerHTML = '';

        // Function to check if a subject is passed
        function isSubjectPassed(courseCode) {
            // Check all years and semesters in failedSubjects
            for (const year in failedSubjects) {
                for (const sem in failedSubjects[year]) {
                    const foundSubject = failedSubjects[year][sem].find(s => s.course_code === courseCode);
                    if (foundSubject) {
                        // Only consider as passed if evaluator has approved it
                        if (foundSubject.evaluator_remarks !== 'Approved') {
                            return false;
                        }
                        // If grade is "No Grade" and approved, consider it as passing
                        if (foundSubject.final_grade === 'No Grade') {
                            return true;
                        }
                        // Check if it's US or S grade (passing grades)
                        if (foundSubject.final_grade === 'US' || foundSubject.final_grade === 'S') {
                            return true;
                        }
                        const grade = parseFloat(foundSubject.final_grade);
                        // Check if it's a passing grade (1.0 to 3.0) and approved
                        if (!isNaN(grade) && grade >= 1.0 && grade <= 3.0) {
                            return true;
                        }
                        // If grade exists but is failing (0.00, 4.00, 5.00), return false
                        if (!isNaN(grade) && (grade === 0.00 || grade >= 4.0)) {
                            return false;
                        }
                        // If grade is "No Grade" or empty string, return false
                        if (foundSubject.final_grade === 'No Grade' || foundSubject.final_grade === '' || foundSubject.final_grade === null) {
                            return false;
                        }
                    }
                }
            }
            // If subject not found in failedSubjects, it means no grade recorded, so not passed
            return false;
        }

        if (allSubjects[year] && allSubjects[year][sem]) {
            // Show all subjects for the selected year/semester (including special subjects that may be disabled)
            // Filter only those that are not passed (grade < 4.0) - this includes subjects that should be shown but disabled
            const availableSubjects = allSubjects[year][sem].filter(subject => {
                return !isSubjectPassed(subject.course_code);
            });

            availableSubjects.forEach(subject => {
                const row = tbody.insertRow();
                
                // Check prerequisites using the existing function
                const prerequisitesMet = checkPrerequisites(subject, failedSubjects, allSubjects);
                
                // Checkbox cell
                const checkboxCell = row.insertCell();
                checkboxCell.style.cssText = 'border: 1px solid #ddd; padding: 8px; text-align: center;';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.value = subject.course_code;
                checkbox.id = 'subject_' + subject.course_code;
                
                // Disable checkbox if prerequisites are not met
                if (!prerequisitesMet) {
                    checkbox.disabled = true;
                    
                    // Generate specific tooltip message based on subject type
                    let tooltipMessage = 'Prerequisites not met: ';
                    if (subject.course_code === 'DCIT 60') {
                        tooltipMessage += 'All subjects from 1st-2nd year must be passed';
                    } else if (subject.course_code === 'COSC 199') {
                        tooltipMessage += 'All subjects from 1st-3rd year must be passed';
                    } else if (subject.course_code === 'COSC 200A') {
                        tooltipMessage += 'All subjects from 1st-3rd year (including Mid Year) must be passed';
                    } else if (subject.pre_requisite && subject.pre_requisite !== '' && subject.pre_requisite.toUpperCase() !== 'NONE') {
                        tooltipMessage += `Required: ${subject.pre_requisite}`;
                    } else {
                        tooltipMessage += 'Check requirements';
                    }
                    
                    checkbox.title = tooltipMessage;
                    checkboxCell.style.opacity = '0.5';
                    // Add visual styling to show the subject is disabled
                    row.style.opacity = '0.6';
                    row.style.backgroundColor = '#f5f5f5';
                }
                
                checkboxCell.appendChild(checkbox);
                
                // Course code cell
                const codeCell = row.insertCell();
                codeCell.style.cssText = 'border: 1px solid #ddd; padding: 8px; text-align: center;';
                codeCell.textContent = subject.course_code;
                
                // Course title cell
                const titleCell = row.insertCell();
                titleCell.style.cssText = 'border: 1px solid #ddd; padding: 8px;';
                titleCell.textContent = subject.course_title;
                
                // Units cell
                const unitsCell = row.insertCell();
                unitsCell.style.cssText = 'border: 1px solid #ddd; padding: 8px; text-align: center;';
                unitsCell.textContent = subject.total_units || (subject.credit_unit_lec + subject.credit_unit_lab);
                
                // Pre-requisite cell
                const prereqCell = row.insertCell();
                prereqCell.style.cssText = 'border: 1px solid #ddd; padding: 8px;';
                prereqCell.textContent = subject.pre_requisite || 'None';
            });
            
            // If no subjects available, show a message
            if (availableSubjects.length === 0) {
                const row = tbody.insertRow();
                const cell = row.insertCell();
                cell.colSpan = 5;
                cell.style.cssText = 'border: 1px solid #ddd; padding: 20px; text-align: center; font-style: italic; color: #666;';
                cell.textContent = 'All subjects for this year/semester have been completed.';
            }
        } else {
            // No subjects found for this year/semester
            const row = tbody.insertRow();
            const cell = row.insertCell();
            cell.colSpan = 5;
            cell.style.cssText = 'border: 1px solid #ddd; padding: 20px; text-align: center; font-style: italic; color: #666;';
            cell.textContent = 'No subjects found for the selected year/semester.';
        }
    }

        function confirmSelection() {
            const select = document.getElementById('yearSemSelect');
            const [year, sem] = select.value.split('_');
            
            // Update the Year Level field based on selected year
            const yearLevelInput = document.querySelector('input[name="year_level"]');
            if (yearLevelInput) {
                yearLevelInput.value = year;
            }
            
            // Get all checked subjects
            const checkedBoxes = document.querySelectorAll('#subjectsTableBody input[type="checkbox"]:checked');
            const selectedRows = Array.from(checkedBoxes).map(checkbox => {
                const row = checkbox.closest('tr');
                const units = row.cells[3].textContent.trim(); // Get units from the Units column (4th column, index 3)
                return {
                    courseCode: checkbox.value,
                    courseTitle: row.cells[2].textContent.trim(),
                    units: units
                };
            });

            // Find the table in the main form
            const mainTable = document.querySelector('.container table');
            if (mainTable) {
                // Clear existing table content
                mainTable.innerHTML = `
                    <thead>
                        <tr>
                            <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE CODE</th>
                            <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE TITLE</th>
                            <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">GRADE</th>
                            <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">UNIT</th>
                            <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">DAY</th>
                        </tr>
                    </thead>
                    <tbody>
                `;

                // Add selected subjects to the table and calculate total units
                let totalUnits = 0;
                selectedRows.forEach(subject => {
                    const units = parseFloat(subject.units) || 0;
                    totalUnits += units;
                    mainTable.innerHTML += `
                        <tr>
                            <td style="border: 1px solid black; padding: 8px; text-align: center;">${subject.courseCode}</td>
                            <td style="border: 1px solid black; padding: 8px; text-align: left;">${subject.courseTitle}</td>
                            <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                            <td style="border: 1px solid black; padding: 8px; text-align: center;">${subject.units}</td>
                            <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                        </tr>
                    `;
                });

                // Add total row
                mainTable.innerHTML += `
                    <tr>
                        <td colspan="3" style="border: 1px solid black; padding: 8px; text-align: right; font-weight: bold;">Total Units:</td>
                        <td style="border: 1px solid black; padding: 8px; text-align: center; font-weight: bold;">${totalUnits}</td>
                        <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                    </tr>
                </tbody>`;
            }
            
            closePopup();
        }

        function showTransactionHistory(showAfterSubmit = false) {
            const popup = document.createElement('div');
            popup.className = 'popup';
            popup.innerHTML = `
                <div class="popup-content" style="width: 60%; max-width: 800px;">
                    <h3 style="margin-top: 0; font-size: 24px; color: #206018;">Transaction History</h3>
                    <div style="margin-top: 20px; max-height: 400px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white;">Time & Date</th>
                                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white;">Course Codes</th>
                                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white;">Course Titles</th>
                                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white;">Total Units</th>
                                    <th style="border: 1px solid #ddd; padding: 8px; background-color: #206018; color: white;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="transactionTableBody">
                            </tbody>
                        </table>
                    </div>
                    <div class="popup-buttons">
                        <button class="popup-btn cancel" onclick="closeTransactionPopup()">Close</button>
                    </div>
                </div>
            `;
            document.body.appendChild(popup);
            popup.style.display = 'block';

            // Fetch transaction history
            const urlParams = new URLSearchParams(window.location.search);
            const studentId = urlParams.get('student_id');
            const url = studentId ? `get_transaction_history.php?student_id=${studentId}` : 'get_transaction_history.php';
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const tbody = document.getElementById('transactionTableBody');
                    tbody.innerHTML = '';
                    if (data.success && data.transactions) {
                        data.transactions.forEach(transaction => {
                            // Calculate total units (sum of all units in the comma-separated string)
                            let totalUnits = 0;
                            if (transaction.units) {
                                totalUnits = transaction.units.split(',').reduce((sum, u) => {
                                    const num = parseFloat(u.trim());
                                    return sum + (isNaN(num) ? 0 : num);
                                }, 0);
                            }
                            tbody.innerHTML += `
                                <tr data-enrollment-id="${transaction.id}">
                                    <td style="border: 1px solid #ddd; padding: 8px;">${transaction.created_at}</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">${transaction.course_codes}</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">${transaction.course_titles}</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">${totalUnits}</td>
                                    <td style="border: 1px solid #ddd; padding: 8px;">
                                        <button class="btn" style="padding: 4px 10px; font-size: 10px;" onclick="loadHistoricalEnrollment('${transaction.id}')">View</button>
                                    </td>
                                </tr>
                            `;
                        });
                    }
                })
                .catch(error => console.error('Error loading transaction history:', error));
        }

        function closeTransactionPopup() {
            const popup = document.querySelector('.popup');
            if (popup) {
                popup.remove();
            }
        }

        function loadHistoricalEnrollment(enrollmentId) {
            fetch(`get_enrollment_details.php?enrollment_id=${enrollmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.enrollment) {
                        // Update all form fields
                        if (data.enrollment.year_level !== undefined) {
                            document.querySelector('input[name="year_level"]').value = data.enrollment.year_level;
                        }
                        if (data.enrollment.name !== undefined) {
                            document.querySelector('input[name="name"]').value = data.enrollment.name;
                        }
                        if (data.enrollment.student_id !== undefined) {
                            document.querySelector('input[name="student_number"]').value = data.enrollment.student_id;
                        }
                        if (data.enrollment.course !== undefined) {
                            document.querySelector('select[name="course"]').value = data.enrollment.course;
                        }
                        if (data.enrollment.section_major !== undefined) {
                            document.querySelector('input[name="section_major"]').value = data.enrollment.section_major || 'N/A';
                        }
                        if (data.enrollment.classification !== undefined) {
                            document.querySelector('select[name="classification"]').value = data.enrollment.classification;
                        }
                        if (data.enrollment.registration_status !== undefined) {
                            document.querySelector('select[name="registration_status"]').value = data.enrollment.registration_status;
                        }
                        if (data.enrollment.scholarship_awarded !== undefined) {
                            document.querySelector('input[name="scholarship_awarded"]').value = data.enrollment.scholarship_awarded;
                        }
                        if (data.enrollment.mode_of_payment !== undefined) {
                            document.querySelector('select[name="mode_of_payment"]').value = data.enrollment.mode_of_payment;
                        }
                        // Update the table with historical courses
                        const mainTable = document.querySelector('.container table');
                        if (mainTable && data.enrollment.courses) {
                            let totalUnits = 0;
                            let tableHtml = `
                                <thead>
                                    <tr>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE CODE</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE TITLE</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">GRADE</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">UNIT</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">DAY</th>
                                    </tr>
                                </thead>
                                <tbody>
                            `;
                            data.enrollment.courses.forEach(course => {
                                totalUnits += parseFloat(course.units) || 0;
                                tableHtml += `
                                    <tr>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;">${course.course_code}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: left;">${course.course_title}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;">${course.units}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;">${course.day || ''}</td>
                                    </tr>
                                `;
                            });
                            tableHtml += `
                                <tr>
                                    <td colspan="3" style="border: 1px solid black; padding: 8px; text-align: right; font-weight: bold;">Total Units:</td>
                                    <td style="border: 1px solid black; padding: 8px; text-align: center; font-weight: bold;">${totalUnits}</td>
                                    <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                                </tr>
                            </tbody>`;
                            mainTable.innerHTML = tableHtml;
                        }
                        // Close the transaction history popup
                        closeTransactionPopup();
                    }
                })
                .catch(error => console.error('Error loading enrollment details:', error));
        }

        function submitForm() {
            // Custom confirmation modal
            const existingModal = document.getElementById('customConfirmModal');
            if (existingModal) existingModal.remove();
            const modal = document.createElement('div');
            modal.id = 'customConfirmModal';
            modal.className = 'popup';
            modal.innerHTML = `
                <div class="popup-content">
                    <h2 class="popup-title">Confirm Submission</h2>
                    <p class="popup-message">Are you sure you want to submit this pre-enrollment form?</p>
                    <div class="popup-buttons">
                        <button class="popup-btn cancel" id="cancelSubmitBtn" type="button">Cancel</button>
                        <button class="popup-btn confirm" id="confirmSubmitBtn" type="button">Submit</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            modal.style.display = 'block';

            document.getElementById('cancelSubmitBtn').onclick = function() {
                modal.remove();
            };
            document.getElementById('confirmSubmitBtn').onclick = function() {
                modal.remove();
                // Get all the form data
                const formData = {
                    student_id: document.querySelector('input[name="student_number"]').value,
                    name: document.querySelector('input[name="name"]').value,
                    year_level: document.querySelector('input[name="year_level"]').value,
                    course: document.querySelector('select[name="course"]').value,
                    section_major: document.querySelector('input[name="section_major"]').value,
                    classification: document.querySelector('select[name="classification"]').value,
                    registration_status: document.querySelector('select[name="registration_status"]').value,
                    scholarship_awarded: document.querySelector('input[name="scholarship_awarded"]').value,
                    mode_of_payment: document.querySelector('select[name="mode_of_payment"]').value,
                    courses: []
                };

                // Get all rows from the table except the last one (total row)
                const rows = Array.from(document.querySelectorAll('table tbody tr')).slice(0, -1);
                rows.forEach(row => {
                    const cells = row.cells;
                    if (cells.length >= 5) {
                        formData.courses.push({
                            course_code: cells[0].textContent.trim(),
                            course_title: cells[1].textContent.trim(),
                            units: cells[3].textContent.trim(),
                            day: cells[4].textContent.trim()
                        });
                    }
                });

                // Check for duplicate in transaction history before submitting
                const tbody = document.getElementById('transactionTableBody');
                let isDuplicate = false;
                if (tbody && tbody.children.length > 0) {
                    for (let i = 0; i < tbody.children.length; i++) {
                        const tr = tbody.children[i];
                        const tds = tr.querySelectorAll('td');
                        if (tds.length >= 4) {
                            // Extract previous transaction data
                            const prevCodes = tds[1].textContent.trim();
                            const prevTitles = tds[2].textContent.trim();
                            // If you display more fields in transaction history, extract and compare them here
                            const currentCodes = formData.courses.map(c => c.course_code).join(',');
                            const currentTitles = formData.courses.map(c => c.course_title).join(',');
                            // Compare all relevant form fields
                            let allMatch = true;
                            allMatch = allMatch && (prevCodes === currentCodes);
                            allMatch = allMatch && (prevTitles === currentTitles);
                            // Compare other form fields (if you display them in transaction history)
                            // Example: year_level, course, section_major, classification, registration_status, scholarship_awarded, mode_of_payment
                            // If you want to compare these, you need to add them to transaction history table and extract here
                            // For now, just compare codes/titles
                            if (allMatch
                                && formData.year_level === (window.lastYearLevel || formData.year_level)
                                && formData.course === (window.lastCourse || formData.course)
                                && formData.section_major === (window.lastSectionMajor || formData.section_major)
                                && formData.classification === (window.lastClassification || formData.classification)
                                && formData.registration_status === (window.lastRegistrationStatus || formData.registration_status)
                                && formData.scholarship_awarded === (window.lastScholarshipAwarded || formData.scholarship_awarded)
                                && formData.mode_of_payment === (window.lastModeOfPayment || formData.mode_of_payment)
                            ) {
                                isDuplicate = true;
                                break;
                            }
                        }
                    }
                }
                if (isDuplicate) {
                    showSuccessModal('Duplicate pre-enrollment detected. Submission blocked.', true);
                    return;
                }

                // Send the data to the server
                const urlParams = new URLSearchParams(window.location.search);
                const studentId = urlParams.get('student_id');
                const saveUrl = studentId ? `save_pre_enrollment.php?student_id=${studentId}` : 'save_pre_enrollment.php';

                fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(formData)
                })
                .then(response => {
                    return response.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            showSuccessModal('Pre-enrollment form submitted successfully!');

                            // Always refresh transaction history after submit
                            const tbody = document.getElementById('transactionTableBody');
                            if (tbody) {
                                const urlParams = new URLSearchParams(window.location.search);
                                const studentId = urlParams.get('student_id');
                                const url = studentId ? `get_transaction_history.php?student_id=${studentId}` : 'get_transaction_history.php';
                                fetch(url)
                                    .then(response => response.json())
                                    .then(data => {
                                        tbody.innerHTML = '';
                                        if (data.success && data.transactions) {
                                            data.transactions.forEach(transaction => {
                                                tbody.innerHTML += `
                                                    <tr data-enrollment-id="${transaction.id}" style="cursor: pointer;" onclick="loadHistoricalEnrollment(${transaction.id})">
                                                        <td style=\"border: 1px solid #ddd; padding: 8px;\">${transaction.created_at}</td>
                                                        <td style=\"border: 1px solid #ddd; padding: 8px;\">${transaction.course_codes}</td>
                                                        <td style=\"border: 1px solid #ddd; padding: 8px;\">${transaction.course_titles}</td>
                                                        <td style=\"border: 1px solid #ddd; padding: 8px;\">${transaction.total_units}</td>
                                                    </tr>
                                                `;
                                            });
                                        }
                                    })
                                    .catch(error => console.error('Error loading transaction history:', error));
                            }

                            // Reload the form data to ensure it's displayed
                            const urlParams2 = new URLSearchParams(window.location.search);
                            const studentId2 = urlParams2.get('student_id');
                            const url2 = studentId2 ? `load_pre_enrollment.php?student_id=${studentId2}` : 'load_pre_enrollment.php';

                            fetch(url2)
                                .then(response => response.json())
                                .then(data => {
                                    if (data.success && data.data) {
                                        document.querySelector('select[name="course"]').value = data.data.course;
                                        document.querySelector('input[name="section_major"]').value = data.data.section_major || 'N/A';
                                        document.querySelector('select[name="classification"]').value = data.data.classification;
                                        document.querySelector('select[name="registration_status"]').value = data.data.registration_status;
                                        document.querySelector('input[name="scholarship_awarded"]').value = data.data.scholarship_awarded;
                                        document.querySelector('select[name="mode_of_payment"]').value = data.data.mode_of_payment;
                                        if (data.data.year_level) {
                                            document.querySelector('input[name="year_level"]').value = data.data.year_level;
                                        }

                                        const mainTable = document.querySelector('.container table');
                                        if (mainTable && data.data.courses && data.data.courses.length > 0) {
                                            let totalUnits = 0;
                                            let tableHtml = `
                                                <thead>
                                                    <tr>
                                                        <th style=\"border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;\">COURSE CODE</th>
                                                        <th style=\"border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;\">COURSE TITLE</th>
                                                        <th style=\"border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;\">GRADE</th>
                                                        <th style=\"border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;\">UNIT</th>
                                                        <th style=\"border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;\">DAY</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                            `;

                                            data.data.courses.forEach(course => {
                                                const units = parseFloat(course.units) || 0;
                                                totalUnits += units;
                                                tableHtml += `
                                                    <tr>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: center;\">${course.course_code}</td>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: left;\">${course.course_title}</td>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: center;\"></td>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: center;\">${course.units}</td>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: center;\">${course.day || ''}</td>
                                                    </tr>
                                                `;
                                            });

                                            tableHtml += `
                                                    <tr>
                                                        <td colspan=\"3\" style=\"border: 1px solid black; padding: 8px; text-align: right; font-weight: bold;\">Total Units:</td>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: center; font-weight: bold;\">${totalUnits}</td>
                                                        <td style=\"border: 1px solid black; padding: 8px; text-align: center;\"></td>
                                                    </tr>
                                                </tbody>
                                            `;

                                            mainTable.innerHTML = tableHtml;
                                        }
                                    }
                                })
                                .catch(error => console.error('Error reloading saved data:', error));
                        } else {
                            showSuccessModal('Error submitting form: ' + data.message, true);
                        }
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        showSuccessModal('Error processing server response', true);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showSuccessModal('Error submitting form. Please try again.', true);
                });
            }
            }

        
    </script>
           </script>
                <script>// Custom success modal function
                function showSuccessModal(message, isError = false) {
                    // Remove any existing success modal
                    const existingModal = document.getElementById('successModal');
                    if (existingModal) existingModal.remove();
                    const modal = document.createElement('div');
                    modal.id = 'successModal';
                    modal.className = 'popup';
                    modal.innerHTML = `
                        <div class="popup-content" style="min-width:320px; max-width:400px;">
                            <h2 class="popup-title" style="color:${isError ? '#b30000' : '#206018'}; font-size:2rem;">${isError ? 'Error' : 'Success'}</h2>
                            <p class="popup-message" style="font-size:1.2rem; margin-bottom:28px;">${message}</p>
                            <div class="popup-buttons" style="justify-content:center;">
                                <button class="popup-btn confirm" id="successOkBtn" type="button" style="background-color:${isError ? '#b30000' : '#206018'};">OK</button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal);
                    modal.style.display = 'block';
                    document.getElementById('successOkBtn').onclick = function() {
                        modal.remove();
                    };
                    // Auto-close after 3 seconds if success
                    if (!isError) {
                        setTimeout(() => {
                            if (document.getElementById('successModal')) {
                                document.getElementById('successModal').remove();
                            }
                        }, 3000);
                    }
                }</script>
</head>
<body>

    <!-- Title Bar -->
    <div class="title-bar">
        <div class="title-content">
            <img src="../img/cav.png" alt="CvSU Logo">
            PRE - ENROLLMENT ASSESSMENT
        </div>
        <div class="adviser-name"><?= $adviser_name ; echo " | Adviser " ?></div>
    </div>

    <div class="adviser-react-shell-slot" data-adviser-shell="<?= $adviserShellPayload ?>"></div>
    <div class="adviser-react-workspace-slot" data-adviser-preenroll-workspace="<?= $adviserPreenrollWorkspacePayload ?>"></div>

    <!-- Add the logo -->
    <div class="container" style="position: relative;<?php if ($show_retention_status): ?> padding-top: 60px;<?php endif; ?>">
        <?php if ($show_retention_status): ?>
        <!-- Retention Status Mark - Only shown when adviser has approved grades -->
        <div class="retention-status" style="position: absolute; top: 10px; left: 10px; padding: 8px 16px; border-radius: 5px; font-weight: bold; font-size: 14px; background-color: <?php echo $retention_color; ?>; color: <?php echo ($retention_status === 'Warning') ? '#000' : '#fff'; ?>; box-shadow: 0 2px 4px rgba(0,0,0,0.2); z-index: 100;">
            Retention Status: <?php echo $retention_status; ?>
            <?php if ($failed_percentage > 0): ?>
                <span style="font-weight: normal; font-size: 12px;">(<?php echo number_format($failed_percentage, 1); ?>% Failed)</span>
            <?php endif; ?>
            <?php if ($max_units > 0): ?>
                <br><span style="font-weight: normal; font-size: 12px;">Max Units: <?php echo $max_units; ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <h1 style="font-size: medium; font-weight: bold;">PRE-ENROLLMENT FORM</h1>

    <h2 style="font-size: medium; font-weight: normal;">
        Name: <input type="text" name="name" value="<?php echo htmlspecialchars(ucwords(strtolower($last_name . ', ' . $first_name . (!empty($middle_name) ? ' ' . $middle_name : '')))); ?>" style="width: 300px; border: none; border-bottom: 1px solid #000" readonly>
        Student Number: <input type="text" name="student_number" value="<?php echo htmlspecialchars($student_id); ?>" style="width: 150px; border: none; border-bottom: 1px solid #000" readonly>
    Age: <input type="text" name="age" value="<?php echo htmlspecialchars($age); ?>" style="width: 50px; border: none; border-bottom: 1px solid #000" readonly>
    </h2>
    <h2 style="font-size: medium; font-weight: normal;">
        Year Level: <input type="text" name="year_level" value="<?php echo htmlspecialchars($year_level); ?>" style="width: 50px; border: none; border-bottom: 1px solid #000" readonly>
        Course: <select name="course" style="width: 160px; border: none; border-bottom: 1px solid #000;">
            <option value="BSCS" selected>BSCS</option>
            <option value="BSIT">BSIT</option>
            <option value="BSIS">BSIS</option>
            <option value="BSCE">BSCE</option>
            <option value="BSEd">BSEd</option>
            <option value="BSA">BSA</option>
            <option value="BSBA">BSBA</option>
            <option value="BSTM">BSTM</option>
            <option value="BSPsych">BSPsych</option>
            <!-- Add more courses as needed -->
        </select>
        Section & Major: <input type="text" name="section_major" value="N/A" placeholder="N/A" style="width: 150px; border: none; border-bottom: 1px solid #000" oninput="if(this.value === '') this.value='N/A';" defaultValue="N/A">
        Address: <input type="text" name="address" value="<?php echo htmlspecialchars($address); ?>" style="width: 250px; border: none; border-bottom: 1px solid #000" readonly>
    </h2>
<h2 style="font-size: medium; font-weight: normal;">
    Classification: 
    <select name="classification" style="width: 160px; border: none; border-bottom: 1px solid #000;">
        <option value="Old" selected>Old</option>
        <option value="New">New</option>
        <option value="Transferee">Transferee</option>
        <option value="Cross Reg. Form">Cross Reg. Form</option>
    </select>
</h2>
<h2 style="font-size: medium; font-weight: normal;">
    Registration Status: 
    <select name="registration_status" style="width: 160px; border: none; border-bottom: 1px solid #000;">
        <option value="Regular" selected>Regular</option>
        <option value="Irregular">Irregular</option>
    </select>
</h2>
<h2 style="font-size: medium; font-weight: normal;">
    Scholarship Awarded: <input type="text" name="scholarship_awarded" value="N/A" style="width: 300px; border: none; border-bottom: 1px solid #000" 
</h2>
    <h2 style="font-size: medium; font-weight: normal; margin-bottom: 0;">
    Mode of Payment: 
    <select name="mode_of_payment" style="width: 160px; border: none; border-bottom: 1px solid #000;">
        <option value="Cash" selected>Cash</option>
        <option value="Installment">Installment</option>
    </select>
</h2>

    <table style="border: 1px solid black; margin-top: 20px;">
        <thead>
            <tr>
                <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE CODE</th>
                <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE TITLE</th>
                <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">GRADE</th>
                <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">UNIT</th>
                <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">DAY</th>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>

        <div style="margin-top: 20px;">
            <!-- Center buttons container -->
            <div style="display: flex; justify-content: center; gap: 10px;">
                <button class="btn" onclick="editTable()">Edit</button>
            </div>
            <!-- Checklist shortcut button - bottom left -->
            <div style="position: absolute; bottom: 10px; left: 10px;">
                <button class="btn" onclick="openChecklistWindow()" style="background-color: #2196F3;">View Checklist</button>
            </div>
        </div>
    </div> <!-- End of container -->

    <!-- Submit and Back buttons below container -->
    <div style="width: 100%; max-width: 1020px; margin: -20px auto; padding: 0 40px; position: relative;">
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
            <a href="checklist_eval.php" class="btn">Back</a>
            <button class="btn" onclick="showTransactionHistory()">Transaction History</button>
            <button class="btn" onclick="submitForm()">Submit</button>
        </div>
    </div>

    <!-- Draggable Checklist Window -->
    <div id="checklistWindow" class="checklist-window">
        <div class="checklist-header" id="checklistHeader">
            <span>Student Checklist</span>
            <button class="checklist-close" onclick="closeChecklistWindow()">&times;</button>
        </div>
        <div class="checklist-content">
            <iframe id="checklistIframe" class="checklist-iframe" src=""></iframe>
        </div>
        <!-- Resize handles -->
        <div class="resize-handle se" id="resizeHandleSE"></div>
        <div class="resize-handle e" id="resizeHandleE"></div>
        <div class="resize-handle s" id="resizeHandleS"></div>
    </div>

    <script>
        // Load saved pre-enrollment data when page loads
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const studentId = urlParams.get('student_id');
            const url = studentId ? `load_pre_enrollment.php?student_id=${studentId}` : 'load_pre_enrollment.php';
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Update form fields
                        document.querySelector('select[name="course"]').value = data.data.course;
                        document.querySelector('input[name="section_major"]').value = data.data.section_major || 'N/A';
                        document.querySelector('select[name="classification"]').value = data.data.classification;
                        document.querySelector('select[name="registration_status"]').value = data.data.registration_status;
                        document.querySelector('input[name="scholarship_awarded"]').value = data.data.scholarship_awarded;
                        document.querySelector('select[name="mode_of_payment"]').value = data.data.mode_of_payment;
                        // Update year level if it exists in the saved data
                        if (data.data.year_level) {
                            document.querySelector('input[name="year_level"]').value = data.data.year_level;
                        }

                        // Update table with courses
                        const mainTable = document.querySelector('.container table');
                        if (mainTable && data.data.courses && data.data.courses.length > 0) {
                            let totalUnits = 0;
                            let tableHtml = `
                                <thead>
                                    <tr>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE CODE</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">COURSE TITLE</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">GRADE</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">UNIT</th>
                                        <th style="border: 1px solid black; background-color: #206018; color: white; padding: 8px; text-align: center;">DAY</th>
                                    </tr>
                                </thead>
                                <tbody>
                            `;

                            data.data.courses.forEach(course => {
                                const units = parseFloat(course.units) || 0;
                                totalUnits += units;
                                tableHtml += `
                                    <tr>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;">${course.course_code}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: left;">${course.course_title}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;">${course.units}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;">${course.day || ''}</td>
                                    </tr>
                                `;
                            });

                            tableHtml += `
                                    <tr>
                                        <td colspan="3" style="border: 1px solid black; padding: 8px; text-align: right; font-weight: bold;">Total Units:</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center; font-weight: bold;">${totalUnits}</td>
                                        <td style="border: 1px solid black; padding: 8px; text-align: center;"></td>
                                    </tr>
                                </tbody>
                            `;

                            mainTable.innerHTML = tableHtml;
                        }
                    }
                })
                .catch(error => console.error('Error loading saved data:', error));
        });

        // Draggable Checklist Window Functions
        let isDragging = false;
        let isResizing = false;
        let currentResizeHandle = null;
        let dragStartX, dragStartY, windowStartX, windowStartY;
        let resizeStartX, resizeStartY, windowStartWidth, windowStartHeight;

        function openChecklistWindow() {
            const checklistWindow = document.getElementById('checklistWindow');
            const iframe = document.getElementById('checklistIframe');
            
            // Get student ID from URL
            const urlParams = new URLSearchParams(window.location.search);
            const studentId = urlParams.get('student_id') || '<?php echo $student_id; ?>';
            
            // Set the iframe source to the checklist page with popup parameter
            iframe.src = `../student/checklist_stud.php?student_id=${studentId}&popup=1`;
            
            // Add event listener to disable buttons when iframe loads
            iframe.onload = function() {
                try {
                    const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                    
                    // Create style element to disable interactive elements
                    const style = iframeDoc.createElement('style');
                    style.textContent = `
                        /* Hide sidebar and title bar for popup view */
                        .sidebar {
                            display: none !important;
                        }
                        .title-bar {
                            display: none !important;
                        }
                        .main-content {
                            margin-left: 0 !important;
                            width: 100% !important;
                            padding-left: 0 !important;
                        }
                        body {
                            padding-top: 0 !important;
                            margin-top: 0 !important;
                        }
                        .container {
                            margin-left: 0 !important;
                            width: 100% !important;
                            max-width: 100% !important;
                        }
                        
                        /* Hide navigation and menu elements */
                        .menu-toggle, .sidebar-header, .sidebar-menu {
                            display: none !important;
                        }
                        
                        button, input[type="submit"], input[type="button"], .btn {
                            pointer-events: none !important;
                            opacity: 0.6 !important;
                            cursor: not-allowed !important;
                            background-color: #cccccc !important;
                            color: #666666 !important;
                        }
                        
                        input[type="text"], input[type="password"], select, textarea {
                            pointer-events: none !important;
                            background-color: #f5f5f5 !important;
                            cursor: not-allowed !important;
                        }
                        
                        a {
                            pointer-events: none !important;
                            color: #999999 !important;
                            text-decoration: none !important;
                        }
                        
                        /* Add a watermark to indicate read-only mode */
                        body::before {
                            content: "VIEW ONLY";
                            position: fixed;
                            top: 20px;
                            right: 20px;
                            background: rgba(255, 0, 0, 0.1);
                            color: #ff0000;
                            padding: 5px 10px;
                            border-radius: 5px;
                            font-weight: bold;
                            font-size: 12px;
                            z-index: 9999;
                            border: 1px solid #ff0000;
                        }
                    `;
                    iframeDoc.head.appendChild(style);
                    
                    // Disable all form submissions
                    const forms = iframeDoc.querySelectorAll('form');
                    forms.forEach(form => {
                        form.addEventListener('submit', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            return false;
                        });
                    });
                    
                } catch (error) {
                    // Cannot access iframe content due to cross-origin restrictions
                }
            };
            
            // Show the window
            checklistWindow.style.display = 'block';
            
            // Center the window
            const windowWidth = checklistWindow.offsetWidth;
            const windowHeight = checklistWindow.offsetHeight;
            const screenWidth = window.innerWidth;
            const screenHeight = window.innerHeight;
            
            checklistWindow.style.left = Math.max(0, (screenWidth - windowWidth) / 2) + 'px';
            checklistWindow.style.top = Math.max(0, (screenHeight - windowHeight) / 2) + 'px';
        }

        function closeChecklistWindow() {
            const checklistWindow = document.getElementById('checklistWindow');
            const iframe = document.getElementById('checklistIframe');
            
            checklistWindow.style.display = 'none';
            iframe.src = ''; // Clear iframe source
        }

        // Make window draggable
        document.getElementById('checklistHeader').addEventListener('mousedown', function(e) {
            isDragging = true;
            const checklistWindow = document.getElementById('checklistWindow');
            
            dragStartX = e.clientX;
            dragStartY = e.clientY;
            windowStartX = parseInt(window.getComputedStyle(checklistWindow).left, 10);
            windowStartY = parseInt(window.getComputedStyle(checklistWindow).top, 10);
            
            checklistWindow.classList.add('dragging');
            
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (isDragging) {
                const checklistWindow = document.getElementById('checklistWindow');
                const newX = windowStartX + e.clientX - dragStartX;
                const newY = windowStartY + e.clientY - dragStartY;
                
                // Keep window within screen bounds
                const maxX = window.innerWidth - checklistWindow.offsetWidth;
                const maxY = window.innerHeight - checklistWindow.offsetHeight;
                
                checklistWindow.style.left = Math.max(0, Math.min(maxX, newX)) + 'px';
                checklistWindow.style.top = Math.max(0, Math.min(maxY, newY)) + 'px';
            }
            
            if (isResizing) {
                const checklistWindow = document.getElementById('checklistWindow');
                const currentLeft = parseInt(checklistWindow.style.left || 0);
                const currentTop = parseInt(checklistWindow.style.top || 0);
                
                // Set minimum and maximum dimensions
                const minWidth = 400;
                const minHeight = 300;
                const maxWidth = window.innerWidth - currentLeft - 20;
                const maxHeight = window.innerHeight - currentTop - 20;
                
                switch (currentResizeHandle) {
                    case 'se': // Bottom-right corner - resize both width and height
                        const newWidth = windowStartWidth + (e.clientX - resizeStartX);
                        const newHeight = windowStartHeight + (e.clientY - resizeStartY);
                        checklistWindow.style.width = Math.max(minWidth, Math.min(maxWidth, newWidth)) + 'px';
                        checklistWindow.style.height = Math.max(minHeight, Math.min(maxHeight, newHeight)) + 'px';
                        break;
                        
                    case 'e': // Right edge - resize width only
                        const widthE = windowStartWidth + (e.clientX - resizeStartX);
                        checklistWindow.style.width = Math.max(minWidth, Math.min(maxWidth, widthE)) + 'px';
                        break;
                        
                    case 's': // Bottom edge - resize height only
                        const heightS = windowStartHeight + (e.clientY - resizeStartY);
                        checklistWindow.style.height = Math.max(minHeight, Math.min(maxHeight, heightS)) + 'px';
                        break;
                }
            }
        });

        document.addEventListener('mouseup', function() {
            if (isDragging) {
                const checklistWindow = document.getElementById('checklistWindow');
                checklistWindow.classList.remove('dragging');
            }
            isDragging = false;
            isResizing = false;
            currentResizeHandle = null;
        });

        // Make window resizable
        document.getElementById('resizeHandleSE').addEventListener('mousedown', function(e) {
            isResizing = true;
            currentResizeHandle = 'se';
            const checklistWindow = document.getElementById('checklistWindow');
            
            resizeStartX = e.clientX;
            resizeStartY = e.clientY;
            windowStartWidth = parseInt(window.getComputedStyle(checklistWindow).width, 10);
            windowStartHeight = parseInt(window.getComputedStyle(checklistWindow).height, 10);
            
            e.preventDefault();
            e.stopPropagation();
        });

        // Add resize functionality for right edge
        document.getElementById('resizeHandleE').addEventListener('mousedown', function(e) {
            isResizing = true;
            currentResizeHandle = 'e';
            const checklistWindow = document.getElementById('checklistWindow');
            
            resizeStartX = e.clientX;
            resizeStartY = e.clientY;
            windowStartWidth = parseInt(window.getComputedStyle(checklistWindow).width, 10);
            windowStartHeight = parseInt(window.getComputedStyle(checklistWindow).height, 10);
            
            e.preventDefault();
            e.stopPropagation();
        });

        // Add resize functionality for bottom edge
        document.getElementById('resizeHandleS').addEventListener('mousedown', function(e) {
            isResizing = true;
            currentResizeHandle = 's';
            const checklistWindow = document.getElementById('checklistWindow');
            
            resizeStartX = e.clientX;
            resizeStartY = e.clientY;
            windowStartWidth = parseInt(window.getComputedStyle(checklistWindow).width, 10);
            windowStartHeight = parseInt(window.getComputedStyle(checklistWindow).height, 10);
            
            e.preventDefault();
            e.stopPropagation();
        });

        // Handle window resize to keep checklist window within bounds
        window.addEventListener('resize', function() {
            const checklistWindow = document.getElementById('checklistWindow');
            if (checklistWindow.style.display === 'block') {
                const windowRect = checklistWindow.getBoundingClientRect();
                const maxX = window.innerWidth - windowRect.width;
                const maxY = window.innerHeight - windowRect.height;
                
                if (windowRect.left > maxX) {
                    checklistWindow.style.left = Math.max(0, maxX) + 'px';
                }
                if (windowRect.top > maxY) {
                    checklistWindow.style.top = Math.max(0, maxY) + 'px';
                }
            }
        });
    </script>

    <style>
        @media print {
            .btn {
                display: none;
            }
        }
    </style>
</body>
</html>
