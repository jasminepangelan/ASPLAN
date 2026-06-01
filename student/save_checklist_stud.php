<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/checklist_term_lock_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/generate_study_plan.php';
header('Content-Type: application/json');

function csStudChecklistIsApprovedRemarkLocal($remark): bool
{
    $normalized = strtoupper(trim((string) $remark));
    if ($normalized === '') {
        return false;
    }

    if (strpos($normalized, 'APPROVE') !== false) {
        return true;
    }

    return strpos($normalized, 'CREDITED') !== false;
}

function csStudChecklistNormalizeCourseTokenLocal($value): string
{
    $value = strtoupper(trim((string) $value));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/^([A-Z]{2,})(\d+[A-Z]*)$/', '$1 $2', $value);
    $value = preg_replace('/^([A-Z]{2,}(?:\s+[A-Z]{1,})?)[\s-]+(\d+[A-Z]*)$/', '$1 $2', $value);
    return trim((string) $value);
}

function csStudChecklistIsPassingFinalGradeLocal($grade): bool
{
    $normalized = strtoupper(trim((string) $grade));
    if ($normalized === 'S' || $normalized === 'PASSED') {
        return true;
    }

    if (is_numeric($grade)) {
        $numeric_grade = (float) $grade;
        return $numeric_grade >= 1.0 && $numeric_grade <= 3.0;
    }

    return false;
}

function csStudChecklistResolveEffectiveApprovedGradeLocal(array $row): ?string
{
    $attempts = [
        3 => [
            'grade' => trim((string) ($row['final_grade_3'] ?? '')),
            'remark' => (string) ($row['evaluator_remarks_3'] ?? ''),
        ],
        2 => [
            'grade' => trim((string) ($row['final_grade_2'] ?? '')),
            'remark' => (string) ($row['evaluator_remarks_2'] ?? ''),
        ],
        1 => [
            'grade' => trim((string) ($row['final_grade'] ?? '')),
            'remark' => (string) ($row['evaluator_remarks'] ?? ''),
        ],
    ];

    foreach ([3, 2, 1] as $slot) {
        $grade = $attempts[$slot]['grade'] ?? '';
        if ($grade === '' || strtoupper($grade) === 'NO GRADE') {
            continue;
        }

        if (csStudChecklistIsApprovedRemarkLocal($attempts[$slot]['remark'] ?? '')) {
            return $grade;
        }
    }

    return null;
}

function csStudChecklistParsePrerequisitesLocal($prereq_string): array
{
    $looksNonCourse = static function ($value): bool {
        $upper = strtoupper(trim((string) $value));
        if ($upper === '') {
            return true;
        }

        foreach ([
            'YEAR',
            'STANDING',
            'INCOMING',
            '%',
            'ALL SUBJECT',
            'ALL MAJOR',
            'GRADUATING',
            'PROF ED',
            'TOTAL UNIT',
            'TOTAL UNITS',
            'HS ',
            'HIGH SCHOOL',
            'GWA',
            'AVERAGE GRADE',
        ] as $fragment) {
            if (strpos($upper, $fragment) !== false) {
                return true;
            }
        }

        return false;
    };

    $prereq_string = trim((string) $prereq_string);
    if ($prereq_string === '' || strtoupper($prereq_string) === 'NONE') {
        return [];
    }

    $normalized = str_replace(["\r\n", "\r", "\n"], ', ', $prereq_string);
    $normalized = preg_replace('/\s*[;\/]\s*/', ', ', $normalized);
    $normalized = preg_replace('/\s+(?:AND|and)\s+/', ', ', $normalized);
    $normalized = preg_replace_callback(
        '/\b([A-Z]{2,}(?:\s+[A-Z]{1,})?[\s-]*\d+[A-Z]*)\s*(?:&|,)\s*((?:\d+[A-Z]*\s*(?:,|&)\s*)*\d+[A-Z]*)/i',
        static function ($m) {
            $first = csStudChecklistNormalizeCourseTokenLocal($m[1]);
            if ($first === '') {
                return $m[0];
            }

            $prefix = preg_replace('/\s+\d+[A-Z]*$/', '', $first);
            $tailParts = preg_split('/\s*(?:,|&)\s*/', trim((string) $m[2]));
            $expanded = [$first];
            foreach ($tailParts as $part) {
                $part = trim((string) $part);
                if ($part === '') {
                    continue;
                }
                $expanded[] = csStudChecklistNormalizeCourseTokenLocal($prefix . ' ' . $part);
            }

            return implode(', ', array_filter($expanded));
        },
        $normalized
    );

    $segments = preg_split('/\s*,\s*/', (string) $normalized);
    $valid_prereqs = [];
    $seen = [];
    $last_prefix = '';

    foreach ($segments as $segment) {
        $segment = trim((string) $segment);
        if ($segment === '' || $looksNonCourse($segment)) {
            continue;
        }

        $matches = [];
        preg_match_all('/\b([A-Z]{2,}(?:\s+[A-Z]{1,})?[\s-]*\d+[A-Z]*)\b/i', $segment, $matches);

        $codes = [];
        if (!empty($matches[1])) {
            foreach ($matches[1] as $match) {
                $normalizedCode = csStudChecklistNormalizeCourseTokenLocal($match);
                if ($normalizedCode !== '') {
                    $codes[] = $normalizedCode;
                }
            }
        } elseif ($last_prefix !== '' && preg_match('/^\d+[A-Z]*$/i', $segment)) {
            $normalizedCode = csStudChecklistNormalizeCourseTokenLocal($last_prefix . ' ' . $segment);
            if ($normalizedCode !== '') {
                $codes[] = $normalizedCode;
            }
        }

        foreach ($codes as $code) {
            if (!preg_match('/^([A-Z]{2,}(?:\s+[A-Z]{1,})?)\s+\d+[A-Z]*$/', $code, $pm)) {
                continue;
            }

            $last_prefix = $pm[1];
            if (!isset($seen[$code])) {
                $seen[$code] = true;
                $valid_prereqs[] = $code;
            }
        }
    }

    return $valid_prereqs;
}

