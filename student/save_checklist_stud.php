<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

    // Database connection
    $conn = getDBConnection();

    // Debug: log received POST data
    file_put_contents('debug_save_checklist_stud.log', "POST: " . print_r($_POST, true) . "\n", FILE_APPEND);

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
        $finalGrade  = isset($final_grades[$index])   ? $final_grades[$index]   : '';
        $finalGrade2 = isset($final_grades_2[$index]) ? $final_grades_2[$index] : '';
        $finalGrade3 = isset($final_grades_3[$index]) ? $final_grades_3[$index] : '';
        $professorInstructor = isset($professor_instructors[$index]) ? $professor_instructors[$index] : '';

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

        $isCreditedLocked = false;
        if ($existing) {
            $r1 = strtolower(trim((string)($existing['evaluator_remarks'] ?? '')));
            $r2 = strtolower(trim((string)($existing['evaluator_remarks_2'] ?? '')));
            $r3 = strtolower(trim((string)($existing['evaluator_remarks_3'] ?? '')));
            $approvedBy = strtolower(trim((string)($existing['approved_by'] ?? '')));
            $submittedBy = strtolower(trim((string)($existing['submitted_by'] ?? '')));

            $isCreditedLocked =
                (strpos($r1, 'credited') !== false) ||
                (strpos($r2, 'credited') !== false) ||
                (strpos($r3, 'credited') !== false) ||
                ($approvedBy === 'shift_engine') ||
                ($submittedBy === 'shift_engine');
        }

        if ($isCreditedLocked) {
            $errors[] = "Skipped credited course (locked): $course_code";
            continue;
        }

        // Determine evaluator_remarks per attempt (keep existing if grade unchanged)
        $er1 = ($finalGrade !== '' && $finalGrade !== null)
            ? (($existing && $existing['final_grade'] === $finalGrade) ? ($existing['evaluator_remarks'] ?? 'Pending') : 'Pending')
            : ($existing['evaluator_remarks'] ?? '');
        $er2 = ($finalGrade2 !== '' && $finalGrade2 !== null && $finalGrade2 !== 'No Grade')
            ? (($existing && $existing['final_grade_2'] === $finalGrade2) ? ($existing['evaluator_remarks_2'] ?? 'Pending') : 'Pending')
            : ($existing['evaluator_remarks_2'] ?? '');
        $er3 = ($finalGrade3 !== '' && $finalGrade3 !== null && $finalGrade3 !== 'No Grade')
            ? (($existing && $existing['final_grade_3'] === $finalGrade3) ? ($existing['evaluator_remarks_3'] ?? 'Pending') : 'Pending')
            : ($existing['evaluator_remarks_3'] ?? '');

        file_put_contents('debug_save_checklist_stud.log', "Saving $course_code: g1='$finalGrade', g2='$finalGrade2', g3='$finalGrade3'\n", FILE_APPEND);

        $stmt = $conn->prepare("
            INSERT INTO student_checklists
                (student_id, course_code, final_grade, evaluator_remarks, professor_instructor,
                 final_grade_2, evaluator_remarks_2, final_grade_3, evaluator_remarks_3,
                 grade_submitted_at, submitted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? != '', NOW(), NULL), IF(? != '', 'student', NULL))
            ON DUPLICATE KEY UPDATE
                professor_instructor = VALUES(professor_instructor),
                final_grade         = IF(VALUES(final_grade) != '' AND VALUES(final_grade) IS NOT NULL, VALUES(final_grade), final_grade),
                evaluator_remarks   = IF(VALUES(final_grade) != '' AND VALUES(final_grade) IS NOT NULL, ?, evaluator_remarks),
                final_grade_2       = IF(VALUES(final_grade_2) != '' AND VALUES(final_grade_2) IS NOT NULL, VALUES(final_grade_2), final_grade_2),
                evaluator_remarks_2 = IF(VALUES(final_grade_2) != '' AND VALUES(final_grade_2) IS NOT NULL, ?, evaluator_remarks_2),
                final_grade_3       = IF(VALUES(final_grade_3) != '' AND VALUES(final_grade_3) IS NOT NULL, VALUES(final_grade_3), final_grade_3),
                evaluator_remarks_3 = IF(VALUES(final_grade_3) != '' AND VALUES(final_grade_3) IS NOT NULL, ?, evaluator_remarks_3),
                grade_submitted_at  = IF(VALUES(final_grade) != '' AND VALUES(final_grade) IS NOT NULL, NOW(), grade_submitted_at),
                submitted_by        = IF(VALUES(final_grade) != '' AND VALUES(final_grade) IS NOT NULL, 'student', submitted_by)
        ");
        if (!$stmt) {
            $errors[] = "Prepare failed for $course_code: " . $conn->error;
            file_put_contents('debug_save_checklist_stud.log', "Prepare failed: " . $conn->error . "\n", FILE_APPEND);
            continue;
        }
        // 9 INSERT data + 2 IF gates = 11 values, plus 3 ON DUPLICATE KEY remarks = 14 total params
        $stmt->bind_param('ssssssssssssss',
            $student_id, $course_code,
            $finalGrade, $er1, $professorInstructor,
            $finalGrade2, $er2, $finalGrade3, $er3,
            $finalGrade, $finalGrade,
            $er1, $er2, $er3
        );

        if (!$stmt->execute()) {
            $errors[] = "Failed to save data for course: $course_code";
            file_put_contents('debug_save_checklist_stud.log', "Execute failed for $course_code: " . $stmt->error . "\n", FILE_APPEND);
            continue;
        }
        $stmt->close();
        $successful++;
    }

    $conn->close();

    file_put_contents('debug_save_checklist_stud.log', "Success count: $successful\nErrors: " . print_r($errors, true) . "\n", FILE_APPEND);

    echo json_encode([
        'status' => 'success',
        'updated' => $successful,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    file_put_contents('debug_save_checklist_stud.log', "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
