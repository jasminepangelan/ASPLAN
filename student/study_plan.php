<?php
// Prevent caching to always show fresh data
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("ETag: " . md5(microtime() . rand()));

// Force browser to never cache this page
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    header('HTTP/1.1 200 OK');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_study_plan.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/study_plan_override_service.php';
require_once __DIR__ . '/../includes/vite_legacy.php';

// Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: ../index.php");
    exit();
}

// Get database connection
$conn = getDBConnection();

// Fetch current student data from database using session student_id
$student_id = $_SESSION['student_id'];
$last_name = htmlspecialchars($_SESSION['last_name'] ?? '');
$first_name = htmlspecialchars($_SESSION['first_name'] ?? '');
$middle_name = htmlspecialchars($_SESSION['middle_name'] ?? '');
$picture = resolveScopedPictureSrc($_SESSION['picture'] ?? '', '../', 'pix/anonymous.jpg');
$admission_year = null;
$program = $_SESSION['program'] ?? '';
$curriculum_year = $_SESSION['curriculum_year'] ?? '';
$bridgeLoaded = false;
$academicHold = ahsGetStudentAcademicHold($conn, (string)$student_id);
$academicHoldCoursesText = ahsFormatHoldCourseList($academicHold['courses'] ?? []);

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        '/api/study-plan/student/bootstrap',
        [
            'bridge_authorized' => true,
            'student_id' => $student_id,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
        $studentRow = $bridgeData['student'];
        $last_name = htmlspecialchars((string) ($studentRow['last_name'] ?? ''));
        $first_name = htmlspecialchars((string) ($studentRow['first_name'] ?? ''));
        $middle_name = htmlspecialchars((string) ($studentRow['middle_name'] ?? ''));
        $picture = resolveScopedPictureSrc($studentRow['picture'] ?? '', '../', 'pix/anonymous.jpg');
        $program = (string) ($studentRow['program'] ?? $program);
        $curriculum_year = (string) ($studentRow['curriculum_year'] ?? $curriculum_year);
        $admission_year = isset($studentRow['admission_year']) ? (int) $studentRow['admission_year'] : null;
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $query = $conn->prepare("SELECT last_name, first_name, middle_name, picture, program, curriculum_year, date_of_admission AS admission_date FROM student_info WHERE student_number = ?");
    $query->bind_param("s", $student_id);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_name = htmlspecialchars($row['last_name'] ?? '');
        $first_name = htmlspecialchars($row['first_name'] ?? '');
        $middle_name = htmlspecialchars($row['middle_name'] ?? '');
        $picture = resolveScopedPictureSrc($row['picture'] ?? '', '../', 'pix/anonymous.jpg');
        $program = (string)($row['program'] ?? $program);
        $curriculum_year = (string)($row['curriculum_year'] ?? $curriculum_year);

        if (!empty($row['admission_date'])) {
            $admission_year = (int)date('Y', strtotime($row['admission_date']));
        }
    }

    if ($admission_year === null && strlen($student_id) >= 4) {
        $potential_year = (int)substr($student_id, 0, 4);
        if ($potential_year >= 2000 && $potential_year <= (int)date('Y')) {
            $admission_year = $potential_year;
        }
    }

    if ($admission_year === null) {
        $admission_year = (int)date('Y') - 4;
    }

}
if (empty($program)) {
    $program = 'Bachelor of Science in Computer Science';
}

// Keep session program aligned with latest student program from DB/bridge.
$_SESSION['program'] = $program;
$_SESSION['curriculum_year'] = $curriculum_year;

// ====================================
// CSP + GREEDY ALGORITHM IMPLEMENTATION
// ====================================

// Initialize the Study Plan Generator with the student's program
$generator = new StudyPlanGenerator($student_id, $program);

// Generate optimized study plan using CSP and Greedy algorithms
$optimized_plan = $generator->generateOptimizedPlan();

// Get completion statistics
$stats = $generator->getCompletionStats();
$policy_gate = $generator->getPolicyGateStatus();

// Load coordinator overrides so student view reflects customized plan placements.
$valid_override_years = spoValidOverrideYears();
$valid_override_semesters = spoValidOverrideSemesters();
$study_plan_overrides = spoLoadStudyPlanOverrides($conn, (string) $student_id);

// Get completed (past) terms for display
$completed_terms = $generator->getCompletedTerms();

// Get all courses grouped by curriculum term for the AY popup
$ay_courses_by_term = $generator->getAllCoursesGroupedByTerm();

// Get courses failed 3+ times (triggers study plan generation stop)
$thrice_failed = $generator->getThriceFailedCourses();

if (!empty($academicHold['active'])) {
    $thrice_failed = [];
    foreach (($academicHold['courses'] ?? []) as $heldCourse) {
        $code = (string)($heldCourse['course_code'] ?? '');
        $count = (int)($heldCourse['failure_count'] ?? 0);
        if ($code !== '' && $count > 0) {
            $thrice_failed[$code] = $count;
        }
    }
    $optimized_plan = [];
}

// Calculate estimated completion timeline
$last_planned_term = null;
$remaining_semesters = 0;
foreach ($optimized_plan as $term) {
    if (empty($term['skipped']) && !empty($term['courses'])) {
        $last_planned_term = $term;
        $remaining_semesters++;
    }
}

$estimated_graduation = null;
$graduation_school_year = '';
$is_extended = false;
if ($last_planned_term) {
    $grad_year_num = intval(preg_replace('/[^0-9]/', '', $last_planned_term['year']));
    $grad_sy_start = $admission_year + ($grad_year_num > 0 ? $grad_year_num - 1 : 0);
    $grad_sy_end = $grad_sy_start + 1;
    $estimated_graduation = $last_planned_term['semester'] . ', ' . $last_planned_term['year'];
    $graduation_school_year = "A.Y. $grad_sy_start-$grad_sy_end";
    $is_extended = $grad_year_num > 4;
} elseif ($stats['remaining_courses'] == 0) {
    $estimated_graduation = 'Completed';
    $is_extended = false;
}

// Add unique identifier to prevent any caching
$page_generated_id = uniqid('studyplan_', true);
$page_generated_time = microtime(true);

// Page generation info for cache detection
// Generated ID: $page_generated_id at $page_generated_time