function csStudChecklistBuildRowKeyLocal(array $row): string
{
    $courseCode = csStudChecklistNormalizeCourseTokenLocal($row['course_code'] ?? '');
    if ($courseCode === '') {
        return '';
    }

    $courseTitle = strtoupper(trim((string)($row['course_title'] ?? '')));
    $year = strtoupper(trim((string)($row['year'] ?? '')));
    $semester = strtoupper(trim((string)($row['semester'] ?? '')));

    return implode('|', [$courseCode, $courseTitle, $year, $semester]);
}

function normalizeChecklistValue($value): string
{
    return trim((string) $value);
}

function resolveStudentAttemptRemarkLocal($incomingGrade, $existingGrade, $existingRemark): string
{
    $incoming = normalizeChecklistValue($incomingGrade);
    if ($incoming === '' || $incoming === 'No Grade') {
        return normalizeChecklistValue($existingRemark);
    }

    $currentGrade = normalizeChecklistValue($existingGrade);
    if ($currentGrade === $incoming) {
        $remark = normalizeChecklistValue($existingRemark);
        return $remark !== '' ? $remark : 'Pending';
    }

    return 'Pending';
}

function isLockedApprovedAttemptLocal($remark): bool
{
    $normalized = strtoupper(trim((string)$remark));
    return $normalized !== '' && strpos($normalized, 'APPROVE') !== false;
}

function resolveStudentSubmissionTargetSlotLocal(array $attempts, int $preferredSlot): int
{
    $preferredSlot = max(1, min(3, $preferredSlot));

    if (!isLockedApprovedAttemptLocal($attempts[$preferredSlot]['remark'] ?? '')) {
        return $preferredSlot;
    }

    for ($slot = $preferredSlot + 1; $slot <= 3; $slot++) {
        if (!isLockedApprovedAttemptLocal($attempts[$slot]['remark'] ?? '')) {
            return $slot;
        }
    }

    return $preferredSlot;
}

function applyIncomingStudentAttemptLocal(array &$attempts, int $preferredSlot, $incomingGrade): void
{
    $incoming = normalizeChecklistValue($incomingGrade);
    if ($incoming === '' || $incoming === 'No Grade') {
        if (!isLockedApprovedAttemptLocal($attempts[$preferredSlot]['remark'] ?? '')) {
            $attempts[$preferredSlot]['grade'] = '';
            $attempts[$preferredSlot]['remark'] = '';
        }
        return;
    }

    $preferredSlot = max(1, min(3, $preferredSlot));
    $existingGrade = normalizeChecklistValue($attempts[$preferredSlot]['grade'] ?? '');
    $existingRemark = normalizeChecklistValue($attempts[$preferredSlot]['remark'] ?? '');

    if ($existingGrade === $incoming) {
        $attempts[$preferredSlot]['remark'] = resolveStudentAttemptRemarkLocal($incoming, $existingGrade, $existingRemark);
        return;
    }

    $targetSlot = resolveStudentSubmissionTargetSlotLocal($attempts, $preferredSlot);
    $targetExistingGrade = normalizeChecklistValue($attempts[$targetSlot]['grade'] ?? '');
    $targetExistingRemark = normalizeChecklistValue($attempts[$targetSlot]['remark'] ?? '');

    $attempts[$targetSlot]['grade'] = $incoming;
    $attempts[$targetSlot]['remark'] = resolveStudentAttemptRemarkLocal($incoming, $targetExistingGrade, $targetExistingRemark);
}

