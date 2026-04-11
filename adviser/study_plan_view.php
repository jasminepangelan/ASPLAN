<?php
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

function aspvLoadStudentWithinAdviserScope(mysqli $conn, string $studentId, string $adviserProgram, array $batches): ?array
{
    if (trim($studentId) === '' || trim($adviserProgram) === '' || empty($batches)) {
        return null;
    }

    $batchPlaceholders = implode(',', array_fill(0, count($batches), '?'));
    $query = "
        SELECT student_number, last_name, first_name, middle_name, program, date_of_admission
        FROM student_info
        WHERE student_number = ?
          AND program = ?
          AND LEFT(student_number, 4) IN ($batchPlaceholders)
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return null;
    }

    $params = array_merge([$studentId, $adviserProgram], array_values($batches));
    $types = 'ss' . str_repeat('s', count($batches));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $student ?: null;
}

$conn = getDBConnection();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['student_id']) || trim($_GET['student_id']) === '') {
    die("Invalid student ID.");
}

$adviser_id = (int)$_SESSION['id'];
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';
$student_id = trim($_GET['student_id']);
$adviser_program = '';
$batches = [];
$student = null;
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/study-plan/adviser/bootstrap',
        [
            'bridge_authorized' => true,
            'adviser_id' => $adviser_id,
            'student_id' => $student_id,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $adviser_program = (string) ($bridgeData['adviser_program'] ?? '');
        $batches = isset($bridgeData['batches']) && is_array($bridgeData['batches']) ? $bridgeData['batches'] : [];
        $student = isset($bridgeData['student']) && is_array($bridgeData['student']) ? $bridgeData['student'] : null;

        if (!empty($bridgeData['access_granted']) && $student) {
            $bridgeLoaded = true;
        } elseif (!empty($bridgeData['message'])) {
            die((string) $bridgeData['message']);
        }
    }
}

if (!$bridgeLoaded) {
    $adviser_stmt = $conn->prepare("SELECT program FROM adviser WHERE id = ? LIMIT 1");
    $adviser_stmt->bind_param("i", $adviser_id);
    $adviser_stmt->execute();
    $adviser_data = $adviser_stmt->get_result()->fetch_assoc();
    $adviser_program = $adviser_data['program'] ?? '';

    $batch_stmt = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ?");
    $batch_stmt->bind_param("i", $adviser_id);
    $batch_stmt->execute();
    $batch_result = $batch_stmt->get_result();
    $batches = [];
    while ($row = $batch_result->fetch_assoc()) {
        $batches[] = trim($row['batch']);
    }

    if (empty($batches) || empty($adviser_program)) {
        die("Access denied. Adviser batch/program assignment is missing.");
    }

    $student = aspvLoadStudentWithinAdviserScope($conn, $student_id, $adviser_program, $batches);

    if (!$student) {
        die("Access denied. Student is not in your assigned batch/program.");
    }
}

$generator = new StudyPlanGenerator($student_id, $student['program']);
$study_plan = $generator->generateOptimizedPlan();
$completed_terms = $generator->getCompletedTerms();
$stats = $generator->getCompletionStats();
$ay_courses_by_term = $generator->getAllCoursesGroupedByTerm();

$admission_year = null;
$admission_date = (string)($student['date_of_admission'] ?? '');
if ($admission_date !== '' && strtotime($admission_date) !== false) {
    $admission_year = (int)date('Y', strtotime($admission_date));
}
if ($admission_year === null && strlen($student_id) >= 4) {
    $candidate_year = (int)substr($student_id, 0, 4);
    $current_year = (int)date('Y');
    if ($candidate_year >= 2000 && $candidate_year <= $current_year) {
        $admission_year = $candidate_year;
    }
}
if ($admission_year === null) {
    $admission_year = (int)date('Y') - 4;
}

$full_name = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));

$display_terms = [];
foreach ($completed_terms as $term) {
    $display_terms[] = [
        'year' => $term['year'],
        'semester' => $term['semester'],
        'courses' => $term['courses'] ?? [],
        'total_units' => $term['total_units'] ?? 0,
        'retention_status' => $term['retention_status'] ?? 'None',
        'is_completed_term' => true,
        'skipped' => false,
    ];
}