// Convert optimized plan to display format â€” preserving new metadata
$study_plan = [];
$study_plan_meta = []; // Store per-term metadata (retention, retake, cross-reg, skip info)
$study_plan_term_base_units = [];
$study_plan_term_override_deltas = [];
foreach ($optimized_plan as $term_index => $term) {
    $year = $term['year'];
    $semester = $term['semester'];
    
    // Store metadata for this term
    $meta_key = $year . '|' . $semester;
    $base_term_units = 0.0;
    foreach (($term['courses'] ?? []) as $base_course) {
        $base_course_code = strtoupper(trim((string)($base_course['code'] ?? '')));
        $base_course_title = strtoupper(trim((string)($base_course['title'] ?? '')));
        $base_is_non_credit = $base_course_code === 'CVSU 101'
            || strpos($base_course_title, 'NON-CREDIT') !== false
            || strpos($base_course_title, 'NON CREDIT') !== false;
        if ($base_is_non_credit) {
            continue;
        }

        $base_term_units += (float)($base_course['units'] ?? 0);
    }
    $study_plan_meta[$meta_key] = [
        'max_units' => $term['max_units'] ?? 21,
        'retention_status' => $term['retention_status'] ?? 'None',
        'retake_count' => $term['retake_count'] ?? 0,
        'cross_reg_count' => $term['cross_reg_count'] ?? 0,
        'forced_add_count' => $term['forced_add_count'] ?? 0,
        'skipped' => $term['skipped'] ?? false,
        'skip_reason' => $term['skip_reason'] ?? ''
    ];
    $study_plan_term_base_units[$meta_key] = $base_term_units;
    $study_plan_term_override_deltas[$meta_key] = 0.0;
    
    if (!isset($study_plan[$year])) {
        $study_plan[$year] = [];
    }
    if (!isset($study_plan[$year][$semester])) {
        $study_plan[$year][$semester] = [];
    }

    foreach ($term['courses'] as $course) {
        $target_year = $year;
        $target_semester = $semester;
        $course_code = (string)($course['code'] ?? '');
        $base_meta_key = $year . '|' . $semester;
        $is_non_credit_course = strtoupper(trim((string)($course['code'] ?? ''))) === 'CVSU 101'
            || stripos((string)($course['title'] ?? ''), 'non-credit') !== false
            || stripos((string)($course['title'] ?? ''), 'non credit') !== false;
        $course_counted_units = $is_non_credit_course ? 0.0 : (float)($course['units'] ?? 0);

        if ($course_code !== '' && isset($study_plan_overrides[$course_code])) {
            $candidate_year = $study_plan_overrides[$course_code]['year'];
            $candidate_semester = $study_plan_overrides[$course_code]['semester'];
            $base_term_order = 0;
            $candidate_term_order = 0;

            if (preg_match('/(\d+)/', (string)$year, $baseYearMatch)) {
                $base_term_order += ((int)($baseYearMatch[1] ?? 0)) * 10;
            }
            if (preg_match('/(\d+)/', (string)$candidate_year, $candidateYearMatch)) {
                $candidate_term_order += ((int)($candidateYearMatch[1] ?? 0)) * 10;
            }

            $semester_order_map = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
            $base_term_order += $semester_order_map[$semester] ?? 0;
            $candidate_term_order += $semester_order_map[$candidate_semester] ?? 0;

            // Ignore stale or invalid overrides that move a course earlier than
            // the generator's own base placement. Those can survive in the
            // database and recreate impossible mixed early-term loads.
            if ($candidate_term_order >= $base_term_order && $candidate_term_order > 0) {
                $candidate_meta_key = $candidate_year . '|' . $candidate_semester;
                $target_max_units = (float)($study_plan_meta[$candidate_meta_key]['max_units'] ?? 21);
                $candidate_target_total = (float)($study_plan_term_base_units[$candidate_meta_key] ?? 0.0)
                    + (float)($study_plan_term_override_deltas[$candidate_meta_key] ?? 0.0);

                if (
                    $candidate_meta_key === $base_meta_key
                    || ($candidate_target_total + $course_counted_units) <= $target_max_units
                ) {
                    $target_year = $candidate_year;
                    $target_semester = $candidate_semester;

                    if ($candidate_meta_key !== $base_meta_key) {
                        $study_plan_term_override_deltas[$base_meta_key] = (float)($study_plan_term_override_deltas[$base_meta_key] ?? 0.0) - $course_counted_units;
                        $study_plan_term_override_deltas[$candidate_meta_key] = (float)($study_plan_term_override_deltas[$candidate_meta_key] ?? 0.0) + $course_counted_units;
                    }
                }
            }
        }

        if (!isset($study_plan[$target_year])) {
            $study_plan[$target_year] = [];
        }
        if (!isset($study_plan[$target_year][$target_semester])) {
            $study_plan[$target_year][$target_semester] = [];
        }

        $study_plan[$target_year][$target_semester][] = [
            'course_code' => $course['code'],
            'course_title' => $course['title'],
            'credit_unit_lec' => $course['units'],
            'credit_unit_lab' => 0,
            'total_units' => $course['units'],
            'non_credit' => $is_non_credit_course,
            'prerequisite' => $course['prerequisite'] ?? 'None',
            'needs_retake' => !empty($course['needs_retake']),
            'cross_registered' => !empty($course['cross_registered']),
            'cross_reg_source_program' => $course['cross_reg_source_program'] ?? '',
            'forced_added' => !empty($course['forced_added']),
            'forced_reason' => $course['forced_reason'] ?? '',
            'source_year' => $course['year'] ?? $year,
            'source_semester' => $course['semester'] ?? $semester,
            'planned_year' => $target_year,
            'planned_semester' => $target_semester,
        ];
    }
}

$query->close();
closeDBConnection($conn);

function isNonCreditStudyPlanCourse($course) {
    $courseCode = strtoupper(trim((string)($course['course_code'] ?? $course['code'] ?? '')));
    $courseTitle = strtoupper(trim((string)($course['course_title'] ?? $course['title'] ?? '')));
    return !empty($course['non_credit'])
        || $courseCode === 'CVSU 101'
        || strpos($courseTitle, 'NON-CREDIT') !== false
        || strpos($courseTitle, 'NON CREDIT') !== false;
}

// Helper function to calculate total units
function calculateTotalUnits($courses) {
    $total = 0;
    foreach ($courses as $course) {
        if (isNonCreditStudyPlanCourse($course)) {
            continue;
        }
        if (isset($course['total_units'])) {
            $total += (float)($course['total_units'] ?? 0);
            continue;
        }
        $total += (float)($course['credit_unit_lec'] ?? 0) + (float)($course['credit_unit_lab'] ?? 0);
    }
    return $total;
}

function studyPlanDisplayTermOrder(string $year, string $semester): array {
    $year_order = 999;
    if (preg_match('/(\d+)/', $year, $matches)) {
        $year_order = (int)($matches[1] ?? 999);
    }

    $semester_order_map = [
        '1st Sem' => 1,
        '2nd Sem' => 2,
        'Mid Year' => 3,
    ];

    return [
        $year_order,
        $semester_order_map[$semester] ?? 999,
    ];
}

function describeStudyPlanDisplayTerm(array $courses, string $displayYear, string $displaySemester): array {
    $displayKey = $displayYear . '|' . $displaySemester;
    $sourceTerms = [];

    foreach ($courses as $course) {
        $sourceYear = trim((string)($course['source_year'] ?? $course['year'] ?? $displayYear));
        $sourceSemester = trim((string)($course['source_semester'] ?? $course['semester'] ?? $displaySemester));

        if ($sourceYear === '' || $sourceSemester === '') {
            continue;
        }

        $sourceKey = $sourceYear . '|' . $sourceSemester;
        if (!isset($sourceTerms[$sourceKey])) {
            $sourceTerms[$sourceKey] = [
                'year' => $sourceYear,
                'semester' => $sourceSemester,
            ];
        }
    }

    uasort($sourceTerms, static function (array $left, array $right): int {
        [$leftYear, $leftSemester] = studyPlanDisplayTermOrder((string)($left['year'] ?? ''), (string)($left['semester'] ?? ''));
        [$rightYear, $rightSemester] = studyPlanDisplayTermOrder((string)($right['year'] ?? ''), (string)($right['semester'] ?? ''));

        if ($leftYear !== $rightYear) {
            return $leftYear <=> $rightYear;
        }

        if ($leftSemester !== $rightSemester) {
            return $leftSemester <=> $rightSemester;
        }

        return strcmp(
            (string)($left['year'] ?? '') . '|' . (string)($left['semester'] ?? ''),
            (string)($right['year'] ?? '') . '|' . (string)($right['semester'] ?? '')
        );
    });

    $sourceTerms = array_values($sourceTerms);
    $hasMatchingDisplayTerm = false;
    $nonDisplayTerms = [];

    foreach ($sourceTerms as $sourceTerm) {
        $sourceKey = (string)($sourceTerm['year'] ?? '') . '|' . (string)($sourceTerm['semester'] ?? '');
        if ($sourceKey === $displayKey) {
            $hasMatchingDisplayTerm = true;
            continue;
        }

        $nonDisplayTerms[] = $sourceTerm;
    }

    $formatTerms = static function (array $terms): string {
        return implode(', ', array_map(
            static fn(array $term): string => trim((string)($term['year'] ?? '')) . ' - ' . trim((string)($term['semester'] ?? '')),
            $terms
        ));
    };

    return [
        'is_mixed' => count($sourceTerms) > 1,
        'is_relocated' => !empty($nonDisplayTerms),
        'has_matching_display_term' => $hasMatchingDisplayTerm,
        'source_summary' => $formatTerms($sourceTerms),
        'non_display_summary' => $formatTerms($nonDisplayTerms),
    ];
}

function sortTaggedStudyPlanCoursesLast(array $courses): array {
    $indexed = [];
    foreach ($courses as $index => $course) {
        $hasDeferredTag = !empty($course['needs_retake'])
            || !empty($course['cross_registered'])
            || !empty($course['forced_added']);
        $indexed[] = [
            'index' => $index,
            'has_deferred_tag' => $hasDeferredTag,
            'course' => $course,
        ];
    }

    usort($indexed, function ($a, $b) {
        if ($a['has_deferred_tag'] === $b['has_deferred_tag']) {
            return $a['index'] <=> $b['index'];
        }

        return $a['has_deferred_tag'] <=> $b['has_deferred_tag'];
    });

    return array_column($indexed, 'course');
}