function resolveStudentAttemptPayloadLocal(?array $existing, $grade1, $grade2, $grade3): array
{
    $attempts = [
        1 => [
            'grade' => normalizeChecklistValue($existing['final_grade'] ?? ''),
            'remark' => normalizeChecklistValue($existing['evaluator_remarks'] ?? ''),
        ],
        2 => [
            'grade' => normalizeChecklistValue($existing['final_grade_2'] ?? ''),
            'remark' => normalizeChecklistValue($existing['evaluator_remarks_2'] ?? ''),
        ],
        3 => [
            'grade' => normalizeChecklistValue($existing['final_grade_3'] ?? ''),
            'remark' => normalizeChecklistValue($existing['evaluator_remarks_3'] ?? ''),
        ],
    ];

    $incomingGrades = [
        1 => normalizeChecklistValue($grade1),
        2 => normalizeChecklistValue($grade2),
        3 => normalizeChecklistValue($grade3),
    ];

    foreach ($incomingGrades as $preferredSlot => $incomingGrade) {
        applyIncomingStudentAttemptLocal($attempts, $preferredSlot, $incomingGrade);
    }

    return [
        'final_grade' => $attempts[1]['grade'],
        'evaluator_remarks' => $attempts[1]['remark'],
        'final_grade_2' => $attempts[2]['grade'],
        'evaluator_remarks_2' => $attempts[2]['remark'],
        'final_grade_3' => $attempts[3]['grade'],
        'evaluator_remarks_3' => $attempts[3]['remark'],
    ];
}

function hasAnySavedAttemptLocal(array $payload): bool
{
    $grades = [
        normalizeChecklistValue($payload['final_grade'] ?? ''),
        normalizeChecklistValue($payload['final_grade_2'] ?? ''),
        normalizeChecklistValue($payload['final_grade_3'] ?? ''),
    ];

    foreach ($grades as $grade) {
        if ($grade !== '' && $grade !== 'No Grade') {
            return true;
        }
    }

    return false;
}

function buildComparableChecklistRecordLocal($record): array
{
    return [
        'final_grade' => normalizeChecklistValue($record['final_grade'] ?? ''),
        'evaluator_remarks' => normalizeChecklistValue($record['evaluator_remarks'] ?? ''),
        'professor_instructor' => normalizeChecklistValue($record['professor_instructor'] ?? ''),
        'final_grade_2' => normalizeChecklistValue($record['final_grade_2'] ?? ''),
        'evaluator_remarks_2' => normalizeChecklistValue($record['evaluator_remarks_2'] ?? ''),
        'final_grade_3' => normalizeChecklistValue($record['final_grade_3'] ?? ''),
        'evaluator_remarks_3' => normalizeChecklistValue($record['evaluator_remarks_3'] ?? ''),
    ];
}

function hasMeaningfulChecklistRecordLocal(array $record): bool
{
    foreach ($record as $value) {
        if (normalizeChecklistValue($value) !== '') {
            return true;
        }
    }

    return false;
}

