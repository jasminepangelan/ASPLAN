<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
header('Content-Type: application/json');

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
    return normalizeChecklistValue($remark) === 'Approved';
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
    $courses = $_POST['courses'];
    $final_grades = $_POST['final_grades'];
    $final_grades_2 = $_POST['final_grades_2'] ?? [];
    $final_grades_3 = $_POST['final_grades_3'] ?? [];
    $professor_instructors = $_POST['professor_instructors'];
    $evaluator_remarks = $_POST['evaluator_remarks'] ?? [];

    $academicHold = ahsGetStudentAcademicHold($conn, (string)$student_id);
    if (!empty($academicHold['active'])) {
        throw new Exception((string)($academicHold['message'] ?? 'Your account is currently read-only.'));
    }

    if ($useLaravelBridge) {
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/save-checklist',
            [
                'save_context' => 'student',
                'student_id' => $student_id,
                'courses' => $courses,
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
        $check_stmt = $conn->prepare("SELECT final_grade, evaluator_remarks, final_grade_2, evaluator_remarks_2, final_grade_3, evaluator_remarks_3, approved_by, submitted_by FROM student_checklists WHERE student_id = ? AND course_code = ?");
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

        $attemptPayload = resolveStudentAttemptPayloadLocal($existing ?: null, $finalGrade, $finalGrade2, $finalGrade3);
        $hasAnySavedAttempt = hasAnySavedAttemptLocal($attemptPayload);

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

    echo json_encode([
        'status' => 'success',
        'updated' => $successful,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
