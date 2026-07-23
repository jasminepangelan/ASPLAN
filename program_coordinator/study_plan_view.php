<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/study_plan_override_service.php';
require_once __DIR__ . '/../includes/study_plan_course_addition_service.php';

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
        $sortGroup = 3;
        if (!empty($course['needs_retake'])) {
            $sortGroup = 0;
        } elseif (!empty($course['cross_registered'])) {
            $sortGroup = 1;
        } elseif (!empty($course['forced_added'])) {
            $sortGroup = 2;
        }
        $indexed[] = [
            'index' => $index,
            'sort_group' => $sortGroup,
            'course' => $course,
        ];
    }

    usort($indexed, function ($a, $b) {
        if ($a['sort_group'] === $b['sort_group']) {
            return $a['index'] <=> $b['index'];
        }

        return $a['sort_group'] <=> $b['sort_group'];
    });

    return array_column($indexed, 'course');
}

function pcIsNonCreditStudyPlanCourse(array $course): bool
{
    $courseCode = strtoupper(trim((string)($course['code'] ?? $course['course_code'] ?? '')));
    $courseTitle = strtoupper(trim((string)($course['title'] ?? $course['course_title'] ?? '')));

    return $courseCode === 'CVSU 101'
        || strpos($courseTitle, 'NON-CREDIT') !== false
        || strpos($courseTitle, 'NON CREDIT') !== false;
}

function pcGetCountedStudyPlanUnits(array $course): float
{
    if (pcIsNonCreditStudyPlanCourse($course)) {
        return 0.0;
    }

    if (isset($course['units'])) {
        return (float)($course['units'] ?? 0);
    }

    return (float)($course['credit_unit_lec'] ?? 0) + (float)($course['credit_unit_lab'] ?? 0);
}