function isCreditedLockedChecklistRecordLocal(?array $existing): bool
{
    if (empty($existing)) {
        return false;
    }

    $remarks = [
        normalizeChecklistValue($existing['evaluator_remarks'] ?? ''),
        normalizeChecklistValue($existing['evaluator_remarks_2'] ?? ''),
        normalizeChecklistValue($existing['evaluator_remarks_3'] ?? ''),
    ];

    foreach ($remarks as $remark) {
        if ($remark !== '' && strpos(strtolower($remark), 'credited') !== false) {
            return true;
        }
    }

    return strtolower(normalizeChecklistValue($existing['approved_by'] ?? '')) === 'shift_engine'
        || strtolower(normalizeChecklistValue($existing['submitted_by'] ?? '')) === 'shift_engine';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

    // Database connection
    $conn = getDBConnection();

    if (!isset($_POST['student_id']) || !isset($_POST['courses']) || !isset($_POST['final_grades']) || !isset($_POST['professor_instructors'])) {
        throw new Exception('Missing required data');
    }

    $student_id = $_POST['student_id'];
    $program_view = trim((string)($_POST['program_view'] ?? ''));
    $courses = $_POST['courses'];
    $course_row_keys = $_POST['course_row_keys'] ?? [];
    $final_grades = $_POST['final_grades'];
    $final_grades_2 = $_POST['final_grades_2'] ?? [];
    $final_grades_3 = $_POST['final_grades_3'] ?? [];
    $professor_instructors = $_POST['professor_instructors'];
    $evaluator_remarks = $_POST['evaluator_remarks'] ?? [];

    $studentProgramLabel = '';
    $progStmt = $conn->prepare('SELECT program FROM student_info WHERE student_number = ? LIMIT 1');
    if ($progStmt) {
        $progStmt->bind_param('s', $student_id);
        $progStmt->execute();
        $progResult = $progStmt->get_result();
        if ($progResult && ($progRow = $progResult->fetch_assoc())) {
            $studentProgramLabel = trim((string)($progRow['program'] ?? ''));
        }
        $progStmt->close();
    }

    $studyPlanGenerator = new StudyPlanGenerator($student_id, $studentProgramLabel);
    $effectiveTerm = $studyPlanGenerator->getEffectiveCurrentTerm();
    $effectiveTermKey = trim((string)($effectiveTerm['year'] ?? '')) . '|' . trim((string)($effectiveTerm['semester'] ?? ''));
    $currentEnrollmentTerm = ctlsLoadStudentCurrentEnrollmentTerm($conn, (string)$student_id);
    $currentEnrollmentTermKey = trim((string)($currentEnrollmentTerm['year'] ?? '')) . '|' . trim((string)($currentEnrollmentTerm['semester'] ?? ''));
    $termLockSource = !empty(trim($currentEnrollmentTermKey, '|')) ? $currentEnrollmentTerm : $effectiveTerm;

    // Ensure termLockSource values are normalized to match checklist row parsing
    if (!empty($termLockSource) && is_array($termLockSource)) {
        $termLockSource['year'] = ctlsNormalizeTermYearLabel((string)($termLockSource['year'] ?? ''));
        $termLockSource['semester'] = ctlsNormalizeTermSemesterLabel((string)($termLockSource['semester'] ?? ''));
    }

    // Courses in the next recommended load can still be edited even if their row term
    // differs from the student's current enrollment term.
    $nextRecommendedLoadCourseCodes = [];
    try {
        $optimizedPlan = $studyPlanGenerator->generateOptimizedPlan();
        foreach ($optimizedPlan as $planTerm) {
            if (!empty($planTerm['skipped']) || empty($planTerm['courses']) || !is_array($planTerm['courses'])) {
                continue;
            }

            foreach ($planTerm['courses'] as $planCourse) {
                $planCourseCode = csStudChecklistNormalizeCourseTokenLocal((string)($planCourse['code'] ?? ''));
                if ($planCourseCode !== '') {
                    $nextRecommendedLoadCourseCodes[$planCourseCode] = true;
                }
            }

            break;
        }
    } catch (Throwable $e) {
        $nextRecommendedLoadCourseCodes = [];
    }

    // Build prerequisite blockers based on the student's current/program-view curriculum.
    $prereqBlockersByCourse = [];
    try {
        $studentProgramLabel = '';
        $progStmt = $conn->prepare('SELECT program FROM student_info WHERE student_number = ? LIMIT 1');
        if ($progStmt) {
            $progStmt->bind_param('s', $student_id);
            $progStmt->execute();
            $progResult = $progStmt->get_result();
            if ($progResult && ($progRow = $progResult->fetch_assoc())) {
                $studentProgramLabel = trim((string)($progRow['program'] ?? ''));
            }
            $progStmt->close();
        }

        $programKey = $program_view !== '' ? $program_view : psNormalizeProgramKey($studentProgramLabel);
        $programLabel = $studentProgramLabel;
        $canonical = psCanonicalProgramLabel($programKey);
        if ($canonical !== '') {
            $programLabel = $canonical;
        }

        $rowsForPrereq = [];
        if (function_exists('psFetchChecklistCourses') && ($programLabel !== '' || $programKey !== '')) {
            $rowsForPrereq = psFetchChecklistCourses($conn, (string)$student_id, (string)$programLabel, (string)$programKey);
        }

        if (!empty($rowsForPrereq)) {
            $completed = [];
            foreach ($rowsForPrereq as $r) {
                $codeNorm = csStudChecklistNormalizeCourseTokenLocal($r['course_code'] ?? '');
                $courseRowKey = csStudChecklistBuildRowKeyLocal((array)$r);
                if ($codeNorm === '' || $courseRowKey === '') {
                    continue;
                }

                $effective = csStudChecklistResolveEffectiveApprovedGradeLocal((array)$r);
                if ($effective !== null && csStudChecklistIsPassingFinalGradeLocal($effective)) {
                    $completed[$codeNorm] = true;
                }
            }

            foreach ($rowsForPrereq as $r) {
                $codeNorm = csStudChecklistNormalizeCourseTokenLocal($r['course_code'] ?? '');
                if ($codeNorm === '') {
                    continue;
                }

                $courseRowKey = csStudChecklistBuildRowKeyLocal((array)$r);

                $prereqs = csStudChecklistParsePrerequisitesLocal($r['pre_requisite'] ?? '');
                if (empty($prereqs)) {
                    continue;
                }

                $blockers = [];
                foreach ($prereqs as $pr) {
                    if (!isset($completed[$pr])) {
                        $blockers[] = $pr;
                    }
                }

                if (!empty($blockers)) {
                    $prereqBlockersByCourse[$courseRowKey] = $blockers;
                }
            }
        }
    } catch (Throwable $e) {
        // Non-fatal: if prereq map fails to build, do not block saves.
        $prereqBlockersByCourse = [];
    }

    $academicHold = ahsGetStudentAcademicHold($conn, (string)$student_id);
    if (!empty($academicHold['active'])) {
        throw new Exception((string)($academicHold['message'] ?? 'Your account is currently read-only.'));
    }

    if ($useLaravelBridge) {
        $bridgeData = postLaravelJsonBridge(
            '/api/save-checklist',
            [
                'save_context' => 'student',
                'student_id' => $student_id,
                'program_view' => $program_view,
                'courses' => $courses,
                'course_row_keys' => $course_row_keys,
                'final_grades' => $final_grades,
                'final_grades_2' => $final_grades_2,
                'final_grades_3' => $final_grades_3,
                'evaluator_remarks' => $evaluator_remarks,
                'professor_instructors' => $professor_instructors,
            ]
        );

        if (is_array($bridgeData)) {
            echo json_encode($bridgeData);
            exit();
        }
    }

    $successful = 0;
    $unchanged = 0;
    $errors = [];

    foreach ($courses as $index => $course_code) {
        $course_code = trim((string) $course_code);
        if ($course_code === '') {
            continue;
        }

        $finalGrade = normalizeChecklistValue($final_grades[$index] ?? '');
        $finalGrade2 = normalizeChecklistValue($final_grades_2[$index] ?? '');
        $finalGrade3 = normalizeChecklistValue($final_grades_3[$index] ?? '');
        $professorInstructor = normalizeChecklistValue($professor_instructors[$index] ?? '');

        // Fetch existing record to preserve values from previous attempts
        $check_stmt = $conn->prepare("SELECT final_grade, evaluator_remarks, professor_instructor, final_grade_2, evaluator_remarks_2, final_grade_3, evaluator_remarks_3, approved_by, submitted_by FROM student_checklists WHERE student_id = ? AND course_code = ?");
        if (!$check_stmt) {
            $errors[] = "Check prepare failed for $course_code: " . $conn->error;
            continue;
        }
        $check_stmt->bind_param('ss', $student_id, $course_code);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if (isCreditedLockedChecklistRecordLocal($existing ?: null)) {
            $errors[] = "Skipped credited course (locked): $course_code";
            continue;
        }

        $hasIncomingSubmittedAttempt = ($finalGrade !== '' && $finalGrade !== 'No Grade')
            || ($finalGrade2 !== '' && $finalGrade2 !== 'No Grade')
            || ($finalGrade3 !== '' && $finalGrade3 !== 'No Grade');

        $courseCodeNorm = csStudChecklistNormalizeCourseTokenLocal($course_code);
        $courseInRecommendedLoad = $courseCodeNorm !== '' && !empty($nextRecommendedLoadCourseCodes[$courseCodeNorm]);
        $courseRowKey = trim((string)($course_row_keys[$index] ?? ''));
        if ($courseRowKey !== '' && !empty($termLockSource) && ctlsIsChecklistRowLockedToCurrentTerm($courseRowKey, $termLockSource) && !$courseInRecommendedLoad) {
            $errors[] = "Course {$course_code} is outside the student's current study-plan term.";
            continue;
        }
        if ($hasIncomingSubmittedAttempt && $courseRowKey !== '' && isset($prereqBlockersByCourse[$courseRowKey])) {
            $errors[] = "Prerequisite(s) not cleared for {$course_code}: " . implode(', ', (array)$prereqBlockersByCourse[$courseRowKey]);
            continue;
        }

        $attemptPayload = resolveStudentAttemptPayloadLocal($existing ?: null, $finalGrade, $finalGrade2, $finalGrade3);
        $hasAnySavedAttempt = hasAnySavedAttemptLocal($attemptPayload);
        $existingComparable = buildComparableChecklistRecordLocal($existing ?: []);
        $proposedComparable = buildComparableChecklistRecordLocal([
            'final_grade' => $attemptPayload['final_grade'] ?? '',
            'evaluator_remarks' => $attemptPayload['evaluator_remarks'] ?? '',
            'professor_instructor' => $professorInstructor,
            'final_grade_2' => $attemptPayload['final_grade_2'] ?? '',
            'evaluator_remarks_2' => $attemptPayload['evaluator_remarks_2'] ?? '',
            'final_grade_3' => $attemptPayload['final_grade_3'] ?? '',
            'evaluator_remarks_3' => $attemptPayload['evaluator_remarks_3'] ?? '',
        ]);

        if ($existingComparable === $proposedComparable) {
            $unchanged++;
            continue;
        }

        if (!$existing && !$hasAnySavedAttempt && !hasMeaningfulChecklistRecordLocal($proposedComparable)) {
            $unchanged++;
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO student_checklists
                (student_id, course_code, final_grade, evaluator_remarks, professor_instructor,
                 final_grade_2, evaluator_remarks_2, final_grade_3, evaluator_remarks_3,
                 grade_submitted_at, submitted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'student', NOW(), NULL), IF(? = 'student', 'student', NULL))
            ON DUPLICATE KEY UPDATE
                professor_instructor = VALUES(professor_instructor),
                final_grade = VALUES(final_grade),
                evaluator_remarks = VALUES(evaluator_remarks),
                final_grade_2 = VALUES(final_grade_2),
                evaluator_remarks_2 = VALUES(evaluator_remarks_2),
                final_grade_3 = VALUES(final_grade_3),
                evaluator_remarks_3 = VALUES(evaluator_remarks_3),
                grade_submitted_at = CASE
                    WHEN ? = 'student' THEN NOW()
                    WHEN ? = 'clear' THEN NULL
                    ELSE grade_submitted_at
                END,
                submitted_by = CASE
                    WHEN ? = 'student' THEN 'student'
                    WHEN ? = 'clear' THEN NULL
                    ELSE submitted_by
                END
        ");
        if (!$stmt) {
            $errors[] = "Prepare failed for $course_code: " . $conn->error;
            continue;
        }
        $gradeSubmissionGate = $hasIncomingSubmittedAttempt ? 'student' : '';
        $gradeClearGate = (!$hasIncomingSubmittedAttempt && !$hasAnySavedAttempt) ? 'clear' : '';
        $stmt->bind_param('sssssssssssssss',
            $student_id, $course_code,
            $attemptPayload['final_grade'], $attemptPayload['evaluator_remarks'], $professorInstructor,
            $attemptPayload['final_grade_2'], $attemptPayload['evaluator_remarks_2'],
            $attemptPayload['final_grade_3'], $attemptPayload['evaluator_remarks_3'],
            $gradeSubmissionGate,
            $gradeSubmissionGate,
            $gradeSubmissionGate,
            $gradeClearGate,
            $gradeSubmissionGate,
            $gradeClearGate
        );

        if (!$stmt->execute()) {
            $errors[] = "Failed to save data for course: $course_code";
            $stmt->close();
            continue;
        }
        $stmt->close();
        $successful++;
    }

    $conn->close();

    $status = 'success';
    if ($successful === 0 && count($errors) === 0) {
        $status = 'noop';
    } elseif ($successful === 0 && count($errors) > 0) {
        $status = 'error';
    }

    $message = "Successfully saved {$successful} record(s).";
    if ($status === 'noop') {
        $message = 'No checklist changes were detected.';
    } elseif ($status === 'error') {
        $message = $errors[0] ?? 'Unable to save checklist.';
    }

    echo json_encode([
        'status' => $status,
        'updated' => $successful,
        'unchanged' => $unchanged,
        'message' => $message,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>