$studentShellPayload = htmlspecialchars(json_encode([
    'title' => 'Study Plan Workspace',
    'description' => 'Review your generated roadmap, keep an eye on completion progress, and stay inside the existing student planning workflow while we modernize the shell around it.',
    'accent' => 'violet',
    'pageKey' => 'study-plan',
    'stats' => [
        ['label' => 'Program', 'value' => (string)$program],
        ['label' => 'Completion', 'value' => (string)($stats['completion_rate'] ?? 0) . '%'],
        ['label' => 'Remaining', 'value' => (string)($stats['remaining_courses'] ?? 0)],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$studentStudyPlanWorkspacePayload = htmlspecialchars(json_encode([
    'title' => 'Study Plan Command Deck',
    'note' => 'Use quick actions to print, review the academic-year overview, or jump back to your progress summary while the current planner output stays server-generated.',
    'stats' => [
        ['label' => 'Completion', 'value' => (string)($stats['completion_percentage'] ?? 0) . '%'],
        ['label' => 'Completed', 'value' => (string)($stats['completed_courses'] ?? 0) . '/' . (string)($stats['total_courses'] ?? 0)],
        ['label' => 'Remaining', 'value' => (string)($stats['remaining_courses'] ?? 0)],
    ],
    'insights' => [
        ['title' => 'Program', 'value' => (string)$program],
        ['title' => 'Projected completion', 'value' => $estimated_graduation ? (string)$estimated_graduation : 'In progress'],
        ['title' => 'Semesters to go', 'value' => (string)$remaining_semesters],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Study Plan - Student</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <?= renderLegacyViteTags(['resources/js/student-shell.jsx', 'resources/js/student-study-plan-workspace.jsx']) ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #eef2f5;
            font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
            color: #333;
            overflow-x: hidden;
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

        /* Sidebar */
        .sidebar {
            width: 250px;
            height: calc(100vh - 38px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 38px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: hidden;
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

        /* Main content */
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: calc(100vh - 60px);
            transition: margin-left 0.3s ease;
        }

        .main-content.expanded {
            margin-left: 0;
        }

        .page-header {
            background: linear-gradient(135deg, rgba(32, 96, 24, 0.95), rgba(45, 143, 34, 0.95));
            color: #ffffff;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            display: none;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .page-header p {
            font-size: 14px;
            opacity: 0.9;
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

        .stat-card {
            background: #f5faf4;
            border: 1px solid #cfe1cd;
            border-radius: 10px;
            padding: 14px;
            text-align: center;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 700;
            color: #1f5d17;
            line-height: 1.15;
        }

        .stat-value.compact {
            font-size: 18px;
        }

        .stat-sub {
            font-size: 13px;
            margin-top: 4px;
            color: #456043;
            font-weight: 600;
        }

        .stat-note {
            font-size: 12px;
            margin-top: 2px;
            color: #587155;
        }

        /* Study Plan Container */
        .study-plan-container {
            background: #ffffff;
            border-radius: 8px;
            padding: 40px 50px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 800px;
            margin: 0 auto;
        }

        /* Student Info Header */
        .student-header {
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .student-details {
            font-size: 14px;
            line-height: 1.8;
        }

        .student-details p {
            margin: 0;
        }

        .student-details .label {
            font-weight: 700;
            color: #000;
            display: inline-block;
            min-width: 100px;
        }

        .student-details .value {
            color: #000;
            font-weight: 400;
        }

        /* Semester Section */
        .semester-section {
            margin-bottom: 30px;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .semester-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .semester-section.completed-term {
            opacity: 0.85;
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
        .plan-tag-forced { background: #ef6c00; }
        .plan-tag-to-add { background: #2e7d32; }
        .plan-tag-warning { background: #ef6c00; }
        .plan-tag-pending { background: #455a64; }
        .completed-badge.plan-tag {
            font-size: 10px;
            background: #4CAF50;
            padding: 2px 6px;
            margin-left: 6px;
        }

        .grade-passed {
            color: #2e7d32;
            font-weight: 600;
        }

        .grade-failed {
            color: #c62828;
            font-weight: 700;
        }

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

        /* Course Table */
        .course-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
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
        }

        .course-table th:first-child {
            text-align: center;
        }

        .course-table th:last-child {
            text-align: center;
            width: 80px;
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
        }

        .course-table td:last-child {
            text-align: center;
        }

        .course-table .course-code {
            font-weight: 400;
            color: #000;
        }

        /* Total Row */
        .total-row {
            background: transparent;
            font-weight: 700;
        }

        .total-row td {
            border: 1px solid #000;
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Print Button */
        .print-button {
            background: #206018;
            color: #ffffff;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 20px auto;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(32, 96, 24, 0.3);
        }

        .print-button:hover {
            background: #2d8f22;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.4);
        }

        /* Pagination Controls */
        .pagination-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin: 25px 0;
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 8px 14px;
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            color: #206018;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            min-width: 40px;
            justify-content: center;
        }

        .pagination-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: #fff;
            border-color: #206018;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
        }

        .pagination-btn:disabled {
            background: #f0f0f0;
            color: #ccc;
            border-color: #e0e0e0;
            cursor: not-allowed;
            opacity: 0.6;
            box-shadow: none;
        }

        .pagination-info {
            color: #666;
            font-size: 13px;
            font-weight: 500;
            padding: 0 15px;
        }

        .page-content {
            display: none;
        }

        .page-content.active {
            display: block;
        }

        /* Print styles */
        @media print {
            body {
                background: white;
            }

            .title-bar,
            .sidebar,
            .print-button,
            .page-header,
            .pagination-controls,
            .completed-divider {
                display: none !important;
            }

            .semester-section.completed-term {
                opacity: 1;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .study-plan-container {
                box-shadow: none;
                padding: 0;
            }

            .page-content {
                display: block !important;
            }

            .semester-section {
                page-break-inside: avoid;
                margin-bottom: 25px;
            }

            .course-table {
                font-size: 12px;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .menu-toggle {
                display: inline-flex;
            }

            .sidebar {
                z-index: 1001;
            }

            .sidebar.collapsed {
                transform: translateX(-250px);
            }

            .main-content {
                margin-left: 0;
            }

            .main-content.expanded {
                margin-left: 0;
            }

            .study-plan-container {
                padding: 20px 15px;
            }

            .page-header h1 {
                font-size: 22px;
            }

            .student-details {
                grid-template-columns: 120px 1fr;
                font-size: 14px;
            }

            .course-table th,
            .course-table td {
                padding: 10px 8px;
                font-size: 13px;
            }
        }

        /* ===== AY Course Overview Popup ===== */
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
        /* Term accordion */
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
            transition: max-height 0.3s ease;
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
            min-width: 70px;
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
        .ay-overview-btn {
            background: linear-gradient(135deg, #1565C0, #1976D2);
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(21,101,192,0.3);
            margin: 0 10px;
        }
        .ay-overview-btn:hover {
            background: linear-gradient(135deg, #0d47a1, #1565C0);
            transform: translateY(-1px);
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
    </style>
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
    <div class="sidebar" id="sidebar">
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
                <li><a href="study_plan.php" class="active"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan</a></li>
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
        <div data-student-study-plan-workspace="<?= $studentStudyPlanWorkspacePayload ?>"></div>
        <div class="page-header">
            <h1>ðŸ“š AI-Generated Study Plan</h1>
            <p>Personalized academic roadmap powered by CSP & Greedy Algorithm</p>
        </div>

        <?php if (!empty($academicHold['active'])): ?>
        <div style="margin: 0 0 16px; padding: 15px 18px; background: linear-gradient(135deg, #fff4f4, #ffe0e0); border-left: 5px solid #b71c1c; border-radius: 10px; color: #5f1d1d; box-shadow: 0 3px 14px rgba(183, 28, 28, 0.12);">
            <div style="font-size: 16px; font-weight: 700; color: #8e1b1b; margin-bottom: 6px;"><?= htmlspecialchars((string)$academicHold['title']) ?></div>
            <div style="font-size: 13px; line-height: 1.55;"><?= htmlspecialchars((string)$academicHold['message']) ?></div>
            <?php if ($academicHoldCoursesText !== ''): ?>
            <div style="margin-top: 8px; font-size: 12px; font-weight: 600;">Courses at the limit: <?= htmlspecialchars($academicHoldCoursesText) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Statistics Dashboard -->
        <div class="academic-overview">
            <h3 class="academic-overview__header">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5zm0 18c-4.41 0-8-3.59-8-8V9h16v3c0 4.41-3.59 8-8 8z"/>
                </svg>
                Academic Progress Overview
                <span class="overview-meta student">Student: <?= $student_id ?></span>
                <span class="overview-meta generated" title="Page generated at <?= date('Y-m-d H:i:s') ?>">
                    Generated: <?= date('H:i:s') ?>
                </span>
            </h3>
            <div class="academic-stats-grid">
                <div class="stat-card" data-stat="completion">
                    <div class="stat-value" id="completion-rate"><?= $stats['completion_percentage'] ?>%</div>
                    <div class="stat-sub">Completion Rate</div>
                </div>
                <div class="stat-card" data-stat="courses">
                    <div class="stat-value" id="courses-completed"><?= $stats['completed_courses'] ?>/<?= $stats['total_courses'] ?></div>
                    <div class="stat-sub">Courses Completed</div>
                </div>
                <div class="stat-card" data-stat="units">
                    <div class="stat-value" id="units-completed"><?= $stats['completed_units'] ?>/<?= $stats['total_units'] ?></div>
                    <div class="stat-sub">Units Completed</div>
                </div>
                <div class="stat-card" data-stat="remaining">
                    <div class="stat-value" id="courses-remaining"><?= $stats['remaining_courses'] ?></div>
                    <div class="stat-sub">Courses Remaining</div>
                </div>
                <?php if ($stats['back_subjects'] > 0): ?>
                <div class="stat-card" data-stat="back">
                    <div class="stat-value"><?= $stats['back_subjects'] ?></div>
                    <div class="stat-sub">Back Subjects</div>
                </div>
                <?php endif; ?>
                <?php if ($estimated_graduation): ?>
                <div class="stat-card" data-stat="graduation">
                    <div class="stat-value compact"><?= htmlspecialchars($estimated_graduation) ?></div>
                    <?php if (!empty($graduation_school_year)): ?>
                    <div class="stat-note"><?= $graduation_school_year ?></div>
                    <?php endif; ?>
                    <div class="stat-sub">Projected Completion</div>
                </div>
                <?php endif; ?>
                <?php if ($remaining_semesters > 0): ?>
                <div class="stat-card" data-stat="remaining-sems">
                    <div class="stat-value"><?= $remaining_semesters ?></div>
                    <div class="stat-sub">Semesters to Go</div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php 
            // Retention Status Display
            $retention_status = $stats['retention_status'] ?? 'None';
            $retention_colors = [
                'None' => ['bg' => '#e8f5e9', 'border' => '#4CAF50', 'text' => '#2e7d32', 'label' => 'Good Standing'],
                'Warning' => ['bg' => '#fff3e0', 'border' => '#FF9800', 'text' => '#e65100', 'label' => 'Warning Status'],
                'Probation' => ['bg' => '#fff3e0', 'border' => '#fd7e14', 'text' => '#bf360c', 'label' => 'Probationary Status'],
                'Disqualification' => ['bg' => '#ffebee', 'border' => '#f44336', 'text' => '#c62828', 'label' => 'Disqualification Status']
            ];
            $ret_style = $retention_colors[$retention_status] ?? $retention_colors['None'];
            ?>
            
            <!-- Retention Policy Status -->
            <div style="margin-top: 15px; padding: 15px; background: <?= $ret_style['bg'] ?>; border-left: 4px solid <?= $ret_style['border'] ?>; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">
                        <?php if ($retention_status === 'None'): ?>&#9989;<?php elseif ($retention_status === 'Warning'): ?>&#9888;&#65039;<?php elseif ($retention_status === 'Probation'): ?>&#128680;<?php else: ?>&#10060;<?php endif; ?>
                    </span>
                    <strong style="font-size: 15px; color: <?= $ret_style['text'] ?>;">Retention Policy: <?= $ret_style['label'] ?></strong>
                </div>
                <p style="margin: 0; font-size: 13px; color: #333;">
                    <?php if ($retention_status === 'Warning'): ?>
                        You have failed 30-50% of enrolled subjects in your latest semester. Two consecutive warnings will result in probationary status (15 units limit).
                    <?php elseif ($retention_status === 'Probation'): ?>
                        You have failed 51% or more of enrolled subjects. Academic load is limited to <strong>15 units only</strong>. Two consecutive probationary semesters will result in disqualification.
                    <?php elseif ($retention_status === 'Disqualification'): ?>
                        You have failed 75% or more of enrolled subjects. You are ineligible to enroll for one semester. Upon return, your load is limited to <strong>15 units</strong>.
                    <?php else: ?>
                        No retention issues detected. You are in good academic standing.
                    <?php endif; ?>
                </p>
                <?php if ($stats['back_subjects'] > 0): ?>
                <p style="margin: 5px 0 0 0; font-size: 13px; color: #555;">
                    <strong>Back Subjects:</strong> <?= $stats['failed_courses'] ?> failed, <?= $stats['inc_courses'] ?> INC, <?= $stats['dropped_courses'] ?> dropped &mdash; These are prioritized in your study plan.
                </p>
                <?php endif; ?>
            </div>

            <?php if (!empty($thrice_failed)): ?>
            <!-- Triple-Failure Alert Banner -->
            <div style="margin-top: 15px; padding: 18px 20px; background: linear-gradient(135deg, #ffebee, #ffcdd2); border-left: 5px solid #b71c1c; border-radius: 6px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <span style="font-size: 22px;">&#9940;</span>
                    <strong style="font-size: 15px; color: #b71c1c;">Critical: Course Failed 3 Times &mdash; Study Plan Stopped</strong>
                </div>
                <p style="margin: 0 0 10px 0; font-size: 13px; color: #333;">
                    The following course<?= count($thrice_failed) > 1 ? 's have' : ' has' ?> been failed <strong>3 or more times</strong>.
                    Per academic policy, the system has stopped generating your study plan.
                    Please consult your academic adviser or the registrar's office immediately.
                </p>
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: rgba(183,28,28,0.1);">
                            <th style="padding: 6px 10px; text-align: left; border: 1px solid #ef9a9a;">Course Code</th>
                            <th style="padding: 6px 10px; text-align: left; border: 1px solid #ef9a9a;">Course Title</th>
                            <th style="padding: 6px 10px; text-align: center; border: 1px solid #ef9a9a;">Times Failed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($thrice_failed as $tf): ?>
                        <tr>
                            <td style="padding: 5px 10px; border: 1px solid #ef9a9a; font-weight: 700; color: #b71c1c;"><?= htmlspecialchars($tf['code']) ?></td>
                            <td style="padding: 5px 10px; border: 1px solid #ef9a9a; color: #333;"><?= htmlspecialchars($tf['title']) ?></td>
                            <td style="padding: 5px 10px; border: 1px solid #ef9a9a; text-align: center; font-weight: 700; color: #b71c1c;"><?= (int)$tf['fail_count'] ?>&times;</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Timeline Optimization Summary -->
            <?php if ($remaining_semesters > 0): ?>
            <div style="margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border-left: 4px solid #4CAF50; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">&#128202;</span>
                    <strong style="font-size: 15px; color: #2e7d32;">Timeline Optimization Summary</strong>
                </div>
                <p style="margin: 0; font-size: 13px; color: #333;">
                    <?php
                    $total_program_semesters = count($completed_terms) + $remaining_semesters;
                    if ($total_program_semesters <= 8): ?>
                    Your optimized plan completes all remaining courses in <strong><?= $remaining_semesters ?> semester<?= $remaining_semesters > 1 ? 's' : '' ?></strong>,
                    keeping you <strong style="color: #2e7d32;">on track</strong> to finish within the standard 4-year program duration.
                    The algorithm advances eligible courses from later years and fills each semester to maximize progress.
                    <?php elseif ($total_program_semesters == 9): ?>
                    Your plan uses the mid-year term effectively, completing in <strong><?= $remaining_semesters ?> semester<?= $remaining_semesters > 1 ? 's' : '' ?></strong> &mdash;
                    keeping you <strong style="color: #2e7d32;">within the standard timeline</strong>.
                    <?php else: ?>
                    Your program requires <strong style="color: #e65100;"><?= $total_program_semesters ?> total semesters</strong> (standard: 8).
                    The algorithm minimizes this extension by advancing courses from later years, prioritizing critical-path subjects,
                    and utilizing cross-registration opportunities to compress your remaining <strong><?= $remaining_semesters ?> semester<?= $remaining_semesters > 1 ? 's' : '' ?></strong>.
                    <?php endif; ?>
                </p>
                <?php if ($estimated_graduation): ?>
                <p style="margin: 8px 0 0 0; font-size: 13px; color: #206018; font-weight: 600;">
                    Projected Completion: <?= htmlspecialchars($estimated_graduation) ?><?= !empty($graduation_school_year) ? ' (' . $graduation_school_year . ')' : '' ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Debug info (hidden, for verification) -->
            <div style="display: none;" id="debug-info">
                <input type="hidden" id="php-completion" value="<?= $stats['completion_percentage'] ?>">
                <input type="hidden" id="php-completed" value="<?= $stats['completed_courses'] ?>">
                <input type="hidden" id="php-total" value="<?= $stats['total_courses'] ?>">
                <input type="hidden" id="php-student" value="<?= $student_id ?>">
                <input type="hidden" id="php-timestamp" value="<?= time() ?>">
                <input type="hidden" id="php-generated-id" value="<?= $page_generated_id ?>">
                <input type="hidden" id="php-microtime" value="<?= $page_generated_time ?>">
            </div>
            
            <!-- VISIBLE CACHE DETECTION -->
            <div style="margin-top: 15px; padding: 12px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; font-size: 12px;">
                <strong>ðŸ” Data Freshness Check:</strong>
                <div style="margin-top: 5px;">
                    <span style="font-family: monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">
                        Generated: <?= date('Y-m-d H:i:s') ?> | ID: <?= substr($page_generated_id, -8) ?>
                    </span>
                </div>
                <div id="cache-warning" style="margin-top: 5px; color: #d32f2f; font-weight: bold; display: none;">
                    âš ï¸ CACHED DATA DETECTED! Press Ctrl+Shift+R to refresh!
                </div>
            </div>
            
            <!-- Algorithm Info -->
            <div style="margin-top: 15px; padding: 12px; background: rgba(32, 96, 24, 0.05); border-left: 4px solid #4CAF50; border-radius: 4px;">
                <p style="margin: 0; font-size: 13px; color: #333;">
                    <strong>Algorithm:</strong> This plan uses <strong>CSP (Constraint Satisfaction Problem)</strong> to validate prerequisites and enforce retention policies, and
                    <strong>Greedy Algorithm</strong> to optimize course sequencing &mdash; prioritizing back and failed subjects from lower years, 
                    critical path courses, and cross-registration opportunities.
                </p>
            </div>
        </div>

        <div class="study-plan-container">
            <!-- Student Information -->
            <div class="student-header">
                <div class="student-details">
                    <p><span class="label">Name</span> : <span class="value"><?= $last_name . ', ' . $first_name . (!empty($middle_name) ? ' ' . $middle_name[0] . '.' : '') ?></span></p>
                    <p><span class="label">Student No.</span> : <span class="value"><?= $student_id ?></span></p>
                </div>
            </div>

            <!-- Academic Schedule by Semester -->
            <?php 
            if (!empty($policy_gate['applies']) && empty($policy_gate['eligible'])):
            ?>
                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-radius: 12px; border: 2px solid #ef6c00;">
                    <div style="font-size: 48px; margin-bottom: 15px;">&#9888;</div>
                    <p style="font-size: 20px; font-weight: 700; color: #e65100; margin-bottom: 10px;">Study Plan Generation Paused</p>
                    <p style="font-size: 16px; color: #333;">Transferees and students with active shift requests must have no failing grades and an average grade of 2.00 or better before the study plan can continue.</p>
                    <?php if (!empty($policy_gate['reasons'])): ?>
                    <p style="font-size: 14px; color: #666; margin-top: 10px;"><?= htmlspecialchars(implode(' ', $policy_gate['reasons'])) ?></p>
                    <?php endif; ?>
                    <?php if (isset($policy_gate['average_grade']) && $policy_gate['average_grade'] !== null): ?>
                    <p style="font-size: 14px; color: #666; margin-top: 8px;">Current average grade: <strong><?= htmlspecialchars(number_format((float)$policy_gate['average_grade'], 2)) ?></strong></p>
                    <?php endif; ?>
                </div>
            <?php
            elseif (empty($study_plan) && empty($completed_terms)): 
                if (!empty($thrice_failed)):
            ?>
                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, #ffebee, #ffcdd2); border-radius: 12px; border: 2px solid #b71c1c;">
                    <div style="font-size: 48px; margin-bottom: 15px;">&#9940;</div>
                    <p style="font-size: 20px; font-weight: 700; color: #b71c1c; margin-bottom: 10px;">Study Plan Generation Stopped</p>
                    <p style="font-size: 16px; color: #333;">One or more courses have been failed <strong>3 or more times</strong>. You are no longer eligible to continue enrollment per academic policy.</p>
                    <p style="font-size: 14px; color: #666; margin-top: 10px;">Please consult your academic adviser or the registrar's office for guidance.</p>
                </div>
            <?php elseif ($stats['retention_status'] === 'Disqualification' && $stats['remaining_courses'] > 0): ?>
            ?>
                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, #ffebee, #ffcdd2); border-radius: 12px; border: 2px solid #f44336;">
                    <div style="font-size: 48px; margin-bottom: 15px;">&#128683;</div>
                    <p style="font-size: 20px; font-weight: 700; color: #c62828; margin-bottom: 10px;">Study Plan Generation Stopped</p>
                    <p style="font-size: 16px; color: #333;">You have received two disqualification statuses. Per university retention policy, you are no longer eligible to continue enrollment.</p>
                    <p style="font-size: 14px; color: #666; margin-top: 10px;">Please consult your academic adviser or the registrar's office for guidance.</p>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-radius: 12px; border: 2px solid #4CAF50;">
                    <div style="font-size: 48px; margin-bottom: 15px;">&#127891;</div>
                    <p style="font-size: 20px; font-weight: 700; color: #206018; margin-bottom: 10px;">Congratulations!</p>
                    <p style="font-size: 16px; color: #333;">You have completed all required courses in your program.</p>
                    <p style="font-size: 14px; color: #666; margin-top: 10px;">No remaining courses to display.</p>
                </div>
            <?php endif; ?>
            <?php 
            else:
                if (!empty($policy_gate['applies']) && empty($policy_gate['eligible'])):
            ?>
                <div style="margin-bottom: 20px; padding: 16px 20px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-left: 5px solid #ef6c00; border-radius: 10px; color: #5d4037;">
                    <strong>Study plan is paused by transferee/shift policy.</strong>
                    <?php if (!empty($policy_gate['reasons'])): ?>
                    <?= htmlspecialchars(implode(' ', $policy_gate['reasons'])) ?>
                    <?php endif; ?>
                    <?php if (isset($policy_gate['average_grade']) && $policy_gate['average_grade'] !== null): ?>
                    Current average grade: <strong><?= htmlspecialchars(number_format((float)$policy_gate['average_grade'], 2)) ?></strong>.
                    <?php endif; ?>
                </div>
            <?php
                endif;
                // Build completed semesters for display
                $completed_semesters = [];
                $completed_term_keys = [];
                foreach ($completed_terms as $ct) {
                    $completed_term_keys[$ct['year'] . '|' . $ct['semester']] = true;
                    $completed_semesters[] = [
                        'year' => $ct['year'],
                        'semester' => $ct['semester'],
                        'courses' => $ct['courses'],
                        'meta' => ['completed' => true, 'retention_status' => $ct['retention_status'] ?? 'None'],
                        'is_completed_term' => true
                    ];
                }
                
                // Flatten future semesters
                $future_semesters = [];
                foreach ($study_plan as $year => $semesters) {
                    foreach ($semesters as $semester => $courses) {
                        $meta_key = $year . '|' . $semester;
                        $future_semesters[] = [
                            'year' => $year,
                            'semester' => $semester,
                            'courses' => $courses,
                            'meta' => $study_plan_meta[$meta_key] ?? [],
                            'is_completed_term' => false
                        ];
                    }
                }

                $future_term_keys = [];
                foreach ($future_semesters as $future_term) {
                    $future_term_keys[$future_term['year'] . '|' . $future_term['semester']] = true;
                }

                $partial_semesters = [];
                foreach ($ay_courses_by_term as $term_key => $term_data) {
                    if (
                        isset($completed_term_keys[$term_key]) ||
                        isset($future_term_keys[$term_key]) ||
                        empty($term_data['completed']) ||
                        empty($term_data['uncomplete'])
                    ) {
                        continue;
                    }

                    $is_credit_migration_term = !empty($policy_gate['applies'])
                        && ($term_data['year'] ?? '') === '1st Yr'
                        && ($term_data['semester'] ?? '') === '1st Sem';

                    $courses = [];
                    foreach ($term_data['completed'] as $course) {
                        $courses[] = [
                            'code' => $course['code'],
                            'title' => $course['title'],
                            'units' => $course['units'],
                            'prerequisite' => $course['prerequisite'] ?? 'None',
                            'status' => $is_credit_migration_term ? 'Credited' : 'Passed',
                            'status_variant' => $is_credit_migration_term ? 'credited' : 'passed',
                            'grade' => $course['grade'] ?? '',
                        ];
                    }
                    foreach ($term_data['uncomplete'] as $course) {
                        $reason = trim((string)($course['reason'] ?? 'Not Yet Taken'));
                        $courses[] = [
                            'code' => $course['code'],
                            'title' => $course['title'],
                            'units' => $course['units'],
                            'prerequisite' => $course['prerequisite'] ?? 'None',
                            'status' => $reason,
                            'status_variant' => strtolower(str_replace(' ', '-', $reason)),
                            'grade' => $course['grade'] ?? '',
                        ];
                    }

                    $partial_semesters[] = [
                        'year' => $term_data['year'],
                        'semester' => $term_data['semester'],
                        'courses' => $courses,
                        'meta' => [
                            'partial' => true,
                            'credit_migration_term' => $is_credit_migration_term,
                        ],
                        'is_completed_term' => false,
                        'is_partial_term' => true,
                    ];
                }

                $year_order = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4, '5th Yr' => 5, '6th Yr' => 6, '7th Yr' => 7];
                $sem_order = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
                $sort_terms = static function (array &$terms) use ($year_order, $sem_order): void {
                    usort($terms, static function ($a, $b) use ($year_order, $sem_order) {
                        $ya = $year_order[$a['year']] ?? 999;
                        $yb = $year_order[$b['year']] ?? 999;
                        if ($ya !== $yb) {
                            return $ya <=> $yb;
                        }

                        $sa = $sem_order[$a['semester']] ?? 999;
                        $sb = $sem_order[$b['semester']] ?? 999;
                        return $sa <=> $sb;
                    });
                };

                $sort_terms($partial_semesters);
                $sort_terms($future_semesters);
                
                // Combine: completed first, then partially completed historical terms, then future plan
                $all_semesters = array_merge($completed_semesters, $partial_semesters, $future_semesters);
                
                // Group into pages (2 semesters per page)
                $pages = array_chunk($all_semesters, 2);
                $total_pages = count($pages);
            ?>
                
                <!-- Pagination Controls (Top) -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-controls">
                    <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)" disabled>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                        Previous
                    </button>
                    <span class="pagination-info">
                        <span id="currentPage">1</span> of <?= $total_pages ?>
                    </span>
                    <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">
                        Next
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>

                <?php 
                $found_first_future = false;
                foreach ($pages as $page_index => $page_semesters): 
                ?>
                <div class="page-content <?= $page_index === 0 ? 'active' : '' ?>" data-page="<?= $page_index + 1 ?>">
                    <?php 
                    // Check if this page has a transition from completed to future
                    $page_has_transition = false;
                    foreach ($page_semesters as $si => $sd) {
                        if (empty($sd['is_completed_term']) && !$found_first_future) {
                            $page_has_transition = true;
                        }
                    }
                    
                    foreach ($page_semesters as $sem_index => $sem_data): 
                        $year = $sem_data['year'];
                        $semester = $sem_data['semester'];
                        $courses = sortTaggedStudyPlanCoursesLast($sem_data['courses'] ?? []);
                        $meta = $sem_data['meta'] ?? [];
                        $is_completed_term = !empty($sem_data['is_completed_term']);
                        $is_partial_term = !empty($sem_data['is_partial_term']);
                        $is_skipped = !empty($meta['skipped']);
                        $term_retention = $meta['retention_status'] ?? 'None';
                        $term_max_units = $meta['max_units'] ?? 21;
                        $term_retake_count = $meta['retake_count'] ?? 0;
                        $term_cross_reg = $meta['cross_reg_count'] ?? 0;
                        $term_forced_add = $meta['forced_add_count'] ?? 0;
                        $term_source_context = describeStudyPlanDisplayTerm($courses, (string)$year, (string)$semester);
                        
                        // Generate academic year string based on year level and admission year
                        $year_num = intval(preg_replace('/[^0-9]/', '', $year));
                        $sy_start = $admission_year + ($year_num > 0 ? $year_num - 1 : 0);
                        $sy_end = $sy_start + 1;
                        $school_year = "A.Y. $sy_start-$sy_end";
                        
                        // Show divider when transitioning from completed to future
                        if (!$is_completed_term && !$found_first_future) {
                            $found_first_future = true;
                    ?>
                    <div class="completed-divider">
                        <span>&#9660; Remaining Semesters (AI-Optimized Plan) &#9660;</span>
                    </div>
                    <?php } ?>
                        
                    <?php if ($is_completed_term): ?>
                    <!-- Completed Semester -->
                    <div class="semester-section completed-term" style="border: 1px solid #c8e6c9;">
                        <div style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 8px; text-align: center; font-weight: 700; font-size: 13px; color: #2e7d32;">
                            <?= htmlspecialchars($year) ?> - <?= htmlspecialchars($semester) ?>, <?= $school_year ?>
                            <span class="completed-badge plan-tag plan-tag-completed">COMPLETED</span>
                            <?php if ($term_retention !== 'None'): ?>
                            <span style="font-size: 10px; background: <?= $term_retention === 'Warning' ? '#fff3e0' : ($term_retention === 'Probation' ? '#fff3e0' : '#ffebee') ?>; color: <?= $term_retention === 'Warning' ? '#e65100' : ($term_retention === 'Probation' ? '#bf360c' : '#c62828') ?>; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 600;">
                                <?= htmlspecialchars($term_retention) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <table class="course-table">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Units</th>
                                    <th>Prerequisite</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $semester_total = 0;
                                foreach ($courses as $course): 
                                    $units = $course['units'] ?? 0;
                                    $is_non_credit = isNonCreditStudyPlanCourse($course);
                                    if (!$is_non_credit) {
                                        $semester_total += $units;
                                    }
                                    $prerequisite = $course['prerequisite'] ?? 'None';
                                    $is_failed_grade = !empty($course['failed']);
                                    $prereq_class = ($prerequisite === 'None') ? 'grade-passed' : 'grade-failed';
                                ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($course['code']) ?>
                                            <span class="plan-tag plan-tag-completed">COMPLETED</span>
                                            <?php if ($is_failed_grade): ?>
                                            <span class="plan-tag plan-tag-failed">FAILED</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($course['title']) ?></td>
                                        <td><?= number_format($units, 1) ?></td>
                                        <td class="<?= $prereq_class ?>" style="text-align: center;"><?= htmlspecialchars($prerequisite) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="2" style="text-align: right;"><strong>TOTAL</strong></td>
                                    <td><strong><?= number_format($semester_total, 1) ?></strong></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php elseif ($is_partial_term): ?>
                    <div class="semester-section" style="border: 2px solid #cfe4d2; background: linear-gradient(180deg, #fbfefb 0%, #f6fbf7 100%);">
                        <div style="background: linear-gradient(135deg, #edf7ee, #dbeadf); padding: 8px; text-align: center; font-weight: 700; font-size: 13px; color: #2f5d34;">
                            <?= htmlspecialchars($year) ?> - <?= htmlspecialchars($semester) ?>, <?= $school_year ?>
                            <span style="font-size: 10px; background: #fff8e1; color: #8d6e00; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 700;">IN PROGRESS</span>
                        </div>
                        <div style="padding: 8px 12px; font-size: 12px; color: #4e6452; border-bottom: 1px solid #e1ece3;">
                            This term already has completed courses, but it is not fully completed yet, so it stays visible here for reference.
                        </div>
                        <table class="course-table">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Title</th>
                                    <th>Units</th>
                                    <th>Prerequisite</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $semester_total = 0;
                                foreach ($courses as $course): 
                                    $units = $course['units'] ?? 0;
                                    $is_non_credit = isNonCreditStudyPlanCourse($course);
                                    if (!$is_non_credit) {
                                        $semester_total += $units;
                                    }
                                    $prerequisite = $course['prerequisite'] ?? 'None';
                                    $status = trim((string)($course['status'] ?? ''));
                                    $status_variant = trim((string)($course['status_variant'] ?? ''));
                                    $status_badge_class = 'plan-tag-completed';
                                    if ($status_variant === 'failed') {
                                        $status_badge_class = 'plan-tag-failed';
                                    } elseif ($status_variant === 'inc' || $status_variant === 'dropped') {
                                        $status_badge_class = 'plan-tag-warning';
                                    } elseif ($status_variant === 'not-yet-taken') {
                                        $status_badge_class = 'plan-tag-pending';
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($course['code']) ?>
                                            <?php if ($status !== ''): ?>
                                            <span class="plan-tag <?= htmlspecialchars($status_badge_class) ?>">
                                                <?= htmlspecialchars(strtoupper($status)) ?>
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($course['title']) ?></td>
                                        <td><?= $is_non_credit ? '(' . number_format($units, 1) . ')' : number_format($units, 1) ?></td>
                                        <td style="text-align: center;"><?= htmlspecialchars($prerequisite) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="2" style="text-align: right;"><strong>TOTAL</strong></td>
                                    <td><strong><?= number_format($semester_total, 1) ?></strong></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <?php
                        // Highlight first future semester as "Next Recommended"
                        $is_first_future = (!$is_skipped && !isset($first_future_shown));
                        if ($is_first_future) $first_future_shown = true;
                    ?>
                    <div class="semester-section" style="<?= $is_first_future ? 'border: 3px solid #4CAF50; box-shadow: 0 4px 20px rgba(76, 175, 80, 0.3);' : ($is_skipped ? 'border: 2px dashed #f44336; opacity: 0.7;' : '') ?>">
                        <?php if ($is_first_future): ?>
                        <div style="background: linear-gradient(135deg, #4CAF50, #45a049); color: white; padding: 8px; text-align: center; font-weight: 700; font-size: 13px; border-radius: 8px 8px 0 0; margin: -3px -3px 0 -3px;">
                            NEXT RECOMMENDED LOAD (CSP & Greedy Optimized)
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($is_skipped): ?>
                        <!-- Skipped Semester (Disqualification) -->
                        <div style="background: linear-gradient(135deg, #ffebee, #ffcdd2); padding: 25px; text-align: center; border-radius: 8px;">
                            <div class="semester-header" style="color: #c62828;">
                                <?= htmlspecialchars($year) ?> - <?= htmlspecialchars($semester) ?>, <?= $school_year ?>
                            </div>
                            <div style="font-size: 36px; margin: 10px 0;">&#128683;</div>
                            <p style="font-size: 14px; color: #c62828; font-weight: 600; margin: 0;">
                                <?= htmlspecialchars($meta['skip_reason'] ?? 'Semester skipped due to disqualification') ?>
                            </p>
                            <p style="font-size: 12px; color: #666; margin-top: 8px;">
                                No enrollment this semester per retention policy.
                            </p>
                        </div>
                        <?php else: ?>
                        <?php
                            $term_heading = $year . ' - ' . $semester . ', ' . $school_year;
                            if (!empty($term_source_context['is_relocated'])) {
                                $term_heading = 'Recommended Load for ' . $term_heading;
                            }

                            $source_note = '';
                            if (!empty($term_source_context['is_relocated'])) {
                                $source_list = !empty($term_source_context['has_matching_display_term'])
                                    ? (string)($term_source_context['non_display_summary'] ?? '')
                                    : (string)($term_source_context['source_summary'] ?? '');

                                if ($source_list !== '') {
                                    $source_note = !empty($term_source_context['has_matching_display_term'])
                                        ? 'Also includes courses originally scheduled in: ' . $source_list
                                        : 'Courses originally scheduled in: ' . $source_list;
                                }
                            }
                        ?>
                        <div class="semester-header">
                            <?= htmlspecialchars($term_heading) ?>
                            <?php if ($term_max_units < 21): ?>
                            <span style="font-size: 11px; background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 4px; margin-left: 8px; font-weight: 600;">
                                Max <?= $term_max_units ?> units (<?= $term_retention ?>)
                            </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($source_note !== ''): ?>
                        <div style="padding: 6px 12px 0; font-size: 11px; color: #546e7a; font-weight: 600;">
                            <?= htmlspecialchars($source_note) ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($term_retake_count > 0 || $term_cross_reg > 0 || $term_forced_add > 0): ?>
                        <div style="display: flex; gap: 8px; padding: 4px 12px; font-size: 11px; flex-wrap: wrap;">
                            <?php if ($term_retake_count > 0): ?>
                            <span style="background: #ffebee; color: #c62828; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                <?= $term_retake_count ?> Retake<?= $term_retake_count > 1 ? 's' : '' ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($term_cross_reg > 0): ?>
                            <span style="background: #e3f2fd; color: #1565C0; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                <?= $term_cross_reg ?> Cross-Reg
                            </span>
                            <?php endif; ?>
                            <?php if ($term_forced_add > 0): ?>
                            <span style="background: #fff3e0; color: #ef6c00; padding: 2px 8px; border-radius: 4px; font-weight: 600;">
                                <?= $term_forced_add ?> Forced Added
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $semester_total = 0;
                                foreach ($courses as $course): 
                                    $units = isset($course['total_units']) ? $course['total_units'] : (($course['credit_unit_lec'] ?? 0) + ($course['credit_unit_lab'] ?? 0));
                                    $is_non_credit = isNonCreditStudyPlanCourse($course);
                                    if (!$is_non_credit) {
                                        $semester_total += $units;
                                    }
                                    $is_retake = !empty($course['needs_retake']);
                                    $is_cross_reg = !empty($course['cross_registered']);
                                    $cross_reg_source_program = trim((string)($course['cross_reg_source_program'] ?? ''));
                                    $cross_reg_tooltip = $cross_reg_source_program !== '' ? 'Cross-registered from: ' . $cross_reg_source_program : 'Cross-registered course';
                                    $is_forced_added = !empty($course['forced_added']);
                                    $prerequisite = trim((string)($course['prerequisite'] ?? ''));
                                    if ($prerequisite === '' || strtoupper($prerequisite) === 'NONE') {
                                        $prerequisite = 'None';
                                    }
                                    $row_style = $is_forced_added
                                        ? 'background: #fff3e0;'
                                        : ($is_retake ? 'background: #fff8e1;' : ($is_cross_reg ? 'background: #e8f4fd;' : ''));
                                ?>
                                    <tr style="<?= $row_style ?>">
                                        <td>
                                            <?= htmlspecialchars($course['course_code']) ?>
                                            <?php if ($is_retake): ?>
                                            <span class="plan-tag plan-tag-retake">RETAKE</span>
                                            <span class="plan-tag plan-tag-to-add">TO BE ADDED</span>
                                            <?php endif; ?>
                                            <?php if ($is_cross_reg): ?>
                                            <span class="plan-tag plan-tag-cross" title="<?= htmlspecialchars($cross_reg_tooltip) ?>" aria-label="<?= htmlspecialchars($cross_reg_tooltip) ?>">CROSS-REG</span>
                                            <span class="plan-tag plan-tag-to-add">TO BE ADDED</span>
                                            <?php endif; ?>
                                            <?php if ($is_forced_added): ?>
                                            <span class="plan-tag plan-tag-forced">FORCED</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($course['course_title']) ?>
                                            <?php if ($is_forced_added && !empty($course['forced_reason'])): ?>
                                            <div style="font-size: 10px; color: #ef6c00; font-weight: 600; margin-top: 3px;">
                                                <?= htmlspecialchars($course['forced_reason']) ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $is_non_credit ? '(' . number_format($units, 1) . ')' : number_format($units, 1) ?></td>
                                        <td><?= htmlspecialchars($prerequisite) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row">
                                    <td colspan="2" style="text-align: right;"><strong>TOTAL</strong></td>
                                    <td><strong><?= number_format($semester_total, 1) ?></strong></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <!-- Pagination Controls (Bottom) -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-controls">
                    <button class="pagination-btn" id="prevBtnBottom" onclick="changePage(-1)" disabled>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="15 18 9 12 15 6"></polyline>
                        </svg>
                        Previous
                    </button>
                    <span class="pagination-info">
                        <span id="currentPageBottom">1</span> of <?= $total_pages ?>
                    </span>
                    <button class="pagination-btn" id="nextBtnBottom" onclick="changePage(1)">
                        Next
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>

            <?php endif; ?>

            <?php if (!empty($thrice_failed) && empty($study_plan) && !empty($completed_terms)): ?>
            <!-- Stop notice when student has completed terms but triple-failure halted the plan -->
            <div style="margin-top: 20px; text-align: center; padding: 30px; background: linear-gradient(135deg, #ffebee, #ffcdd2); border-radius: 12px; border: 2px solid #b71c1c;">
                <div style="font-size: 40px; margin-bottom: 12px;">&#9940;</div>
                <p style="font-size: 18px; font-weight: 700; color: #b71c1c; margin-bottom: 8px;">Study Plan Generation Stopped</p>
                <p style="font-size: 14px; color: #333;">One or more courses have been failed <strong>3 or more times</strong>. No further study plan can be generated. Please see the alert above for details.</p>
                <p style="font-size: 13px; color: #666; margin-top: 8px;">Please consult your academic adviser or the registrar's office for guidance.</p>
            </div>
            <?php endif; ?>

            <!-- Print Button + AY Overview Button -->
            <div style="display: flex; justify-content: center; align-items: center; flex-wrap: wrap; gap: 10px; margin: 20px 0;">
            <button class="print-button" onclick="window.print()" style="margin: 0;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Print AI-Generated Study Plan
            </button>
            <button class="ay-overview-btn" onclick="openAYModal()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="9"></rect>
                    <rect x="14" y="3" width="7" height="5"></rect>
                    <rect x="14" y="12" width="7" height="9"></rect>
                    <rect x="3" y="16" width="7" height="5"></rect>
                </svg>
                A.Y. Course Overview
            </button>
            </div>
            
            <!-- Algorithm Information Footer -->
            <div style="margin-top: 20px; padding: 20px; background: linear-gradient(135deg, #f5f5f5, #e0e0e0); border-radius: 8px; text-align: center;">
                <p style="margin: 0 0 10px 0; font-size: 14px; font-weight: 700; color: #206018;">
                    How This Plan Was Generated
                </p>
                <p style="margin: 0; font-size: 12px; color: #555; line-height: 1.6;">
                    This study plan uses <strong>Constraint Satisfaction Problem (CSP)</strong> to enforce prerequisites, retention policy limits, 
                    and semester constraints, combined with <strong>Greedy Algorithm</strong> to prioritize back and failed subjects from lower years,
                    critical path courses, and cross-registration opportunities. The system enforces no-overloading limits and adapts
                    unit caps based on academic standing.
                </p>
                
                <!-- Legend -->
                <div style="display: flex; justify-content: center; gap: 20px; margin-top: 12px; flex-wrap: wrap; font-size: 11px;">
                    <span style="display: flex; align-items: center; gap: 4px;">
                        <span style="background: #4CAF50; color: white; padding: 1px 6px; border-radius: 3px; font-size: 9px;">COMPLETED</span>
                        Finished Semester
                    </span>
                    <span style="display: flex; align-items: center; gap: 4px;">
                        <span style="background: #f44336; color: white; padding: 1px 6px; border-radius: 3px; font-size: 9px;">RETAKE</span>
                        Back and failed Subject
                    </span>
                    <span style="display: flex; align-items: center; gap: 4px;">
                        <span style="background: #2196F3; color: white; padding: 1px 6px; border-radius: 3px; font-size: 9px;">CROSS-REG</span>
                        Cross-Registration
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== A.Y. Course Overview Modal ===== -->
    <div id="ay-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ay-modal-title">
        <div id="ay-modal">
            <div class="modal-header">
                <h2 id="ay-modal-title">&#128218; Academic Year Course Overview</h2>
                <button class="modal-close" onclick="closeAYModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <?php
                // Helper: convert internal year label to school-year string
                $year_num_map = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
                $sem_label_map = [
                    '1st Sem'  => 'First Semester',
                    '2nd Sem'  => 'Second Semester',
                    'Mid Year' => 'Mid Year'
                ];

                foreach ($ay_courses_by_term as $term_key => $term_data):
                    $yr_num   = $year_num_map[$term_data['year']] ?? 1;
                    $sy_start = $admission_year + ($yr_num - 1);
                    $sy_end   = $sy_start + 1;
                    $ay_label = "A.Y. $sy_start-$sy_end";
                    $sem_full = $sem_label_map[$term_data['semester']] ?? $term_data['semester'];
                    $completed_list  = $term_data['completed'];
                    $uncomplete_list = $term_data['uncomplete'];
                    $block_id = 'ay-block-' . preg_replace('/[^a-z0-9]/i', '-', $term_key);
                ?>
                <div class="ay-term-block">
                    <div class="ay-term-header" onclick="toggleAYBlock('<?= $block_id ?>', this)" role="button" tabindex="0">
                        <span><?= htmlspecialchars($ay_label) ?> &mdash; <?= htmlspecialchars($sem_full) ?></span>
                        <span class="ay-term-toggle">&#9660;</span>
                    </div>
                    <div class="ay-term-body" id="<?= $block_id ?>">
                        <!-- Completed Courses Column -->
                        <div class="ay-col completed">
                            <h4>Completed Courses
                                <span style="font-size: 11px; font-weight: normal; margin-left: 6px; color: #555;">(<?= count($completed_list) ?>)</span>
                            </h4>
                            <?php if (empty($completed_list)): ?>
                                <div class="ay-empty">No completed courses yet.</div>
                            <?php else: ?>
                                <?php foreach ($completed_list as $c): ?>
                                <div class="ay-course-row">
                                    <span class="ay-course-code"><?= htmlspecialchars($c['code']) ?></span>
                                    <span class="ay-course-title"><?= htmlspecialchars($c['title']) ?></span>
                                    <span class="ay-course-badge ay-badge-pending">Pre: <?= htmlspecialchars($c['prerequisite'] ?? 'None') ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <!-- Uncomplete Courses Column -->
                        <div class="ay-col uncomplete">
                            <h4>Uncomplete Courses
                                <span style="font-size: 11px; font-weight: normal; margin-left: 6px; color: #555;">(<?= count($uncomplete_list) ?>)</span>
                            </h4>
                            <?php if (empty($uncomplete_list)): ?>
                                <div class="ay-empty" style="color: #2e7d32;">All courses completed! &#127881;</div>
                            <?php else: ?>
                                <?php foreach ($uncomplete_list as $c):
                                    $badge_class = 'ay-badge-pending';
                                    if ($c['reason'] === 'Failed')   $badge_class = 'ay-badge-failed';
                                    elseif ($c['reason'] === 'INC')  $badge_class = 'ay-badge-inc';
                                    elseif ($c['reason'] === 'Dropped') $badge_class = 'ay-badge-dropped';
                                ?>
                                <div class="ay-course-row">
                                    <span class="ay-course-code"><?= htmlspecialchars($c['code']) ?></span>
                                    <span class="ay-course-title"><?= htmlspecialchars($c['title']) ?></span>
                                    <span class="ay-course-badge ay-badge-pending">Pre: <?= htmlspecialchars($c['prerequisite'] ?? 'None') ?></span>
                                    <span class="ay-course-badge <?= $badge_class ?>">
                                        <?= htmlspecialchars($c['reason'] === 'Not Yet Taken' ? 'Pending' : $c['reason']) ?>
                                    </span>
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
        // Keep a cache-busting token in the URL without forcing a page reload.
        (function() {
            const url = new URL(window.location.href);
            if (!url.searchParams.get('_t')) {
                url.searchParams.set('_t', String(Date.now()));
                window.history.replaceState({}, '', url.toString());
            }
        })();
        
        // ANTI-CACHE: Verify displayed data matches server data
        window.addEventListener('DOMContentLoaded', function() {
            // Check if displayed values match PHP values
            const phpCompletion = document.getElementById('php-completion')?.value;
            const phpCompleted = document.getElementById('php-completed')?.value;
            const phpStudent = document.getElementById('php-student')?.value;
            const phpTimestamp = document.getElementById('php-timestamp')?.value;
            const phpGenId = document.getElementById('php-generated-id')?.value;
            
            const displayedCompletion = document.getElementById('completion-rate')?.textContent.replace('%', '').trim();
            const displayedCourses = document.getElementById('courses-completed')?.textContent.split('/')[0].trim();

            const normalizeNumber = function(value) {
                const n = Number(value);
                return Number.isFinite(n) ? n : null;
            };

            const phpCompletionNum = normalizeNumber(phpCompletion);
            const displayedCompletionNum = normalizeNumber(displayedCompletion);
            const phpCompletedNum = normalizeNumber(phpCompleted);
            const displayedCoursesNum = normalizeNumber(displayedCourses);

            const hasMismatch = (
                phpCompletionNum !== null &&
                displayedCompletionNum !== null &&
                phpCompletedNum !== null &&
                displayedCoursesNum !== null
            )
                ? (Math.abs(phpCompletionNum - displayedCompletionNum) > 0.01 || phpCompletedNum !== displayedCoursesNum)
                : (
                    String(phpCompletion).trim() !== String(displayedCompletion).trim() ||
                    String(phpCompleted).trim() !== String(displayedCourses).trim()
                );
            
            console.log('=== Study Plan Cache Verification ===');
            console.log('Student ID:', phpStudent);
            console.log('Generation ID:', phpGenId);
            console.log('Server generated:', phpTimestamp, '(' + new Date(parseInt(phpTimestamp) * 1000).toLocaleString() + ')');
            console.log('PHP says:', phpCompletion + '% completion,', phpCompleted, 'courses');
            console.log('Display shows:', displayedCompletion + '% completion,', displayedCourses, 'courses');
            
            // Check for mismatch
            if (hasMismatch) {
                console.error('âŒ CACHE MISMATCH DETECTED!');
                console.error('Expected from server:', phpCompletion + '%', phpCompleted, 'courses');
                console.error('Displayed on page:', displayedCompletion + '%', displayedCourses, 'courses');
                
                // Show visible warning
                const warningDiv = document.getElementById('cache-warning');
                if (warningDiv) {
                    warningDiv.style.display = 'block';
                    warningDiv.innerHTML = 'âš ï¸ CACHED DATA! Server says: ' + phpCompletion + '% (' + phpCompleted + '/57). Press Ctrl+Shift+R NOW!';
                }
                
                // Show banner at top
                const warningBanner = document.createElement('div');
                warningBanner.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; background: #f44336; color: white; padding: 15px; text-align: center; z-index: 10000; font-weight: bold; font-size: 16px;';
                warningBanner.innerHTML = 'âš ï¸ DISPLAYING CACHED DATA! Real completion: ' + phpCompletion + '% (' + phpCompleted + ' courses). Press Ctrl+Shift+R to see correct data!';
                document.body.insertBefore(warningBanner, document.body.firstChild);
            } else {
                console.log('âœ… Data is fresh and matches server values!');
                // Hide warning if it exists
                const warningDiv = document.getElementById('cache-warning');
                if (warningDiv) {
                    warningDiv.style.display = 'none';
                }
            }
        });
        
        // Pagination functionality
        let currentPage = 1;
        const totalPages = <?= isset($total_pages) ? $total_pages : 1 ?>;

        function changePage(direction) {
            const newPage = currentPage + direction;
            if (newPage >= 1 && newPage <= totalPages) {
                currentPage = newPage;
                updatePageDisplay();
            }
        }

        function goToPage(page) {
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
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

            // Update button states
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const prevBtnBottom = document.getElementById('prevBtnBottom');
            const nextBtnBottom = document.getElementById('nextBtnBottom');

            if (prevBtn) prevBtn.disabled = currentPage === 1;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages;
            if (prevBtnBottom) prevBtnBottom.disabled = currentPage === 1;
            if (nextBtnBottom) nextBtnBottom.disabled = currentPage === totalPages;

            // Scroll to top of study plan container
            const container = document.querySelector('.study-plan-container');
            if (container) {
                container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
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

        // ===== A.Y. Course Overview Modal =====
        document.addEventListener('student-study-plan:action', function(event) {
            const action = event && event.detail ? event.detail.action : '';
            if (action === 'print') {
                window.print();
            } else if (action === 'overview') {
                openAYModal();
            } else if (action === 'progress') {
                const overview = document.querySelector('.academic-overview');
                if (overview) {
                    overview.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });

        function openAYModal() {
            const overlay = document.getElementById('ay-modal-overlay');
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeAYModal() {
            const overlay = document.getElementById('ay-modal-overlay');
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }

        // Close modal when clicking the backdrop
        document.getElementById('ay-modal-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeAYModal();
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeAYModal();
        });

        function toggleAYBlock(blockId, headerEl) {
            const body = document.getElementById(blockId);
            if (!body) return;
            const isHidden = body.classList.toggle('hidden');
            headerEl.classList.toggle('collapsed', isHidden);
        }

    </script>
</body>
</html>