function pcFormatStudyPlanMeasure($value): string
{
    $number = (float)($value ?? 0);
    if (abs($number - round($number)) < 0.001) {
        return number_format($number, 0);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function pcGetStudyPlanCourseBreakdown(array $course): array
{
    return [
        'credit_unit_lec' => (float)($course['credit_unit_lec'] ?? $course['units'] ?? 0),
        'credit_unit_lab' => (float)($course['credit_unit_lab'] ?? 0),
        'lect_hrs_lec' => (float)($course['lect_hrs_lec'] ?? $course['contact_hrs_lec'] ?? 0),
        'lect_hrs_lab' => (float)($course['lect_hrs_lab'] ?? $course['contact_hrs_lab'] ?? 0),
    ];
}

function pcSumStudyPlanCourseBreakdowns(array $courses): array
{
    $totals = [
        'credit_unit_lec' => 0.0,
        'credit_unit_lab' => 0.0,
        'lect_hrs_lec' => 0.0,
        'lect_hrs_lab' => 0.0,
    ];

    foreach ($courses as $course) {
        if (pcIsNonCreditStudyPlanCourse((array)$course)) {
            continue;
        }

        $breakdown = pcGetStudyPlanCourseBreakdown((array)$course);
        foreach ($totals as $key => $value) {
            $totals[$key] += (float)($breakdown[$key] ?? 0);
        }
    }

    return $totals;
}

function pcDescribeStudyPlanCourseReason(array $course, array $termSourceContext = []): string
{
    $reasons = [];

    if (!empty($course['forced_added'])) {
        $forcedReason = trim((string)($course['forced_reason'] ?? ''));
        $reasons[] = $forcedReason !== ''
            ? $forcedReason
            : 'This course was manually added to the plan.';
    }

    if (!empty($course['needs_retake'])) {
        $reasons[] = 'This is a back/failed subject, so it is prioritized early.';
    }

    if (!empty($course['cross_registered'])) {
        $crossRegSourceProgram = trim((string)($course['cross_reg_source_program'] ?? ''));
        $reasons[] = $crossRegSourceProgram !== ''
            ? 'This course was cross-registered from ' . $crossRegSourceProgram . '.'
            : 'This course was cross-registered to keep your load balanced.';
    }

    if (!empty($course['moved_override'])) {
        $reasons[] = 'This course was moved manually for advisory or planning reasons.';
    }

    if (!empty($termSourceContext['is_relocated'])) {
        $nonDisplaySummary = trim((string)($termSourceContext['non_display_summary'] ?? ''));
        $sourceSummary = trim((string)($termSourceContext['source_summary'] ?? ''));
        if ($nonDisplaySummary !== '' && $sourceSummary !== '') {
            $reasons[] = 'The planner relocated this course from ' . $nonDisplaySummary . ' and now shows it with ' . $sourceSummary . '.';
        } elseif ($sourceSummary !== '') {
            $reasons[] = 'The planner placed this course here after checking the curriculum timeline (' . $sourceSummary . ').';
        }
    }

    if (empty($reasons)) {
        $reasons[] = 'This course matches the curriculum slot for this term and fits the current plan after prerequisites and unit limits were checked.';
    }

    return implode(' ', $reasons);
}

function pcDescribeStudyPlanCourseReasonTooltip(array $course, array $termSourceContext = []): string
{
    $reasons = [];
    if (!empty($course['forced_added'])) {
        $forcedReason = trim((string)($course['forced_reason'] ?? ''));
        $reasons[] = $forcedReason !== '' ? 'Manually added: ' . $forcedReason : 'Manually added to the study plan by adviser';
    }
    if (!empty($course['needs_retake'])) {
        $reasons[] = 'Requires retake (previous attempt failed) — prioritized earlier to allow progression';
    }
    if (!empty($course['cross_registered'])) {
        $src = trim((string)($course['cross_reg_source_program'] ?? ''));
        $reasons[] = $src !== '' ? 'Cross-registered from ' . $src . ' to balance program load' : 'Cross-registered to balance load across programs';
    }
    if (!empty($course['moved_override'])) {
        $reasons[] = 'Manually moved by adviser for scheduling or curriculum reasons';
    }
    if (!empty($termSourceContext['is_relocated'])) {
        $nonDisplaySummary = trim((string)($termSourceContext['non_display_summary'] ?? ''));
        $sourceSummary = trim((string)($termSourceContext['source_summary'] ?? ''));
        if ($nonDisplaySummary !== '' && $sourceSummary !== '') {
            $reasons[] = 'Relocated due to curriculum timeline adjustment';
        } elseif ($sourceSummary !== '') {
            $reasons[] = 'Placed here after curriculum timeline review (' . $sourceSummary . ')';
        }
    }

    if (!empty($reasons)) {
        $why = 'Recommended here because ' . implode(' · ', $reasons) . '.';
    } else {
        $why = 'Recommended here as it matches the curriculum slot and prerequisites for this term.';
    }

    $text = $why;
    if (mb_strlen($text) > 400) $text = mb_substr($text, 0, 397) . '...';
    return $text;
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
$courseAdditionMap = spcaLoadCourseAdditionMap($conn, $studentId);

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
        isset($futureTermKeys[$termKey])
    ) {
        continue;
    }

    $courses = [];
    foreach (($termData['completed'] ?? []) as $course) {
        $courses[] = [
            'code' => $course['code'] ?? '',
            'title' => $course['title'] ?? '',
            'units' => $course['units'] ?? 0,
            'credit_unit_lec' => $course['credit_unit_lec'] ?? $course['units'] ?? 0,
            'credit_unit_lab' => $course['credit_unit_lab'] ?? 0,
            'lect_hrs_lec' => $course['lect_hrs_lec'] ?? 0,
            'lect_hrs_lab' => $course['lect_hrs_lab'] ?? 0,
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
            'credit_unit_lec' => $course['credit_unit_lec'] ?? $course['units'] ?? 0,
            'credit_unit_lab' => $course['credit_unit_lab'] ?? 0,
            'lect_hrs_lec' => $course['lect_hrs_lec'] ?? 0,
            'lect_hrs_lab' => $course['lect_hrs_lab'] ?? 0,
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
            return $carry + pcGetCountedStudyPlanUnits((array)$course);
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
            $baseTermUnits += pcGetCountedStudyPlanUnits((array)$baseCourse);
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
        $courseCountedUnits = pcGetCountedStudyPlanUnits((array)$course);

        if ($courseCode !== '' && isset($overrideMap[$courseCode])) {
            $candidateYear = $overrideMap[$courseCode]['year'];
            $candidateSemester = $overrideMap[$courseCode]['semester'];
            $baseIsMidyear = strcasecmp(trim((string)$baseSemester), 'Mid Year') === 0;
            $allowOverride = true;

            if ($baseIsMidyear) {
                $allowOverride = ($candidateYear === $baseYear)
                    && (strcasecmp(trim((string)$candidateSemester), trim((string)$baseSemester)) === 0);
            }

            if ($allowOverride) {
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
        }

        $targetKey = $targetYear . '|' . $targetSemester;
        if (!isset($futureTermBuckets[$targetKey])) {
            $futureTermBuckets[$targetKey] = [
                'year' => $targetYear,
                'semester' => $targetSemester,
                'courses' => [],
                'total_units' => 0.0,
                'max_units' => null,
                'skipped' => false,
                'skip_reason' => '',
            ];
        }

        $course['original_year'] = $baseYear;
        $course['original_semester'] = $baseSemester;
        $course['moved_override'] = $isMoved;

        $futureTermBuckets[$targetKey]['courses'][] = $course;
        $futureTermBuckets[$targetKey]['total_units'] += $courseCountedUnits;
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
$plannedRemainingCourseCodes = [];
foreach ($studyPlan as $term) {
    if (empty($term['skipped']) && !empty($term['courses'])) {
        $lastPlannedTerm = $term;
        $remainingSemesters++;

        foreach (($term['courses'] ?? []) as $courseCode => $course) {
            $code = strtoupper(trim((string)($course['code'] ?? $courseCode)));
            $units = (float)($course['units'] ?? 0);
            if ($code !== '' && $units > 0) {
                $plannedRemainingCourseCodes[$code] = true;
            }
        }
    }
}
$planCoverage = is_array($stats['plan_coverage'] ?? null) ? $stats['plan_coverage'] : [];
$coveragePlannedCodes = $planCoverage['planned_remaining_course_codes'] ?? ($stats['planned_remaining_course_codes'] ?? []);
if (is_array($coveragePlannedCodes) && !empty($coveragePlannedCodes)) {
    $plannedRemainingCourseCodes = [];
    foreach ($coveragePlannedCodes as $coverageCode) {
        $code = strtoupper(trim((string)$coverageCode));
        if ($code !== '') {
            $plannedRemainingCourseCodes[$code] = true;
        }
    }
}
$unresolvedCourses = is_array($planCoverage['unresolved_courses'] ?? null)
    ? $planCoverage['unresolved_courses']
    : (is_array($stats['unresolved_courses'] ?? null) ? $stats['unresolved_courses'] : []);
$unscheduledRemainingCourses = isset($planCoverage['unscheduled_remaining_courses'])
    ? max(0, (int)$planCoverage['unscheduled_remaining_courses'])
    : max(0, (int)($stats['remaining_courses'] ?? 0) - count($plannedRemainingCourseCodes));
$hasUnresolvedPlan = $unscheduledRemainingCourses > 0 || !empty($unresolvedCourses);

// Tentative projection when unresolved courses remain
$tentativeEstimatedGraduation = null;
$estimatedGraduation = null;
$graduationSchoolYear = '';
if ($hasUnresolvedPlan && !empty($stats['remaining_courses']) && (int)$stats['remaining_courses'] > 0) {
    $remainingCourses = (int)$stats['remaining_courses'];
    $plannedCount = count($plannedRemainingCourseCodes);

    if ($remainingSemesters > 0 && $plannedCount > 0) {
        $avgCoursesPerSem = max(1, (int)ceil($plannedCount / max(1, $remainingSemesters)));
    } elseif (is_array($completedTerms) && count($completedTerms) > 0) {
        $avgCoursesPerSem = max(1, (int)ceil(((int)($stats['completed_courses'] ?? 0)) / max(1, count($completedTerms))));
    } else {
        $avgCoursesPerSem = 4;
    }

    $semestersNeeded = (int)ceil($remainingCourses / max(1, $avgCoursesPerSem));

    $startTerm = $lastPlannedTerm ?: ((count($completedTerms) > 0) ? end($completedTerms) : null);
    if ($startTerm) {
        $startYear = (int)preg_replace('/[^0-9]/', '', (string)($startTerm['year'] ?? date('Y')));
        $startSemester = (string)($startTerm['semester'] ?? '1st Sem');
    } else {
        $startYear = (int)date('Y');
        $startSemester = '1st Sem';
    }

    $semesterIndexMap = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
    $indexToSemester = [1 => '1st Sem', 2 => '2nd Sem', 3 => 'Mid Year'];

    $startIndex = $semesterIndexMap[$startSemester] ?? 1;
    $totalIndex = $startIndex + $semestersNeeded;
    $yearIncrement = (int)floor(($totalIndex - 1) / 3);
    $targetIndex = (($totalIndex - 1) % 3) + 1;
    $targetSemester = $indexToSemester[$targetIndex] ?? '1st Sem';
    $targetYear = $startYear + $yearIncrement;

    $tentativeEstimatedGraduation = $targetSemester . ', ' . $targetYear;
}
if ($lastPlannedTerm && !$hasUnresolvedPlan) {
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
            table-layout: fixed;
            border: 1px solid #e4ebe3;
            border-left: none;
            border-right: none;
            background: #fff;
        }
        th, td {
            padding: 6px 12px;
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
        th:nth-child(2), td:nth-child(2) { width: auto; text-align: left; }
        th:nth-child(3), td:nth-child(3) { width: 72px; text-align: center; }
        th:nth-child(4), td:nth-child(4) { width: 72px; text-align: center; }
        th:nth-child(5), td:nth-child(5) { width: 72px; text-align: center; }
        th:nth-child(6), td:nth-child(6) { width: 72px; text-align: center; }
        th:nth-child(7), td:nth-child(7) { width: 120px; text-align: center; }
        th:nth-child(8), td:nth-child(8) { width: 95px; text-align: center; }
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
            width: 100%;
            max-width: none;
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
        .plan-tag-added { background: #1565c0; }
        .plan-tag-button {
            border: none;
            cursor: pointer;
            font-family: inherit;
            line-height: 1.2;
        }
        .plan-tag-button:disabled {
            opacity: 0.7;
            cursor: wait;
        }
        .course-title-stack {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }
        .course-tag-row {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            align-items: center;
        }
        .course-title-line {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            min-width: 0;
            line-height: 1.3;
        }
        .course-title-text {
            min-width: 0;
            word-break: break-word;
        }
        .sp-info {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #e0f2f1;
            color: #00695c;
            border: 1px solid #c8e6c9;
            font-weight: 700;
            font-size: 12px;
            line-height: 18px;
            cursor: pointer;
            margin-left: 8px;
        }
        .sp-info:focus { outline: 2px solid rgba(0,105,96,0.15); }
        .sp-tooltip {
            position: absolute;
            z-index: 9999;
            background: #fff;
            border: 1px solid #cfd8dc;
            padding: 10px 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            border-radius: 8px;
            font-size: 13px;
            color: #263238;
            max-width: 420px;
            word-break: break-word;
            line-height: 1.4;
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
            #ay-modal-overlay,
            .sp-info {
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
    <script>
        const originalPrint = window.print;
        window.print = function() {
            const origTitle = document.title;
            const studentNo = "<?= addslashes(htmlspecialchars($student['student_number'] ?? '')) ?>";
            const lastName = "<?= addslashes(htmlspecialchars(str_replace(' ', '', $student['last_name'] ?? ''))) ?>";
            if (studentNo && lastName) {
                document.title = studentNo + '_' + lastName;
            }
            originalPrint.apply(window);
            document.title = origTitle;
        };
    </script>
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
                    <!-- Program Shift Requests removed from coordinator UI -->
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
                <?php if ($estimatedGraduation || $tentativeEstimatedGraduation): ?>
                <div class="stat-card">
                    <div class="stat-value compact"><?= htmlspecialchars($estimatedGraduation ?: $tentativeEstimatedGraduation); ?></div>
                    <?php if ($graduationSchoolYear !== '' && !$hasUnresolvedPlan): ?><div class="stat-note"><?= htmlspecialchars($graduationSchoolYear); ?></div><?php endif; ?>
                    <div class="stat-sub"><?= $hasUnresolvedPlan ? 'Projected Completion (Tentative)' : 'Projected Completion' ?></div>
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

            <?php if ($hasUnresolvedPlan): ?>
            <div style="margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); border-left: 4px solid #ef6c00; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">&#9888;&#65039;</span>
                    <strong style="font-size: 15px; color: #e65100;">Incomplete Study Plan</strong>
                </div>
                <p style="margin: 0; font-size: 13px; color: #333;">
                    This plan schedules <strong><?= count($plannedRemainingCourseCodes) ?></strong> of <strong><?= (int)($stats['remaining_courses'] ?? 0); ?></strong> remaining courses.
                    <strong><?= $unscheduledRemainingCourses; ?> course<?= $unscheduledRemainingCourses === 1 ? '' : 's'; ?></strong> remain<?= $unscheduledRemainingCourses === 1 ? 's' : ''; ?> unresolved.
                    <?php if ($tentativeEstimatedGraduation): ?>A tentative projected completion is <strong><?= htmlspecialchars($tentativeEstimatedGraduation) ?></strong> (tentative).<?php else: ?>Projected completion is hidden.<?php endif; ?>
                </p>
                <?php if (!empty($unresolvedCourses)): ?>
                <ul style="margin: 8px 0 0 18px; font-size: 13px; color: #333;">
                    <?php foreach (array_slice($unresolvedCourses, 0, 8) as $unresolved): ?>
                    <li>
                        <strong><?= htmlspecialchars((string)($unresolved['code'] ?? '')); ?></strong>
                        <?= htmlspecialchars((string)($unresolved['reason'] ?? 'Unresolved')); ?>
                        <?php if (!empty($unresolved['blockers']) && is_array($unresolved['blockers'])): ?>
                            &mdash; blocker<?= count($unresolved['blockers']) === 1 ? '' : 's'; ?>: <?= htmlspecialchars(implode(', ', $unresolved['blockers'])); ?>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($remainingSemesters > 0): ?>
            <div style="margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border-left: 4px solid #4CAF50; border-radius: 4px;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                    <span style="font-size: 18px;">&#128202;</span>
                    <strong style="font-size: 15px; color: #2e7d32;">Timeline Optimization Summary</strong>
                </div>
                <p style="margin: 0; font-size: 13px; color: #333;">
                    <?php if ($hasUnresolvedPlan): ?>
                    This plan currently schedules <strong><?= count($plannedRemainingCourseCodes); ?></strong> of <strong><?= (int)($stats['remaining_courses'] ?? 0); ?></strong> remaining courses.
                    <strong style="color: #e65100;"><?= $unscheduledRemainingCourses; ?> course<?= $unscheduledRemainingCourses === 1 ? '' : 's'; ?></strong> still need<?= $unscheduledRemainingCourses === 1 ? 's' : ''; ?> adviser review or curriculum action.
                    <?php else: ?>
                    This plan completes all remaining courses in <strong><?= $remainingSemesters ?> semester<?= $remainingSemesters > 1 ? 's' : '' ?></strong>,
                    keeping the student on track based on the current curriculum and eligible course sequencing.
                    <?php endif; ?>
                </p>
                <?php if ($estimatedGraduation || $tentativeEstimatedGraduation): ?>
                <p style="margin: 8px 0 0 0; font-size: 13px; color: #206018; font-weight: 600;">
                    Projected Completion: <?= htmlspecialchars($estimatedGraduation ?: $tentativeEstimatedGraduation) ?><?= (!$hasUnresolvedPlan && $graduationSchoolYear !== '') ? ' (' . htmlspecialchars($graduationSchoolYear) . ')' : '' ?>
                    <?= $hasUnresolvedPlan ? '<span style="color:#e65100; font-weight:600;"> (Tentative)</span>' : '' ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div style="margin-top: 15px; padding: 12px; background: rgba(32, 96, 24, 0.05); border-left: 4px solid #4CAF50; border-radius: 4px;">
                <p style="margin: 0 0 8px 0; font-size: 13px; color: #333;">
                    <strong>Automated Study Plan Generator (CSP + Greedy)</strong>
                </p>
                <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #333; line-height: 1.5;">
                    <li>Back or failed courses are prioritized first, with lower year levels taken before higher year levels.</li>
                    <li>Prerequisites must be cleared before a course can be scheduled.</li>
                    <li>Max units are enforced per term using the checklist's curriculum term limits.</li>
                    <li>Cross-registration is allowed when an equivalent offering exists in another program for the same semester.</li>
                    <li>Semester offering rules are enforced, so a course is only scheduled in its allowed semester.</li>
                    <li>Mid-year or summer-only courses are locked to their proper term and are not moved into regular semesters.</li>
                    <li>Standing rules are enforced, so courses requiring 2nd Year Standing, 3rd Year Standing, or graduating standing stay blocked until that standing is met.</li>
                    <li>Retention policy is applied, which can reduce load limits or skip terms depending on warning/probation/disqualification status.</li>
                    <li>If the student is disqualified twice, or if any course has been failed 3 or more times, generation can stop.</li>
                    <li>No Grade, INC, and dropped entries are treated as back subjects instead of being ignored.</li>
                    <li>Regular students can follow the exact curriculum sequence instead of the optimized reorder path when they have no active back subjects or irregular constraints.</li>
                </ul>
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
                                        <th rowspan="2">Course Code</th>
                                        <th rowspan="2">Course Title</th>
                                        <th colspan="2">Credit Unit</th>
                                        <th colspan="2">Contact Hrs</th>
                                        <th rowspan="2">Prerequisite</th>
                                        <th rowspan="2">Action</th>
                                    </tr>
                                    <tr>
                                        <th>Lec</th>
                                        <th>Lab</th>
                                        <th>Lec</th>
                                        <th>Lab</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php $termCourses = pcSortTaggedCoursesLast((array)($term['courses'] ?? [])); ?>
                                <?php $termBreakdownTotals = pcSumStudyPlanCourseBreakdowns($termCourses); ?>
                                <?php foreach ($termCourses as $course): ?>
                                    <?php
                                        $prerequisite = trim((string)($course['prerequisite'] ?? ''));
                                        if ($prerequisite === '') {
                                            $prerequisite = 'None';
                                        }
                                        $isNonCredit = pcIsNonCreditStudyPlanCourse((array)$course);
                                        $breakdown = pcGetStudyPlanCourseBreakdown((array)$course);
                                        $status = trim((string)($course['status'] ?? ''));
                                        $statusVariant = trim((string)($course['status_variant'] ?? ''));
                                        $statusBadgeClass = 'plan-tag-to-add';
                                        if ($statusVariant === 'passed' || $statusVariant === 'credited') {
                                            $statusBadgeClass = 'plan-tag-completed';
                                        } elseif ($statusVariant === 'failed') {
                                            $statusBadgeClass = 'plan-tag-retake';
                                        } elseif ($statusVariant === 'inc' || $statusVariant === 'dropped') {
                                            $statusBadgeClass = 'plan-tag-cross';
                                        }
                                        $crossRegSourceProgram = trim((string)($course['cross_reg_source_program'] ?? ''));
                                        $crossRegTooltip = $crossRegSourceProgram !== '' ? 'Cross-registered from: ' . $crossRegSourceProgram : 'Cross-registered course';
                                        $isActionRequired = !empty($course['needs_retake']) || !empty($course['cross_registered']);
                                        $courseAdditionKey = spcaBuildCourseAdditionKey((string)($course['code'] ?? ''), (string)($term['year'] ?? ''), (string)($term['semester'] ?? ''));
                                        $isAddedConfirmed = $isActionRequired && !empty($courseAdditionMap[$courseAdditionKey]);
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string)($course['code'] ?? '')); ?></td>
                                        <td>
                                            <div class="course-title-stack">
                                                <div class="course-title-line">
                                                    <span class="course-title-text"><?= htmlspecialchars((string)($course['title'] ?? '')); ?></span>
                                                    <div class="course-tag-row">
                                                        <?php if ($status !== ''): ?>
                                                            <span class="plan-tag <?= htmlspecialchars($statusBadgeClass); ?>"><?= htmlspecialchars(strtoupper($status)); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($course['needs_retake'])): ?><span class="plan-tag plan-tag-retake">Retake</span><?php endif; ?>
                                                        <?php if (!empty($course['cross_registered'])): ?><span class="plan-tag plan-tag-cross" title="<?= htmlspecialchars($crossRegTooltip) ?>" aria-label="<?= htmlspecialchars($crossRegTooltip) ?>">Cross-Reg</span><?php endif; ?>
                                                        <?php if ($isActionRequired): ?>
                                                            <button
                                                                type="button"
                                                                class="plan-tag <?= $isAddedConfirmed ? 'plan-tag-added' : 'plan-tag-to-add' ?> plan-tag-button"
                                                                data-added="<?= $isAddedConfirmed ? '1' : '0' ?>"
                                                                onclick="toggleCourseAdded(this, '<?= htmlspecialchars((string)($course['code'] ?? ''), ENT_QUOTES); ?>', '<?= htmlspecialchars((string)($term['year'] ?? ''), ENT_QUOTES); ?>', '<?= htmlspecialchars((string)($term['semester'] ?? ''), ENT_QUOTES); ?>')"
                                                            ><?= $isAddedConfirmed ? 'Added' : 'Prioritize' ?></button>
                                                        <?php endif; ?>
                                                        <?php if (!empty($course['moved_override'])): ?><span class="plan-tag plan-tag-moved">Moved</span><?php endif; ?>
                                                    </div>
                                                    <button type="button" class="sp-info" aria-label="Why shown" data-reason="<?= htmlspecialchars(pcDescribeStudyPlanCourseReasonTooltip((array)$course, (array)($term_source_context ?? [])), ENT_QUOTES) ?>">i</button>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= $isNonCredit ? '(' . pcFormatStudyPlanMeasure($breakdown['credit_unit_lec']) . ')' : pcFormatStudyPlanMeasure($breakdown['credit_unit_lec']) ?></td>
                                        <td><?= $isNonCredit ? '(' . pcFormatStudyPlanMeasure($breakdown['credit_unit_lab']) . ')' : pcFormatStudyPlanMeasure($breakdown['credit_unit_lab']) ?></td>
                                        <td><?= $isNonCredit ? '(' . pcFormatStudyPlanMeasure($breakdown['lect_hrs_lec']) . ')' : pcFormatStudyPlanMeasure($breakdown['lect_hrs_lec']) ?></td>
                                        <td><?= $isNonCredit ? '(' . pcFormatStudyPlanMeasure($breakdown['lect_hrs_lab']) . ')' : pcFormatStudyPlanMeasure($breakdown['lect_hrs_lab']) ?></td>
                                        <td><?= htmlspecialchars($prerequisite); ?></td>
                                        <td>
                                            <?php if (!$isCompletedTerm && !$isPartialTerm): ?>
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
                                                    $originalSemester = trim((string)($course['original_semester'] ?? $currentSemester));
                                                    $lockedMidyear = strcasecmp($originalSemester, 'Mid Year') === 0;
                                                ?>
                                                <?php if ($lockedMidyear): ?>
                                                    <span style="color:#64748b;font-size:12px;font-weight:600;">Locked (Mid Year)</span>
                                                <?php else: ?>
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
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#64748b;font-size:12px;font-weight:600;">No Action</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="2" style="text-align: right;"><strong>TOTAL</strong></td>
                                        <td><strong><?= pcFormatStudyPlanMeasure($termBreakdownTotals['credit_unit_lec']) ?></strong></td>
                                        <td><strong><?= pcFormatStudyPlanMeasure($termBreakdownTotals['credit_unit_lab']) ?></strong></td>
                                        <td><strong><?= pcFormatStudyPlanMeasure($termBreakdownTotals['lect_hrs_lec']) ?></strong></td>
                                        <td><strong><?= pcFormatStudyPlanMeasure($termBreakdownTotals['lect_hrs_lab']) ?></strong></td>
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
        const studyPlanCourseAdditionEndpoint = <?= json_encode($isAdmin ? '../program_coordinator/save_study_plan_course_addition.php' : 'save_study_plan_course_addition.php'); ?>;
        const studyPlanOverrideEndpoint = <?= json_encode($isAdmin ? '../program_coordinator/save_study_plan_override.php' : 'save_study_plan_override.php'); ?>;

        function applyCourseAddedButtonState(buttonEl, isAdded) {
            buttonEl.dataset.added = isAdded ? '1' : '0';
            buttonEl.textContent = isAdded ? 'Added' : 'Prioritize';
            buttonEl.classList.toggle('plan-tag-added', isAdded);
            buttonEl.classList.toggle('plan-tag-to-add', !isAdded);
        }

        function toggleCourseAdded(buttonEl, courseCode, targetYear, targetSemester) {
            if (!buttonEl) {
                return;
            }

            const desiredAdded = String(buttonEl.dataset.added || '0') !== '1';
            buttonEl.disabled = true;

            fetch(studyPlanCourseAdditionEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    student_id: studyPlanStudentId,
                    course_code: courseCode,
                    target_year: targetYear,
                    target_semester: targetSemester,
                    added: desiredAdded
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) {
                    alert(data.message || 'Failed to update added state.');
                    return;
                }

                applyCourseAddedButtonState(buttonEl, !!data.added);
            })
            .catch(function(err) {
                alert('Network error: ' + err.message);
            })
            .finally(function() {
                buttonEl.disabled = false;
            });
        }

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

            fetch(studyPlanOverrideEndpoint, {
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

        // Tooltip for 'Why shown' info buttons
        (function() {
            function hideTooltip() { const t = document.querySelector('.sp-tooltip'); if (t) t.remove(); }
            function showTooltip(button) {
                hideTooltip();
                const reason = button.getAttribute('data-reason') || '';
                if (!reason) return;
                const tooltip = document.createElement('div'); tooltip.className = 'sp-tooltip'; tooltip.textContent = reason; document.body.appendChild(tooltip);
                const rect = button.getBoundingClientRect(); const top = rect.top + window.scrollY + rect.height + 8; let left = rect.left + window.scrollX;
                const maxRight = window.scrollX + window.innerWidth - 20; if (left + tooltip.offsetWidth > maxRight) left = Math.max(window.scrollX + 10, maxRight - tooltip.offsetWidth);
                tooltip.style.top = top + 'px'; tooltip.style.left = left + 'px';
                setTimeout(function() { document.addEventListener('click', outsideHandler); }, 10);
                function outsideHandler(e) { if (!tooltip.contains(e.target) && e.target !== button) { hideTooltip(); document.removeEventListener('click', outsideHandler); } }
            }
            document.addEventListener('click', function(e) { const btn = e.target.closest('.sp-info'); if (btn) { e.preventDefault(); showTooltip(btn); } });
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') hideTooltip(); });
        })();

	</script>
</body>
</html>
<?php $conn->close(); ?>

