<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/study_plan_override_service.php';

$isAdmin = isset($_SESSION['admin_id']) || isset($_SESSION['admin_username']);
$isProgramCoordinator = isset($_SESSION['username']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'program_coordinator');

if (!$isAdmin && !$isProgramCoordinator) {
    header('Location: ../index.html');
    exit();
}

if (!isset($_GET['student_id']) || trim((string)$_GET['student_id']) === '') {
    die('Invalid student ID.');
}

$conn = getDBConnection();
$conn->set_charset('utf8mb4');

function pcSortTaggedCoursesLast(array $courses): array
{
    $indexed = [];
    foreach ($courses as $index => $course) {
        $hasDeferredTag = !empty($course['needs_retake']) || !empty($course['cross_registered']) || !empty($course['forced_added']);
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

$coordinatorName = $isAdmin
    ? (isset($_SESSION['admin_full_name']) ? htmlspecialchars((string)$_SESSION['admin_full_name']) : 'Admin')
    : (isset($_SESSION['full_name']) ? htmlspecialchars((string)$_SESSION['full_name']) : 'Program Coordinator');
$roleLabel = $isAdmin ? 'Admin' : 'Program Coordinator';
$panelTitle = $isAdmin ? 'Admin Panel' : 'Program Coordinator Panel';
$headerBadge = $isAdmin ? 'Admin Panel' : ($coordinatorName . ' | ' . $roleLabel);
$dashboardHref = $isAdmin ? '../admin/index.php' : 'index.php';
$listStudentsHref = $isAdmin ? '../admin/list_of_students.php' : 'list_of_students.php';
$logoutHref = $isAdmin ? '../admin/logout.php' : 'logout.php';
$studentId = trim((string)$_GET['student_id']);
$coordinatorProgram = 'All Programs';
$student = null;
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        '/api/study-plan/student/bootstrap',
        [
            'bridge_authorized' => true,
            'student_id' => $studentId,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
        $student = [
            'student_number' => (string) ($bridgeData['student']['student_number'] ?? $studentId),
            'last_name' => (string) ($bridgeData['student']['last_name'] ?? ''),
            'first_name' => (string) ($bridgeData['student']['first_name'] ?? ''),
            'middle_name' => (string) ($bridgeData['student']['middle_name'] ?? ''),
            'program' => (string) ($bridgeData['student']['program'] ?? ''),
            'date_of_admission' => (string) ($bridgeData['student']['date_of_admission'] ?? ''),
        ];
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $studentStmt = $conn->prepare('SELECT student_number, last_name, first_name, middle_name, program, date_of_admission FROM student_info WHERE student_number = ? LIMIT 1');
    $studentStmt->bind_param('s', $studentId);
    $studentStmt->execute();
    $studentResult = $studentStmt->get_result();
    $student = $studentResult ? $studentResult->fetch_assoc() : null;
    $studentStmt->close();
}

if (!$student) {
    die('Student not found.');
}

$generator = new StudyPlanGenerator($studentId, (string)$student['program']);
$studyPlan = $generator->generateOptimizedPlan();
$stats = $generator->getCompletionStats();
$completedTerms = $generator->getCompletedTerms();
$ayCoursesByTerm = $generator->getAllCoursesGroupedByTerm();

$admissionYear = null;
$admissionDate = (string)($student['date_of_admission'] ?? '');
if ($admissionDate !== '' && strtotime($admissionDate) !== false) {
    $admissionYear = (int)date('Y', strtotime($admissionDate));
}
if ($admissionYear === null && strlen($studentId) >= 4) {
    $candidateYear = (int)substr($studentId, 0, 4);
    $currentYear = (int)date('Y');
    if ($candidateYear >= 2000 && $candidateYear <= $currentYear) {
        $admissionYear = $candidateYear;
    }
}
if ($admissionYear === null) {
    $admissionYear = (int)date('Y') - 4;
}

$validYears = spoValidOverrideYears();
$validSemesters = spoValidOverrideSemesters();
$overrideMap = spoLoadStudyPlanOverrides($conn, $studentId);

$displayTerms = [];
$completedTermKeys = [];

// Show completed/past terms first (same behavior as student study plan page).
foreach ($completedTerms as $term) {
    $completedTermKeys[((string)($term['year'] ?? '')) . '|' . ((string)($term['semester'] ?? ''))] = true;
    $displayTerms[] = [
        'year' => (string)($term['year'] ?? ''),
        'semester' => (string)($term['semester'] ?? ''),
        'total_units' => (int)($term['total_units'] ?? 0),
        'max_units' => null,
        'courses' => $term['courses'] ?? [],
        'completed_term' => true,
        'skipped' => false,
    ];
}

$futureTermKeys = [];
foreach ($studyPlan as $term) {
    $futureTermKeys[((string)($term['year'] ?? '')) . '|' . ((string)($term['semester'] ?? ''))] = true;
}

$partialTerms = [];
foreach ($ayCoursesByTerm as $termKey => $termData) {
    if (
        isset($completedTermKeys[$termKey]) ||
        isset($futureTermKeys[$termKey]) ||
        empty($termData['completed']) ||
        empty($termData['uncomplete'])
    ) {
        continue;
    }

    $courses = [];
    foreach (($termData['completed'] ?? []) as $course) {
        $courses[] = [
            'code' => $course['code'] ?? '',
            'title' => $course['title'] ?? '',
            'units' => $course['units'] ?? 0,
            'prerequisite' => $course['prerequisite'] ?? 'None',
            'status' => 'Passed',
            'status_variant' => 'passed',
            'grade' => $course['grade'] ?? '',
        ];
    }

    foreach (($termData['uncomplete'] ?? []) as $course) {
        $reason = trim((string)($course['reason'] ?? 'Not Yet Taken'));
        $courses[] = [
            'code' => $course['code'] ?? '',
            'title' => $course['title'] ?? '',
            'units' => $course['units'] ?? 0,
            'prerequisite' => $course['prerequisite'] ?? 'None',
            'status' => $reason,
            'status_variant' => strtolower(str_replace(' ', '-', $reason)),
            'grade' => $course['grade'] ?? '',
        ];
    }

    $partialTerms[] = [
        'year' => (string)($termData['year'] ?? ''),
        'semester' => (string)($termData['semester'] ?? ''),
        'total_units' => (int) array_reduce($courses, static function ($carry, $course) {
            $courseCode = strtoupper(trim((string)($course['code'] ?? '')));
            $courseTitle = strtoupper(trim((string)($course['title'] ?? '')));
            $isNonCredit = $courseCode === 'CVSU 101'
                || strpos($courseTitle, 'NON-CREDIT') !== false
                || strpos($courseTitle, 'NON CREDIT') !== false;
            if ($isNonCredit) {
                return $carry;
            }

            return $carry + (float)($course['units'] ?? 0);
        }, 0),
        'max_units' => null,
        'courses' => $courses,
        'completed_term' => false,
        'partial_term' => true,
        'skipped' => false,
    ];
}

// Append generated future/current terms with coordinator overrides applied.
$futureTermMeta = [];
$futureTermBuckets = [];
$futureTermBaseUnits = [];
$futureTermOverrideDeltas = [];
$yearOrder = array_flip($validYears);
$semesterOrder = array_flip($validSemesters);

foreach ($studyPlan as $term) {
    $baseYear = (string)($term['year'] ?? '');
    $baseSemester = (string)($term['semester'] ?? '');
    $baseKey = $baseYear . '|' . $baseSemester;

    if (!isset($futureTermMeta[$baseKey])) {
        $baseTermUnits = 0.0;
        foreach (($term['courses'] ?? []) as $baseCourse) {
            $baseCourseCode = strtoupper(trim((string)($baseCourse['code'] ?? '')));
            $baseCourseTitle = strtoupper(trim((string)($baseCourse['title'] ?? '')));
            $baseIsNonCredit = $baseCourseCode === 'CVSU 101'
                || strpos($baseCourseTitle, 'NON-CREDIT') !== false
                || strpos($baseCourseTitle, 'NON CREDIT') !== false;
            if ($baseIsNonCredit) {
                continue;
            }

            $baseTermUnits += (float)($baseCourse['units'] ?? 0);
        }

        $futureTermMeta[$baseKey] = [
            'max_units' => isset($term['max_units']) ? (int)$term['max_units'] : null,
            'skipped' => !empty($term['skipped']),
            'skip_reason' => (string)($term['skip_reason'] ?? ''),
        ];
        $futureTermBaseUnits[$baseKey] = $baseTermUnits;
        $futureTermOverrideDeltas[$baseKey] = 0.0;
    }

    foreach (($term['courses'] ?? []) as $course) {
        $courseCode = trim((string)($course['code'] ?? ''));
        $targetYear = $baseYear;
        $targetSemester = $baseSemester;
        $isMoved = false;
        $courseCodeUpper = strtoupper(trim((string)($course['code'] ?? '')));
        $courseTitleUpper = strtoupper(trim((string)($course['title'] ?? '')));
        $isNonCredit = $courseCodeUpper === 'CVSU 101'
            || strpos($courseTitleUpper, 'NON-CREDIT') !== false
            || strpos($courseTitleUpper, 'NON CREDIT') !== false;
        $courseCountedUnits = $isNonCredit ? 0.0 : (float)($course['units'] ?? 0);

        if ($courseCode !== '' && isset($overrideMap[$courseCode])) {
            $candidateYear = $overrideMap[$courseCode]['year'];
            $candidateSemester = $overrideMap[$courseCode]['semester'];
            $baseYearOrder = $yearOrder[$baseYear] ?? 99;
            $candidateYearOrder = $yearOrder[$candidateYear] ?? 99;
            $baseSemesterOrder = $semesterOrder[$baseSemester] ?? 99;
            $candidateSemesterOrder = $semesterOrder[$candidateSemester] ?? 99;
            $baseTermOrder = ($baseYearOrder * 10) + $baseSemesterOrder;
            $candidateTermOrder = ($candidateYearOrder * 10) + $candidateSemesterOrder;

            if ($candidateTermOrder >= $baseTermOrder && $candidateTermOrder < 999) {
                $candidateKey = $candidateYear . '|' . $candidateSemester;
                $targetMaxUnits = (float)($futureTermMeta[$candidateKey]['max_units'] ?? 21);
                $candidateTargetTotal = (float)($futureTermBaseUnits[$candidateKey] ?? 0.0)
                    + (float)($futureTermOverrideDeltas[$candidateKey] ?? 0.0);

                if (
                    $candidateKey === $baseKey
                    || ($candidateTargetTotal + $courseCountedUnits) <= $targetMaxUnits
                ) {
                    $targetYear = $candidateYear;
                    $targetSemester = $candidateSemester;
                    $isMoved = ($targetYear !== $baseYear || $targetSemester !== $baseSemester);

                    if ($candidateKey !== $baseKey) {
                        $futureTermOverrideDeltas[$baseKey] = (float)($futureTermOverrideDeltas[$baseKey] ?? 0.0) - $courseCountedUnits;
                        $futureTermOverrideDeltas[$candidateKey] = (float)($futureTermOverrideDeltas[$candidateKey] ?? 0.0) + $courseCountedUnits;
                    }
                }
            }
        }

        $targetKey = $targetYear . '|' . $targetSemester;
        if (!isset($futureTermBuckets[$targetKey])) {
            $futureTermBuckets[$targetKey] = [
                'year' => $targetYear,
                'semester' => $targetSemester,
                'courses' => [],
                'total_units' => 0,
                'max_units' => null,
                'skipped' => false,
                'skip_reason' => '',
            ];
        }

        $courseUnits = (int)($course['units'] ?? 0);
        $course['original_year'] = $baseYear;
        $course['original_semester'] = $baseSemester;
        $course['moved_override'] = $isMoved;

        $futureTermBuckets[$targetKey]['courses'][] = $course;
        $futureTermBuckets[$targetKey]['total_units'] += $courseUnits;
    }
}

// Merge per-term metadata where available.
foreach ($futureTermBuckets as $k => &$bucket) {
    if (isset($futureTermMeta[$k])) {
        $bucket['max_units'] = $futureTermMeta[$k]['max_units'];
        $bucket['skipped'] = $futureTermMeta[$k]['skipped'];
        $bucket['skip_reason'] = $futureTermMeta[$k]['skip_reason'];
    }
}
unset($bucket);

usort($futureTermBuckets, function ($a, $b) use ($yearOrder, $semesterOrder) {
    $ya = $yearOrder[$a['year']] ?? 99;
    $yb = $yearOrder[$b['year']] ?? 99;
    if ($ya !== $yb) {
        return $ya <=> $yb;
    }

    $sa = $semesterOrder[$a['semester']] ?? 99;
    $sb = $semesterOrder[$b['semester']] ?? 99;
    return $sa <=> $sb;
});

foreach ($futureTermBuckets as $term) {
    $displayTerms[] = [
        'year' => (string)($term['year'] ?? ''),
        'semester' => (string)($term['semester'] ?? ''),
        'total_units' => (int)($term['total_units'] ?? 0),
        'max_units' => isset($term['max_units']) ? (int)$term['max_units'] : null,
        'courses' => $term['courses'] ?? [],
        'completed_term' => false,
        'partial_term' => false,
        'skipped' => !empty($term['skipped']),
        'skip_reason' => (string)($term['skip_reason'] ?? ''),
    ];
}

if (!empty($partialTerms)) {
    usort($partialTerms, function ($a, $b) use ($yearOrder, $semesterOrder) {
        $ya = $yearOrder[$a['year']] ?? 99;
        $yb = $yearOrder[$b['year']] ?? 99;
        if ($ya !== $yb) {
            return $ya <=> $yb;
        }

        $sa = $semesterOrder[$a['semester']] ?? 99;
        $sb = $semesterOrder[$b['semester']] ?? 99;
        return $sa <=> $sb;
    });

    $displayTerms = array_merge($displayTerms, $partialTerms);
}

usort($displayTerms, function ($a, $b) use ($yearOrder, $semesterOrder) {
    $ya = $yearOrder[$a['year']] ?? 99;
    $yb = $yearOrder[$b['year']] ?? 99;
    if ($ya !== $yb) {
        return $ya <=> $yb;
    }

    $sa = $semesterOrder[$a['semester']] ?? 99;
    $sb = $semesterOrder[$b['semester']] ?? 99;
    if ($sa !== $sb) {
        return $sa <=> $sb;
    }

    $aWeight = !empty($a['completed_term']) ? 0 : (!empty($a['partial_term']) ? 1 : 2);
    $bWeight = !empty($b['completed_term']) ? 0 : (!empty($b['partial_term']) ? 1 : 2);
    return $aWeight <=> $bWeight;
});

$fullName = trim((string)($student['last_name'] ?? '') . ', ' . (string)($student['first_name'] ?? '') . ' ' . (string)($student['middle_name'] ?? ''));
$lastPlannedTerm = null;
$remainingSemesters = 0;
foreach ($studyPlan as $term) {
    if (empty($term['skipped']) && !empty($term['courses'])) {
        $lastPlannedTerm = $term;
        $remainingSemesters++;
    }
}

$estimatedGraduation = null;
$graduationSchoolYear = '';
if ($lastPlannedTerm) {
    $gradYearNum = (int)preg_replace('/[^0-9]/', '', (string)($lastPlannedTerm['year'] ?? ''));
    $gradSyStart = $admissionYear + ($gradYearNum > 0 ? $gradYearNum - 1 : 0);
    $gradSyEnd = $gradSyStart + 1;
    $estimatedGraduation = (string)($lastPlannedTerm['semester'] ?? '') . ', ' . (string)($lastPlannedTerm['year'] ?? '');
    $graduationSchoolYear = "A.Y. $gradSyStart-$gradSyEnd";
} elseif (!empty($stats['remaining_courses']) && (int)$stats['remaining_courses'] === 0) {
    $estimatedGraduation = 'Completed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdmin ? 'Student Study Plan - Admin' : 'Student Study Plan - Program Coordinator' ?></title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #f2f5f1;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
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
        .menu-toggle:hover { background: rgba(255, 255, 255, 0.22); }
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
        .sidebar-menu img {
            width: 20px;
            height: 20px;
            margin-right: 0;
            flex: 0 0 20px;
            filter: brightness(0) invert(1);
        }
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
        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 45px);
            width: calc(100vw - 250px);
            transition: margin-left 0.3s ease, width 0.3s ease;
            padding: 20px;
        }
        .main-content.expanded { margin-left: 0; width: 100vw; }
        .page-card {
            background: #fff;
            border: 1px solid #dbe5d9;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(32, 96, 24, 0.08);
            padding: 16px;
            margin-bottom: 14px;
        }
        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #206018;
            margin-bottom: 4px;
        }
        .subtitle { color: #666; font-size: 13px; }
        .btn-back {
            display: inline-block;
            text-decoration: none;
            padding: 7px 10px;
            background: #e5e7eb;
            border-radius: 5px;
            color: #333;
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 8px;
        }
        .student-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 8px;
            font-size: 14px;
            margin-top: 8px;
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
            border: 1px solid #dfe7de;
            border-radius: 12px;
            margin-bottom: 14px;
            overflow: hidden;
            box-shadow: 0 6px 16px rgba(28, 70, 30, 0.06);
        }
        .term-header {
            background: linear-gradient(135deg, #f6faf5 0%, #edf5eb 100%);
            padding: 11px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            border-bottom: 1px solid #dde7db;
        }
        .term-title { font-weight: 700; color: #1b4332; }
        .term-meta { font-size: 12px; color: #4f5f56; font-weight: 600; }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: auto;
            border: 1px solid #e4ebe3;
            border-left: none;
            border-right: none;
            background: #fff;
        }
        th, td {
            padding: 9px 10px;
            border-bottom: 1px solid #edf2ec;
            font-size: 13px;
        }
        th {
            background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            color: #fff;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            font-weight: 700;
        }
        tbody tr:nth-child(even) { background: #f9fcf9; }
        tbody tr:hover { background: #eef6ee; }
        td { color: #223328; }
        th:nth-child(1), td:nth-child(1) { width: 110px; text-align: center; }
        th:nth-child(2), td:nth-child(2) { text-align: left; }
        th:nth-child(3), td:nth-child(3) { width: 80px; text-align: center; }
        th:nth-child(4), td:nth-child(4) { width: 175px; text-align: center; }
        .warning {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
            font-size: 13px;
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
        .tag-completed { background: #e8f5e9; color: #2e7d32; }
        .tag-retake { background: #ffe8cc; color: #9a3412; }
        .tag-cross { background: #dbeafe; color: #1d4ed8; }
        .tag-moved { background: #e9d5ff; color: #6d28d9; }
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
            max-width: 1120px;
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
        .student-details p { margin: 0; }
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
        .plan-tag-moved { background: #6d28d9; }
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
        .course-table thead { background: transparent; }
        .course-table th {
            padding: 8px 12px;
            text-align: center;
            font-weight: 700;
            color: #000;
            border: 1px solid #000;
            font-size: 13px;
            background: transparent;
        }
        .course-table tbody tr { border: 1px solid #000; }
        .course-table tbody tr:hover { background: #f9f9f9; }
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
        .course-table td:nth-child(4),
        .course-table td:nth-child(5),
        .course-table td:nth-child(6) {
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
        .move-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 7px;
            flex-wrap: wrap;
        }
        .move-controls select {
            font-size: 11px;
            padding: 4px 7px;
            border: 1px solid #c8d7c8;
            border-radius: 6px;
            max-width: 165px;
            background: #f8fbf8;
        }
        .move-controls button {
            font-size: 11px;
            padding: 4px 9px;
            border: none;
            background: #206018;
            color: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .move-controls button:hover { background: #2d8f22; }

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
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100vw; }
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
                width: 100% !important;
                padding: 0 !important;
            }
            .page-card,
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
            .course-table th:last-child,
            .course-table td:last-child {
                display: none !important;
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
        <div class="admin-info"><?= htmlspecialchars($headerBadge); ?></div>
    </div>

    <?php if ($isAdmin): ?>
        <?php
        $activeAdminPage = 'list_of_students';
        $adminSidebarCollapsed = true;
        require __DIR__ . '/../includes/admin_sidebar.php';
        ?>
    <?php else: ?>
        <div class="sidebar collapsed" id="sidebar">
            <div class="sidebar-header"><h3><?= htmlspecialchars($panelTitle) ?></h3></div>
            <ul class="sidebar-menu">
                <div class="menu-group">
                    <div class="menu-group-title">Dashboard</div>
                    <li><a href="<?= htmlspecialchars($dashboardHref) ?>"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
                </div>
                <div class="menu-group">
                    <div class="menu-group-title">Modules</div>
                    <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum" style="filter: brightness(0) invert(1);"> Curriculum Management</a></li>
                    <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers" style="filter: brightness(0) invert(1);"> Adviser Management</a></li>
                    <li><a href="<?= htmlspecialchars($listStudentsHref) ?>" class="active"><img src="../pix/checklist.png" alt="Students" style="filter: brightness(0) invert(1);"> List of Students</a></li>
                    <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift" style="filter: brightness(0) invert(1);"> Program Shift Requests</a></li>
                    <li><a href="profile.php"><img src="../pix/account.png" alt="Profile" style="filter: brightness(0) invert(1);"> Update Profile</a></li>
                </div>
                <div class="menu-group">
                    <div class="menu-group-title">Account</div>
                    <li><a href="<?= htmlspecialchars($logoutHref) ?>"><img src="../pix/singout.png" alt="Sign Out" style="filter: brightness(0) invert(1);"> Sign Out</a></li>
                </div>
            </ul>
        </div>
    <?php endif; ?>

    <div class="main-content" id="mainContent">
        <div class="academic-overview">
            <h3 class="academic-overview__header">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5zm0 18c-4.41 0-8-3.59-8-8V9h16v3c0 4.41-3.59 8-8 8z"/>
                </svg>
                Academic Progress Overview
                <span class="overview-meta student">Student: <?= htmlspecialchars((string)$student['student_number']); ?></span>
                <span class="overview-meta generated" title="Page generated at <?= date('Y-m-d H:i:s') ?>">
                    Generated: <?= date('H:i:s') ?>
                </span>
            </h3>
            <div class="academic-stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars((string)$stats['completion_percentage']); ?>%</div>
                    <div class="stat-sub">Completion Rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars((string)$stats['completed_courses']); ?>/<?= htmlspecialchars((string)($stats['total_courses'] ?? 0)); ?></div>
                    <div class="stat-sub">Courses Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars((string)($stats['completed_units'] ?? 0)); ?>/<?= htmlspecialchars((string)($stats['total_units'] ?? 0)); ?></div>
                    <div class="stat-sub">Units Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars((string)$stats['remaining_courses']); ?></div>
                    <div class="stat-sub">Courses Remaining</div>
                </div>
                <?php if ($estimatedGraduation): ?>
                <div class="stat-card">
                    <div class="stat-value compact"><?= htmlspecialchars($estimatedGraduation); ?></div>
                    <?php if ($graduationSchoolYear !== ''): ?><div class="stat-note"><?= htmlspecialchars($graduationSchoolYear); ?></div><?php endif; ?>
                    <div class="stat-sub">Projected Completion</div>
                </div>
                <?php endif; ?>
                <div class="stat-card">
                    <div class="stat-value"><?= (int)$remainingSemesters; ?></div>
                    <div class="stat-sub">Semesters to Go</div>
                </div>
            </div>

            <?php
            $retentionStatus = $stats['retention_status'] ?? 'None';
            $retentionColors = [
                'None' => ['bg' => '#e8f5e9', 'border' => '#4CAF50', 'text' => '#2e7d32', 'label' => 'Good Standing'],
                'Warning' => ['bg' => '#fff3e0', 'border' => '#FF9800', 'text' => '#e65100', 'label' => 'Warning Status'],
                'Probation' => ['bg' => '#fff3e0', 'border' => '#fd7e14', 'text' => '#bf360c', 'label' => 'Probationary Status'],
                'Disqualification' => ['bg' => '#ffebee', 'border' => '#f44336', 'text' => '#c62828', 'label' => 'Disqualification Status']
            ];
            $retStyle = $retentionColors[$retentionStatus] ?? $retentionColors['None'];
            ?>
            <div style="margin-top: 15px; padding: 15px; background: <?= $retStyle['bg'] ?>; border-left: 4px solid <?= $retStyle['border'] ?>; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;"><?php if ($retentionStatus === 'None'): ?>&#9989;<?php elseif ($retentionStatus === 'Warning'): ?>&#9888;&#65039;<?php elseif ($retentionStatus === 'Probation'): ?>&#128680;<?php else: ?>&#10060;<?php endif; ?></span>
                    <strong style="font-size: 15px; color: <?= $retStyle['text'] ?>;">Retention Policy: <?= $retStyle['label'] ?></strong>
                </div>
                <p style="margin: 0; font-size: 13px; color: #333;">
                    <?php if ($retentionStatus === 'Warning'): ?>
                        The student has failed 30-50% of enrolled subjects in the latest semester.
                    <?php elseif ($retentionStatus === 'Probation'): ?>
                        The student is under probationary status with a reduced load limit.
                    <?php elseif ($retentionStatus === 'Disqualification'): ?>
                        The student is currently under disqualification status.
                    <?php else: ?>
                        No retention issues detected. The student is in good academic standing.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($remainingSemesters > 0): ?>
            <div style="margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border-left: 4px solid #4CAF50; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">&#128202;</span>
                    <strong style="font-size: 15px; color: #2e7d32;">Timeline Optimization Summary</strong>
                </div>
                <p style="margin: 0; font-size: 13px; color: #333;">
                    This plan completes all remaining courses in <strong><?= $remainingSemesters ?> semester<?= $remainingSemesters > 1 ? 's' : '' ?></strong>,
                    keeping the student on track based on the current curriculum and eligible course sequencing.
                </p>
                <?php if ($estimatedGraduation): ?>
                <p style="margin: 8px 0 0 0; font-size: 13px; color: #206018; font-weight: 600;">
                    Projected Completion: <?= htmlspecialchars($estimatedGraduation) ?><?= $graduationSchoolYear !== '' ? ' (' . htmlspecialchars($graduationSchoolYear) . ')' : '' ?>
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
                <a class="btn-back" href="<?= htmlspecialchars($listStudentsHref) ?>">Back to Student Directory</a>
                <div class="student-details">
                    <p><span class="label">Name</span> : <span class="value"><?= htmlspecialchars($fullName); ?></span></p>
                    <p><span class="label">Student No.</span> : <span class="value"><?= htmlspecialchars((string)$student['student_number']); ?></span></p>
                    <p><span class="label">Program</span> : <span class="value"><?= htmlspecialchars((string)$student['program']); ?></span></p>
                    <p><span class="label">Admission Date</span> : <span class="value"><?= htmlspecialchars((string)($student['date_of_admission'] ?? '')); ?></span></p>
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
                    Study plan generation is limited because this student has <?= (int)$stats['thrice_failed_count']; ?> course(s) failed three or more times.
                </div>
            <?php endif; ?>

            <?php if (empty($displayTerms)): ?>
                <div class="warning" style="background:#e8f5e9;border-color:#c8e6c9;color:#206018;">No study plan terms generated for this student yet.</div>
            <?php else: ?>
                <?php
                $futureDividerShown = false;
                foreach ($displayTerms as $term):
                    $isCompletedTerm = !empty($term['completed_term']);
                    $isPartialTerm = !empty($term['partial_term']);
                    $yearLabel = (string)($term['year'] ?? '');
                    $yearNum = (int)preg_replace('/[^0-9]/', '', $yearLabel);
                    $syStart = $admissionYear + ($yearNum > 0 ? $yearNum - 1 : 0);
                    $syEnd = $syStart + 1;
                    $schoolYear = "A.Y. $syStart-$syEnd";
                    if (!$isCompletedTerm && !$isPartialTerm && !$futureDividerShown && !empty($completedTerms)) {
                        $futureDividerShown = true;
                ?>
                    <div class="completed-divider">
                        <span>&#9660; Remaining Semesters (AI-Optimized Plan) &#9660;</span>
                    </div>
                <?php } $termUnits = (int)($term['total_units'] ?? 0); ?>
                    <?php if (!empty($term['skipped'])): ?>
                        <div class="semester-section" style="border: 2px dashed #f44336; opacity: 0.78;">
                                <div style="background: linear-gradient(135deg, #ffebee, #ffcdd2); padding: 25px; text-align: center; border-radius: 8px;">
                                <div class="semester-header" style="color: #c62828;">
                                    <?= htmlspecialchars((string)$term['year']); ?> - <?= htmlspecialchars((string)$term['semester']); ?>, <?= htmlspecialchars($schoolYear); ?>
                                </div>
                                <div style="font-size: 36px; margin: 10px 0;">&#128683;</div>
                                <p style="font-size: 14px; color: #c62828; font-weight: 600; margin: 0;">
                                    <?= htmlspecialchars((string)($term['skip_reason'] ?? 'Semester skipped due to retention policy')); ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="semester-section <?= $isCompletedTerm ? 'completed-term' : '' ?>" style="<?= $isCompletedTerm ? 'border: 1px solid #c8e6c9;' : '' ?>">
                            <?php if ($isCompletedTerm): ?>
                                <div style="background: linear-gradient(135deg, #e8f5e9, #c8e6c9); padding: 8px; text-align: center; font-weight: 700; font-size: 13px; color: #2e7d32;">
                                    <?= htmlspecialchars((string)$term['year']); ?> - <?= htmlspecialchars((string)$term['semester']); ?>, <?= htmlspecialchars($schoolYear); ?>
                                    <span class="completed-badge">COMPLETED</span>
                                </div>
                            <?php elseif ($isPartialTerm): ?>
                                <div style="background: linear-gradient(135deg, #edf7ee, #dbeadf); padding: 8px; text-align: center; font-weight: 700; font-size: 13px; color: #2f5d34;">
                                    <?= htmlspecialchars((string)$term['year']); ?> - <?= htmlspecialchars((string)$term['semester']); ?>, <?= htmlspecialchars($schoolYear); ?>
                                    <span style="font-size: 10px; background: #fff8e1; color: #8d6e00; padding: 2px 6px; border-radius: 4px; margin-left: 6px; font-weight: 700;">IN PROGRESS</span>
                                </div>
                            <?php else: ?>
                                <div class="semester-header">
                                    <?= htmlspecialchars((string)$term['year']); ?> - <?= htmlspecialchars((string)$term['semester']); ?>, <?= htmlspecialchars($schoolYear); ?>
                                    <?php if (!empty($term['max_units'])): ?>
                                        <span style="font-size: 11px; background: #fff3e0; color: #e65100; padding: 2px 8px; border-radius: 4px; margin-left: 8px; font-weight: 600;">
                                            Max <?= (int)$term['max_units']; ?> units
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
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $termCourses = pcSortTaggedCoursesLast((array)($term['courses'] ?? [])); ?>
                                <?php foreach ($termCourses as $course): ?>
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
                                        <td><?= htmlspecialchars((string)($course['code'] ?? '')); ?></td>
                                        <td><?= htmlspecialchars((string)($course['title'] ?? '')); ?></td>
                                        <td><?= $isNonCredit ? '(' . number_format((float)($course['units'] ?? 0), 1) . ')' : number_format((float)($course['units'] ?? 0), 1) ?></td>
                                        <td><?= htmlspecialchars($prerequisite); ?></td>
                                        <td>
                                            <?php
                                                $crossRegSourceProgram = trim((string)($course['cross_reg_source_program'] ?? ''));
                                                $crossRegTooltip = $crossRegSourceProgram !== '' ? 'Cross-registered from: ' . $crossRegSourceProgram : 'Cross-registered course';
                                            ?>
                                            <?php if ($isCompletedTerm): ?><span class="plan-tag plan-tag-completed">Completed</span><?php endif; ?>
                                            <?php if ($isPartialTerm): ?><span class="plan-tag plan-tag-cross">In Progress</span><?php endif; ?>
                                            <?php
                                                $status = trim((string)($course['status'] ?? ''));
                                                $statusVariant = trim((string)($course['status_variant'] ?? ''));
                                            ?>
                                            <?php if ($isPartialTerm && $status !== ''): ?>
                                                <?php
                                                    $partialBadgeClass = 'plan-tag-to-add';
                                                    if ($statusVariant === 'passed' || $statusVariant === 'credited') {
                                                        $partialBadgeClass = 'plan-tag-completed';
                                                    } elseif ($statusVariant === 'failed') {
                                                        $partialBadgeClass = 'plan-tag-retake';
                                                    } elseif ($statusVariant === 'inc' || $statusVariant === 'dropped') {
                                                        $partialBadgeClass = 'plan-tag-cross';
                                                    }
                                                ?>
                                                <span class="plan-tag <?= htmlspecialchars($partialBadgeClass); ?>"><?= htmlspecialchars(strtoupper($status)); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($course['needs_retake'])): ?><span class="plan-tag plan-tag-retake">Retake</span><?php endif; ?>
                                            <?php if (!empty($course['cross_registered'])): ?><span class="plan-tag plan-tag-cross" title="<?= htmlspecialchars($crossRegTooltip) ?>" aria-label="<?= htmlspecialchars($crossRegTooltip) ?>">Cross-Reg</span><?php endif; ?>
                                            <?php if (!empty($course['needs_retake']) || !empty($course['cross_registered'])): ?><span class="plan-tag plan-tag-to-add">To be added</span><?php endif; ?>
                                            <?php if (!empty($course['moved_override'])): ?><span class="plan-tag plan-tag-moved">Moved</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$isCompletedTerm && !$isPartialTerm && !$isAdmin): ?>
                                                <?php
                                                    $courseCode = (string)($course['code'] ?? '');
                                                    $currentPlacement = ((string)($term['year'] ?? '')) . '|' . ((string)($term['semester'] ?? ''));
                                                    $currentSelectId = 'move_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $courseCode) . '_' . md5($currentPlacement);
                                                    $termOptions = [
                                                        '1st Yr|1st Sem' => '1st Yr - 1st Sem',
                                                        '1st Yr|2nd Sem' => '1st Yr - 2nd Sem',
                                                        '1st Yr|Mid Year' => '1st Yr - Mid Year',
                                                        '2nd Yr|1st Sem' => '2nd Yr - 1st Sem',
                                                        '2nd Yr|2nd Sem' => '2nd Yr - 2nd Sem',
                                                        '2nd Yr|Mid Year' => '2nd Yr - Mid Year',
                                                        '3rd Yr|1st Sem' => '3rd Yr - 1st Sem',
                                                        '3rd Yr|2nd Sem' => '3rd Yr - 2nd Sem',
                                                        '3rd Yr|Mid Year' => '3rd Yr - Mid Year',
                                                        '4th Yr|1st Sem' => '4th Yr - 1st Sem',
                                                        '4th Yr|2nd Sem' => '4th Yr - 2nd Sem',
                                                        '4th Yr|Mid Year' => '4th Yr - Mid Year',
                                                    ];
                                                    $currentYear = (string)($term['year'] ?? '');
                                                    $currentSemester = (string)($term['semester'] ?? '');
                                                    $currentPlacementOrder = (($yearOrder[$currentYear] ?? 99) * 10) + ($semesterOrder[$currentSemester] ?? 99);
                                                ?>
                                                <div class="move-controls">
                                                    <select id="<?= htmlspecialchars($currentSelectId); ?>">
                                                        <?php foreach ($termOptions as $termValue => $termLabel): ?>
                                                            <?php
                                                                [$optionYear, $optionSemester] = array_pad(explode('|', (string)$termValue, 2), 2, '');
                                                                $optionPlacementOrder = (($yearOrder[$optionYear] ?? 99) * 10) + ($semesterOrder[$optionSemester] ?? 99);
                                                                if ($optionPlacementOrder < $currentPlacementOrder) {
                                                                    continue;
                                                                }
                                                            ?>
                                                            <option value="<?= htmlspecialchars($termValue); ?>" <?= $termValue === $currentPlacement ? 'selected' : ''; ?>>
                                                                <?= htmlspecialchars($termLabel); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="button" onclick="moveCourseTerm('<?= htmlspecialchars($courseCode, ENT_QUOTES); ?>', '<?= htmlspecialchars($currentSelectId, ENT_QUOTES); ?>')">Move</button>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#64748b;font-size:12px;font-weight:600;">No Action</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="2" style="text-align: right;"><strong>TOTAL</strong></td>
                                        <td><strong><?= $termUnits; ?></strong></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
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
                $yearNumMap = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
                $semLabelMap = [
                    '1st Sem' => 'First Semester',
                    '2nd Sem' => 'Second Semester',
                    'Mid Year' => 'Mid Year',
                ];

                foreach ($ayCoursesByTerm as $termKey => $termData):
                    $yrNum = $yearNumMap[$termData['year']] ?? 1;
                    $syStart = $admissionYear + ($yrNum - 1);
                    $syEnd = $syStart + 1;
                    $ayLabel = 'A.Y. ' . $syStart . '-' . $syEnd;
                    $semFull = $semLabelMap[$termData['semester']] ?? $termData['semester'];
                    $completedList = $termData['completed'] ?? [];
                    $uncompleteList = $termData['uncomplete'] ?? [];
                    $blockId = 'ay-block-' . preg_replace('/[^a-z0-9]/i', '-', (string)$termKey);
                ?>
                <div class="ay-term-block">
                    <div class="ay-term-header" onclick="toggleAYBlock('<?= htmlspecialchars($blockId); ?>', this)">
                        <span><?= htmlspecialchars($termData['year'] . ' - ' . $termData['semester']); ?> | <?= htmlspecialchars($ayLabel); ?> (<?= htmlspecialchars($semFull); ?>)</span>
                        <span class="ay-term-toggle">&#9662;</span>
                    </div>
                    <div class="ay-term-body" id="<?= htmlspecialchars($blockId); ?>">
                        <div class="ay-col completed">
                            <h4>Completed Courses (<?= count($completedList); ?>)</h4>
                            <?php if (empty($completedList)): ?>
                                <div class="ay-empty">No completed courses in this term.</div>
                            <?php else: ?>
                                <?php foreach ($completedList as $c): ?>
                                    <div class="ay-course-row">
                                        <div class="ay-course-code"><?= htmlspecialchars((string)($c['code'] ?? '')); ?></div>
                                        <div class="ay-course-title"><?= htmlspecialchars((string)($c['title'] ?? '')); ?></div>
                                        <span class="ay-course-badge ay-badge-passed"><?= htmlspecialchars((string)($c['grade'] ?? 'Passed')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="ay-col uncomplete">
                            <h4>Uncompleted Courses (<?= count($uncompleteList); ?>)</h4>
                            <?php if (empty($uncompleteList)): ?>
                                <div class="ay-empty">All courses completed for this term.</div>
                            <?php else: ?>
                                <?php foreach ($uncompleteList as $c): ?>
                                    <?php
                                        $reason = strtoupper((string)($c['reason'] ?? 'Not Yet Taken'));
                                        $badgeClass = 'ay-badge-pending';
                                        if ($reason === 'FAILED') {
                                            $badgeClass = 'ay-badge-failed';
                                        } elseif ($reason === 'INC') {
                                            $badgeClass = 'ay-badge-inc';
                                        } elseif ($reason === 'DROPPED') {
                                            $badgeClass = 'ay-badge-dropped';
                                        }
                                    ?>
                                    <div class="ay-course-row">
                                        <div class="ay-course-code"><?= htmlspecialchars((string)($c['code'] ?? '')); ?></div>
                                        <div class="ay-course-title"><?= htmlspecialchars((string)($c['title'] ?? '')); ?></div>
                                        <span class="ay-course-badge <?= htmlspecialchars($badgeClass); ?>"><?= htmlspecialchars((string)($c['reason'] ?? 'Not Yet Taken')); ?></span>
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
        const studyPlanStudentId = <?= json_encode($studentId); ?>;

        function moveCourseTerm(courseCode, selectId) {
            const selectEl = document.getElementById(selectId);
            if (!selectEl) {
                alert('Move control not found. Please reload the page.');
                return;
            }

            const chosen = String(selectEl.value || '').trim();
            if (!chosen || chosen.indexOf('|') === -1) {
                alert('Please select a valid target term.');
                return;
            }

            const parts = chosen.split('|');
            const targetYear = parts[0] || '';
            const targetSemester = parts[1] || '';

            fetch('save_study_plan_override.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    student_id: studyPlanStudentId,
                    course_code: courseCode,
                    target_year: targetYear,
                    target_semester: targetSemester
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) {
                    alert(data.message || 'Failed to update study plan term.');
                    return;
                }
                window.location.reload();
            })
            .catch(function(err) {
                alert('Network error: ' + err.message);
            });
        }

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
    </script>
</body>
</html>
<?php $conn->close(); ?>