foreach ($study_plan as $term) {
    $display_terms[] = [
        'year' => $term['year'] ?? '',
        'semester' => $term['semester'] ?? '',
        'courses' => $term['courses'] ?? [],
        'total_units' => $term['total_units'] ?? 0,
        'max_units' => $term['max_units'] ?? null,
        'retention_status' => $term['retention_status'] ?? 'None',
        'skipped' => !empty($term['skipped']),
        'skip_reason' => $term['skip_reason'] ?? '',
        'is_completed_term' => false,
    ];
}

$last_planned_term = null;
$remaining_semesters = 0;
foreach ($study_plan as $term) {
    if (empty($term['skipped']) && !empty($term['courses'])) {
        $last_planned_term = $term;
        $remaining_semesters++;
    }
}

$estimated_graduation = null;
$graduation_school_year = '';
if ($last_planned_term) {
    $grad_year_num = (int)preg_replace('/[^0-9]/', '', (string)($last_planned_term['year'] ?? ''));
    $grad_sy_start = $admission_year + ($grad_year_num > 0 ? $grad_year_num - 1 : 0);
    $grad_sy_end = $grad_sy_start + 1;
    $estimated_graduation = (string)($last_planned_term['semester'] ?? '') . ', ' . (string)($last_planned_term['year'] ?? '');
    $graduation_school_year = "A.Y. $grad_sy_start-$grad_sy_end";
} elseif (!empty($stats['remaining_courses']) && (int)$stats['remaining_courses'] === 0) {
    $estimated_graduation = 'Completed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Study Plan</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #f4f6f9; color: #333; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 8px 14px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 46px;
        }
        .title-content { display: flex; align-items: center;font-size: 18px; font-weight: 800;}
        .title-content img { width: 32px; height: 32px; margin-right: 10px; cursor: pointer; }
        .adviser-name {
            font-size: 15px;
            font-weight: 600;
            color: #facc41;
            background: rgba(250, 204, 65, 0.15);
            border: 1px solid rgba(250, 204, 65, 0.3);
            border-radius: 7px;
            padding: 6px 10px;
        }
        .sidebar {
            width: 250px;
            height: calc(100vh - 46px);
            position: fixed;
            top: 46px;
            left: 0;
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }
        .sidebar.collapsed { transform: translateX(-100%); }
        .sidebar-header { padding: 0 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.2); margin-bottom: 8px; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            color: #fff;
            text-decoration: none;
            padding: 13px 20px;
            border-left: 3px solid transparent;
        }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); border-left-color: #4CAF50; }
        .sidebar-menu a.active { background: rgba(255,255,255,0.15); border-left-color: #4CAF50; }
        .sidebar-menu img { width: 20px; height: 20px; margin-right: 10px; filter: invert(1); }
        .menu-group-title { padding: 10px 20px 6px; font-size: 12px; text-transform: uppercase; color: rgba(255,255,255,0.7); font-weight: 600; }
        .main-content { margin-left: 250px; padding: 62px 14px 20px; transition: margin-left 0.3s ease; }
        .main-content.expanded { margin-left: 0; }
        .container { max-width: 1280px; margin: 0 auto; }
        .panel {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 14px;
            margin-bottom: 12px;
        }
        .heading {
            background: linear-gradient(135deg, #206018 0%, #2d8023 100%);
            color: #fff;
            border-radius: 8px;
            padding: 14px;
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 12px;
        }
        .student-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 8px;
            font-size: 14px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .stat-card {
            background: #f8fafc;
            border: 1px solid #e5ebf1;
            border-radius: 8px;
            padding: 10px;
        }
        .stat-label { font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .stat-value { font-size: 18px; font-weight: 700; color: #206018; }
        .term-card {
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 8px;
            margin-bottom: 12px;
            overflow: hidden;
        }
        .term-header {
            background: #f1f5f9;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            border-bottom: 1px solid #e6e6e6;
        }
        .term-title { font-weight: 700; color: #1f2937; }
        .term-meta { font-size: 12px; color: #555; }
        .term-badges {
            display: inline-flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .term-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .term-badge.completed {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #b7ddb9;
        }
        .term-badge.upcoming {
            background: #eef5ee;
            color: #206018;
            border: 1px solid #d1e0cf;
        }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; }
        th {
            background: #333;
            color: #fff;
            text-transform: uppercase;
            font-size: 12px;
            text-align: left;
        }
        td { text-align: left; vertical-align: middle; }
        th:nth-child(3), td:nth-child(3),
        th:nth-child(4), td:nth-child(4) {
            text-align: center;
        }
        .tag {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: 11px;
            margin-right: 5px;
            margin-bottom: 3px;
            font-weight: 600;
        }
        .tag-retake { background: #ffe8cc; color: #9a3412; }
        .tag-cross { background: #dbeafe; color: #1d4ed8; }
        .tag-completed { background: #e8f5e9; color: #2e7d32; }
        .term-divider {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 14px 0;
        }
        .term-divider span {
            background: #eef5ee;
            color: #206018;
            border: 1px solid #d1e0cf;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .ay-overview-wrap {
            display: flex;
            justify-content: center;
            margin: 14px 0 18px;
        }
        .ay-overview-btn {
            background: linear-gradient(135deg, #1565C0, #1976D2);
            color: #fff;
            border: none;
            padding: 11px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(21, 101, 192, 0.25);
        }
        .ay-overview-btn:hover {
            background: linear-gradient(135deg, #0d47a1, #1565C0);
        }
        .print-button {
            background: #206018;
            color: #ffffff;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(32, 96, 24, 0.3);
            transition: all 0.3s ease;
        }
        .print-button:hover {
            background: #2d8f22;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.4);
        }
        #ay-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 9000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 30px 15px;
        }
        #ay-modal-overlay.open {
            display: flex;
        }
        #ay-modal {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 900px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.25);
            overflow: hidden;
            margin: auto;
        }
        #ay-modal .modal-header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #ay-modal .modal-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
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
        #ay-modal .modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }
        #ay-modal .modal-body {
            padding: 20px 24px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .ay-term-block {
            margin-bottom: 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .ay-term-header {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 10px 16px;
            font-weight: 700;
            font-size: 14px;
            color: #206018;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        .ay-term-header:hover {
            background: linear-gradient(135deg, #d0edda, #aad9ae);
        }
        .ay-term-header .ay-term-toggle {
            font-size: 18px;
            transition: transform 0.2s;
        }
        .ay-term-header.collapsed .ay-term-toggle {
            transform: rotate(-90deg);
        }
        .ay-term-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .ay-term-body.hidden {
            display: none;
        }
        .ay-col {
            padding: 12px 16px;
        }
        .ay-col:first-child {
            border-right: 1px solid #eee;
        }
        .ay-col h4 {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 2px solid;
        }
        .ay-col.completed h4 {
            color: #2e7d32;
            border-color: #4CAF50;
        }
        .ay-col.uncomplete h4 {
            color: #c62828;
            border-color: #f44336;
        }
        .ay-course-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12px;
        }
        .ay-course-row:last-child {
            border-bottom: none;
        }
        .ay-course-code {
            font-weight: 600;
            white-space: nowrap;
            min-width: 72px;
        }
        .ay-course-title {
            flex: 1;
            color: #444;
            line-height: 1.4;
        }
        .ay-course-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .ay-badge-passed  { background: #e8f5e9; color: #2e7d32; }
        .ay-badge-failed  { background: #ffebee; color: #c62828; }
        .ay-badge-inc     { background: #fff3e0; color: #e65100; }
        .ay-badge-dropped { background: #f3e5f5; color: #6a1b9a; }
        .ay-badge-pending { background: #e3f2fd; color: #1565c0; }
        .ay-empty {
            font-size: 12px;
            color: #999;
            font-style: italic;
            padding: 6px 0;
        }
        .warning {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .btn-back {
            display: inline-block;
            text-decoration: none;
            padding: 7px 10px;
            background: #e5e7eb;
            border-radius: 5px;
            color: #333;
            font-weight: 600;
            font-size: 12px;
        }
        .academic-overview {
            background: #ffffff;
            border: 1px solid #d7e3d6;
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 4px 14px rgba(24, 66, 20, 0.08);
        }
        .academic-overview__header {
            color: #1f5d17;
            margin-bottom: 14px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .overview-meta {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 999px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .overview-meta.student {
            margin-left: auto;
            background: #eaf6e9;
            color: #2f6e28;
            border-color: #b9d9b5;
        }
        .overview-meta.generated {
            background: #f4f7f4;
            color: #5b6c59;
            border-color: #d9e2d8;
        }
        .academic-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
        }
        .study-plan-container {
            background: #ffffff;
            border-radius: 8px;
            padding: 40px 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 980px;
            margin: 0 auto;
        }
        .student-header {
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .student-details {
            font-size: 14px;
            line-height: 1.8;
            margin-top: 12px;
        }
        .student-details p {
            margin: 0;
        }
        .student-details .label {
            font-weight: 700;
            color: #000;
            display: inline-block;
            min-width: 120px;
        }
        .student-details .value {
            color: #000;
            font-weight: 400;
        }
        .semester-section {
            margin-bottom: 30px;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .semester-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .semester-section.completed-term {
            opacity: 0.92;
        }
        .semester-section.completed-term .course-table tbody tr {
            background: #f9fdf9;
        }
        .completed-badge {
            font-size: 10px;
            background: #4CAF50;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            margin-left: 6px;
            vertical-align: middle;
            font-weight: 600;
        }
        .plan-tag {
            font-size: 9px;
            color: white;
            padding: 1px 4px;
            border-radius: 3px;
            margin-left: 4px;
            vertical-align: middle;
            font-weight: 600;
            display: inline-block;
        }
        .plan-tag-retake { background: #f44336; }
        .plan-tag-cross { background: #2196F3; }
        .plan-tag-completed { background: #2e7d32; }
        .plan-tag-failed { background: #c62828; }
        .plan-tag-to-add { background: #2e7d32; }
        .completed-divider {
            text-align: center;
            margin: 30px 0;
            position: relative;
        }
        .completed-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #4CAF50, transparent);
        }
        .completed-divider span {
            background: #fff;
            padding: 5px 20px;
            position: relative;
            font-size: 13px;
            font-weight: 700;
            color: #206018;
            border: 2px solid #4CAF50;
            border-radius: 20px;
        }
        .semester-header {
            background: transparent;
            color: #000;
            padding: 8px 0;
            font-size: 14px;
            font-weight: 700;
            text-align: center;
            border: none;
        }
        .course-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            table-layout: auto;
        }
        .course-table thead {
            background: transparent;
        }
        .course-table th {
            padding: 8px 12px;
            text-align: center;
            font-weight: 700;
            color: #000;
            border: 1px solid #000;
            font-size: 13px;
            background: transparent;
        }
        .course-table tbody tr {
            border: 1px solid #000;
        }
        .course-table tbody tr:hover {
            background: #f9f9f9;
        }
        .course-table td {
            padding: 6px 12px;
            font-size: 13px;
            color: #000;
            border: 1px solid #000;
            text-align: left;
            vertical-align: middle;
        }
        .course-table td:first-child,
        .course-table td:nth-child(3),
        .course-table td:nth-child(4) {
            text-align: center;
        }
        .total-row {
            background: transparent;
            font-weight: 700;
        }
        .total-row td {
            border: 1px solid #000;
            padding: 6px 12px;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .study-plan-container {
                padding: 24px 18px;
            }
            .student-details .label {
                min-width: 95px;
            }
        }
        @media (max-width: 600px) {
            .ay-term-body {
                grid-template-columns: 1fr;
            }
            .ay-col:first-child {
                border-right: none;
                border-bottom: 1px solid #eee;
            }
        }
        @media print {
            body {
                background: #fff;
            }
            .header,
            .sidebar,
            .btn-back,
            .ay-overview-wrap,
            #ay-modal-overlay {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .container,
            .panel,
            .academic-overview,
            .study-plan-container,
            .semester-section {
                box-shadow: none !important;
            }
            .study-plan-container {
                padding: 0 !important;
                border: none !important;
            }
            .academic-overview,
            .semester-section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title-content">
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color:#d9e441;">ASPLAN</span>
        </div>
        <span class="adviser-name"><?= $adviser_name ?> | Adviser</span>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><h3>Adviser Panel</h3></div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>
            <div class="menu-group">
                <div class="menu-group-title">Student Management</div>
                <li><a href="pending_accounts.php"><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
                <li><a href="checklist_eval.php"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
                <li><a href="study_plan_list.php" class="active"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>
            </div>
            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="main-content" id="mainContent">
        <div class="container">
            <div class="academic-overview">
                <h3 class="academic-overview__header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5zm0 18c-4.41 0-8-3.59-8-8V9h16v3c0 4.41-3.59 8-8 8z"/>
                    </svg>
                    Academic Progress Overview
                    <span class="overview-meta student">Student: <?= htmlspecialchars((string)$student['student_number']) ?></span>
                    <span class="overview-meta generated" title="Page generated at <?= date('Y-m-d H:i:s') ?>">
                        Generated: <?= date('H:i:s') ?>
                    </span>
                </h3>
                <div class="academic-stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['completion_percentage']) ?>%</div>
                        <div class="stat-sub">Completion Rate</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['completed_courses']) ?>/<?= htmlspecialchars((string)($stats['total_courses'] ?? 0)) ?></div>
                        <div class="stat-sub">Courses Completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= htmlspecialchars((string)($stats['completed_units'] ?? 0)) ?>/<?= htmlspecialchars((string)($stats['total_units'] ?? 0)) ?></div>
                        <div class="stat-sub">Units Completed</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['remaining_courses']) ?></div>
                        <div class="stat-sub">Courses Remaining</div>
                    </div>
                    <?php if ($estimated_graduation): ?>
                    <div class="stat-card">
                        <div class="stat-value compact"><?= htmlspecialchars($estimated_graduation) ?></div>
                        <?php if ($graduation_school_year !== ''): ?><div class="stat-note"><?= htmlspecialchars($graduation_school_year) ?></div><?php endif; ?>
                        <div class="stat-sub">Projected Completion</div>
                    </div>
                    <?php endif; ?>
                    <div class="stat-card">
                        <div class="stat-value"><?= (int)$remaining_semesters ?></div>
                        <div class="stat-sub">Semesters to Go</div>
                    </div>
                </div>

                <?php
                $retention_status = $stats['retention_status'] ?? 'None';
                $retention_colors = [
                    'None' => ['bg' => '#e8f5e9', 'border' => '#4CAF50', 'text' => '#2e7d32', 'label' => 'Good Standing'],
                    'Warning' => ['bg' => '#fff3e0', 'border' => '#FF9800', 'text' => '#e65100', 'label' => 'Warning Status'],
                    'Probation' => ['bg' => '#fff3e0', 'border' => '#fd7e14', 'text' => '#bf360c', 'label' => 'Probationary Status'],
                    'Disqualification' => ['bg' => '#ffebee', 'border' => '#f44336', 'text' => '#c62828', 'label' => 'Disqualification Status']
                ];
                $ret_style = $retention_colors[$retention_status] ?? $retention_colors['None'];
                ?>
                <div style="margin-top: 15px; padding: 15px; background: <?= $ret_style['bg'] ?>; border-left: 4px solid <?= $ret_style['border'] ?>; border-radius: 4px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <span style="font-size: 18px;"><?php if ($retention_status === 'None'): ?>&#9989;<?php elseif ($retention_status === 'Warning'): ?>&#9888;&#65039;<?php elseif ($retention_status === 'Probation'): ?>&#128680;<?php else: ?>&#10060;<?php endif; ?></span>
                        <strong style="font-size: 15px; color: <?= $ret_style['text'] ?>;">Retention Policy: <?= $ret_style['label'] ?></strong>
                    </div>
                    <p style="margin: 0; font-size: 13px; color: #333;">
                        <?php if ($retention_status === 'Warning'): ?>
                            The student has failed 30-50% of enrolled subjects in the latest semester.
                        <?php elseif ($retention_status === 'Probation'): ?>
                            The student is under probationary status with a reduced load limit.
                        <?php elseif ($retention_status === 'Disqualification'): ?>
                            The student is currently under disqualification status.
                        <?php else: ?>
                            No retention issues detected. The student is in good academic standing.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($remaining_semesters > 0): ?>
                <div style="margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border-left: 4px solid #4CAF50; border-radius: 4px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                        <span style="font-size: 18px;">&#128202;</span>
                        <strong style="font-size: 15px; color: #2e7d32;">Timeline Optimization Summary</strong>
                    </div>
                    <p style="margin: 0; font-size: 13px; color: #333;">
                        This plan completes all remaining courses in <strong><?= $remaining_semesters ?> semester<?= $remaining_semesters > 1 ? 's' : '' ?></strong>,
                        keeping the student on track based on the current curriculum and eligible course sequencing.
                    </p>
                    <?php if ($estimated_graduation): ?>
                    <p style="margin: 8px 0 0 0; font-size: 13px; color: #206018; font-weight: 600;">
                        Projected Completion: <?= htmlspecialchars($estimated_graduation) ?><?= $graduation_school_year !== '' ? ' (' . htmlspecialchars($graduation_school_year) . ')' : '' ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div style="margin-top: 15px; padding: 12px; background: rgba(32, 96, 24, 0.05); border-left: 4px solid #4CAF50; border-radius: 4px;">
                    <p style="margin: 0; font-size: 13px; color: #333;">
                        <strong>Algorithm:</strong> This plan uses <strong>CSP (Constraint Satisfaction Problem)</strong> to validate prerequisites and enforce retention policies, and
                        <strong>Greedy Algorithm</strong> to optimize course sequencing.
                    </p>
                </div>
            </div>

            <div class="study-plan-container">
                <div class="student-header">
                    <a class="btn-back" href="study_plan_list.php">Back to Study Plan List</a>
                    <div class="student-details">
                        <p><span class="label">Name</span> : <span class="value"><?= htmlspecialchars($full_name) ?></span></p>
                        <p><span class="label">Student No.</span> : <span class="value"><?= htmlspecialchars((string)$student['student_number']) ?></span></p>
                        <p><span class="label">Program</span> : <span class="value"><?= htmlspecialchars((string)$student['program']) ?></span></p>
                        <p><span class="label">Admission Date</span> : <span class="value"><?= htmlspecialchars((string)($student['date_of_admission'] ?? '')) ?></span></p>
                    </div>
                </div>

                <div class="ay-overview-wrap">
                    <button class="print-button" type="button" onclick="window.print()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 6 2 18 2 18 9"></polyline>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                            <rect x="6" y="14" width="12" height="8"></rect>
                        </svg>
                        Print Study Plan
                    </button>
                    <button class="ay-overview-btn" type="button" onclick="openAYModal()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="7" height="9"></rect>
                            <rect x="14" y="3" width="7" height="5"></rect>
                            <rect x="14" y="12" width="7" height="9"></rect>
                            <rect x="3" y="16" width="7" height="5"></rect>
                        </svg>
                        A.Y. Course Overview
                    </button>
                </div>

                <?php if (!empty($stats['thrice_failed_count'])): ?>
                    <div class="warning">
                        Study plan generation is limited because this student has <?= (int)$stats['thrice_failed_count'] ?> course(s) failed three or more times.
                    </div>
                <?php endif; ?>

                <?php if (empty($display_terms)): ?>
                    <div class="warning" style="background:#e8f5e9;border-color:#c8e6c9;color:#206018;">No study plan terms generated for this student yet.</div>
                <?php else: ?>
                    <?php
                        $futureDividerShown = false;
                        foreach ($display_terms as $term):
                            $is_completed_term = !empty($term['is_completed_term']);
                            if (!$is_completed_term && !$futureDividerShown && !empty($completed_terms)) {
                                $futureDividerShown = true;
                    ?>
                        <div class="completed-divider">
                            <span>&#9660; Remaining Semesters (AI-Optimized Plan) &#9660;</span>
                        </div>
                    <?php
                            }
                            $term_units = (int)($term['total_units'] ?? 0);
                    ?>
                        <?php if (!empty($term['skipped'])): ?>
                            <div class="semester-section" style="border: 2px dashed #f44336; opacity: 0.78;">
                                <div style="background: linear-gradient(135deg, #ffebee, #ffcdd2); padding: 25px; text-align: center; border-radius: 8px;">
                                    <div class="semester-header" style="color: #c62828;">
                                        <?= htmlspecialchars($term['year']) ?> - <?= htmlspecialchars($term['semester']) ?>
                                    </div>
                                    <div style="font-size: 36px; margin: 10px 0;">&#128683;</div>
                                    <p style="font-size: 14px; color: #c62828; font-weight: 600; margin: 0;">
                                        <?= htmlspecialchars($term['skip_reason'] ?? 'Semester skipped due to retention policy') ?>
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="semester-section <?= $is_completed_term ? 'completed-term' : '' ?>" style="<?= $is_completed_term ? 'border: 1px solid #c8e6c9;' : '' ?>">
                                <?php if ($is_completed_term): ?>
                                    <div style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 8px; text-align: center; font-weight: 700; font-size: 13px; color: #2e7d32;">
                                        <?= htmlspecialchars($term['year']) ?> - <?= htmlspecialchars($term['semester']) ?>
                                        <span class="completed-badge">COMPLETED</span>
                                    </div>
                                <?php else: ?>
                                    <div class="semester-header">
                                        <?= htmlspecialchars($term['year']) ?> - <?= htmlspecialchars($term['semester']) ?>
                                        <?php if (!empty($term['max_units'])): ?>
                                            <span style="font-size: 11px; background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 4px; margin-left: 8px; font-weight: 600;">
                                                Max <?= (int)$term['max_units'] ?> units
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <table class="course-table">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Title</th>
                                            <th>Units</th>
                                            <th>Prerequisite</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach (($term['courses'] ?? []) as $course): ?>
                                        <?php
                                            $prerequisite = trim((string)($course['prerequisite'] ?? ''));
                                            if ($prerequisite === '') {
                                                $prerequisite = 'None';
                                            }
                                            $courseCode = strtoupper(trim((string)($course['code'] ?? '')));
                                            $courseTitle = strtoupper(trim((string)($course['title'] ?? '')));
                                            $isNonCredit = $courseCode === 'CVSU 101'
                                                || strpos($courseTitle, 'NON-CREDIT') !== false
                                                || strpos($courseTitle, 'NON CREDIT') !== false;
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($course['code'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string)($course['title'] ?? '')) ?></td>
                                            <td><?= $isNonCredit ? '(' . number_format((float)($course['units'] ?? 0), 1) . ')' : number_format((float)($course['units'] ?? 0), 1) ?></td>
                                            <td><?= htmlspecialchars($prerequisite) ?></td>
                                            <td>
                                                <?php if ($is_completed_term): ?><span class="plan-tag plan-tag-completed">Completed</span><?php endif; ?>
                                                <?php if (!empty($course['needs_retake'])): ?><span class="plan-tag plan-tag-retake">Retake</span><?php endif; ?>
                                                <?php if (!empty($course['cross_registered'])): ?><span class="plan-tag plan-tag-cross">Cross-Reg</span><?php endif; ?>
                                                <?php if (!empty($course['needs_retake']) || !empty($course['cross_registered'])): ?><span class="plan-tag plan-tag-to-add">To be added</span><?php endif; ?>
                                                <?php if (!empty($course['failed'])): ?><span class="plan-tag plan-tag-failed">Failed</span><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                        <tr class="total-row">
                                            <td colspan="2" style="text-align: right;"><strong>TOTAL</strong></td>
                                            <td><strong><?= $term_units ?></strong></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="ay-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ay-modal-title">
        <div id="ay-modal">
            <div class="modal-header">
                <h2 id="ay-modal-title">Academic Year Course Overview</h2>
                <button class="modal-close" onclick="closeAYModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <?php
                $year_num_map = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
                $sem_label_map = [
                    '1st Sem' => 'First Semester',
                    '2nd Sem' => 'Second Semester',
                    'Mid Year' => 'Mid Year',
                ];

                foreach ($ay_courses_by_term as $term_key => $term_data):
                    $yr_num = $year_num_map[$term_data['year']] ?? 1;
                    $sy_start = $admission_year + ($yr_num - 1);
                    $sy_end = $sy_start + 1;
                    $ay_label = 'A.Y. ' . $sy_start . '-' . $sy_end;
                    $sem_full = $sem_label_map[$term_data['semester']] ?? $term_data['semester'];
                    $completed_list = $term_data['completed'] ?? [];
                    $uncomplete_list = $term_data['uncomplete'] ?? [];
                    $block_id = 'ay-block-' . preg_replace('/[^a-z0-9]/i', '-', (string)$term_key);
                ?>
                <div class="ay-term-block">
                    <div class="ay-term-header" onclick="toggleAYBlock('<?= htmlspecialchars($block_id); ?>', this)">
                        <span><?= htmlspecialchars($term_data['year'] . ' - ' . $term_data['semester']); ?> | <?= htmlspecialchars($ay_label); ?> (<?= htmlspecialchars($sem_full); ?>)</span>
                        <span class="ay-term-toggle">&#9662;</span>
                    </div>
                    <div class="ay-term-body" id="<?= htmlspecialchars($block_id); ?>">
                        <div class="ay-col completed">
                            <h4>Completed Courses (<?= count($completed_list); ?>)</h4>
                            <?php if (empty($completed_list)): ?>
                                <div class="ay-empty">No completed courses in this term.</div>
                            <?php else: ?>
                                <?php foreach ($completed_list as $c): ?>
                                    <div class="ay-course-row">
                                        <div class="ay-course-code"><?= htmlspecialchars((string)($c['code'] ?? '')); ?></div>
                                        <div class="ay-course-title"><?= htmlspecialchars((string)($c['title'] ?? '')); ?></div>
                                        <span class="ay-course-badge ay-badge-passed"><?= htmlspecialchars((string)($c['grade'] ?? 'Passed')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="ay-col uncomplete">
                            <h4>Uncompleted Courses (<?= count($uncomplete_list); ?>)</h4>
                            <?php if (empty($uncomplete_list)): ?>
                                <div class="ay-empty">All courses completed for this term.</div>
                            <?php else: ?>
                                <?php foreach ($uncomplete_list as $c): ?>
                                    <?php
                                        $reason = strtoupper((string)($c['reason'] ?? 'Not Yet Taken'));
                                        $badge_class = 'ay-badge-pending';
                                        if ($reason === 'FAILED') {
                                            $badge_class = 'ay-badge-failed';
                                        } elseif ($reason === 'INC') {
                                            $badge_class = 'ay-badge-inc';
                                        } elseif ($reason === 'DROPPED') {
                                            $badge_class = 'ay-badge-dropped';
                                        }
                                    ?>
                                    <div class="ay-course-row">
                                        <div class="ay-course-code"><?= htmlspecialchars((string)($c['code'] ?? '')); ?></div>
                                        <div class="ay-course-title"><?= htmlspecialchars((string)($c['title'] ?? '')); ?></div>
                                        <span class="ay-course-badge <?= htmlspecialchars($badge_class); ?>"><?= htmlspecialchars((string)($c['reason'] ?? 'Not Yet Taken')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function openAYModal() {
            const overlay = document.getElementById('ay-modal-overlay');
            if (!overlay) {
                return;
            }
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeAYModal() {
            const overlay = document.getElementById('ay-modal-overlay');
            if (!overlay) {
                return;
            }
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }

        const ayOverlay = document.getElementById('ay-modal-overlay');
        if (ayOverlay) {
            ayOverlay.addEventListener('click', function(e) {
                if (e.target === ayOverlay) {
                    closeAYModal();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAYModal();
            }
        });

        function toggleAYBlock(blockId, headerEl) {
            const body = document.getElementById(blockId);
            if (!body) {
                return;
            }
            const isHidden = body.classList.toggle('hidden');
            headerEl.classList.toggle('collapsed', isHidden);
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }
    </script>
</body>
</html>
