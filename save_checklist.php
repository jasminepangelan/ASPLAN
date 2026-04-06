<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/laravel_bridge.php';

// Disable any output buffering or errors that might corrupt JSON
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any stray output
ob_start();

// Set JSON header immediately
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $isAdmin = isset($_SESSION['admin_id']) || isset($_SESSION['admin_username']);
    $isAdviser = isset($_SESSION['id']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'adviser');
    $isProgramCoordinator = isset($_SESSION['username']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator';
    if (!$isAdmin && !$isAdviser && !$isProgramCoordinator) {
        http_response_code(403);
        throw new Exception('Unauthorized request');
    }

    $rawInput = null;
    $csrfToken = trim((string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if ($csrfToken === '') {
        $contentTypeForCsrf = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '';
        if (stripos($contentTypeForCsrf, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $csrfPayload = json_decode($rawInput, true);
            if (is_array($csrfPayload)) {
                $csrfToken = trim((string)($csrfPayload['csrf_token'] ?? ''));
            }
        }
    }

    if (!validateCSRFToken($csrfToken)) {
        http_response_code(403);
        throw new Exception('Invalid security token. Please refresh and try again.');
    }

    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

    // Use centralized DB helper for consistent configuration and charset.
    $conn = getDBConnection();

    // Helper function to validate student exists in student_info
    function validateStudentExists($conn, $student_id) {
        $stmt = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ?");
        $student_id_int = intval($student_id);
        $stmt->bind_param('i', $student_id_int);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    // Check if this is a bulk approval request (sent as JSON or form-data)
    $isBulkApprove = false;
    $student_id = '';
    $courses = [];
    $grades = [];
    $professors = [];
    $debug = [];
    if (isset($_POST['bulk_approve']) && $_POST['bulk_approve']) {
        // Bulk approve via form-data
        $isBulkApprove = true;
        $student_id = $_POST['student_id'];
        $courses = isset($_POST['courses']) ? json_decode($_POST['courses'], true) : [];
        $grades = isset($_POST['grades']) ? json_decode($_POST['grades'], true) : [];
        $professors = isset($_POST['professors']) ? json_decode($_POST['professors'], true) : [];
        $debug['source'] = 'form-data';
    } else {
        $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode($rawInput !== null ? $rawInput : file_get_contents('php://input'), true);
            if (isset($input['bulk_approve']) && $input['bulk_approve']) {
                $isBulkApprove = true;
                $student_id = $input['student_id'];
                $courses = $input['courses'];
                $grades = $input['grades'];
                $professors = isset($input['professors']) ? $input['professors'] : [];
                $debug['source'] = 'json';
            }
        }
    }
    $debug['student_id'] = $student_id;
    $debug['courses'] = $courses;
    $debug['grades'] = $grades;
    $debug['professors'] = $professors;

    if ($useLaravelBridge) {
        if ($isBulkApprove) {
            $bridgeData = postLaravelJsonBridge(
                'http://localhost/ASPLAN_v10/laravel-app/public/api/save-checklist',
                [
                    'bulk_approve' => true,
                    'save_context' => 'staff',
                    'student_id' => $student_id,
                    'courses' => $courses,
                    'grades' => $grades,
                    'professors' => $professors,
                ]
            );
        } else {
            $bridgeData = postLaravelJsonBridge(
                'http://localhost/ASPLAN_v10/laravel-app/public/api/save-checklist',
                [
                    'save_context' => 'staff',
                    'student_id' => (string) ($_POST['student_id'] ?? $student_id),
                    'courses' => $_POST['courses'] ?? [],
                    'final_grades' => $_POST['final_grades'] ?? [],
                    'final_grades_2' => $_POST['final_grades_2'] ?? [],
                    'final_grades_3' => $_POST['final_grades_3'] ?? [],
                    'evaluator_remarks' => $_POST['evaluator_remarks'] ?? [],
                    'professor_instructors' => $_POST['professor_instructors'] ?? [],
                ]
            );
        }

        if (is_array($bridgeData)) {
            ob_clean();
            echo json_encode($bridgeData);
            exit;
        }
    }

    if ($isBulkApprove) {
        // Validate student existence
        if (!validateStudentExists($conn, $student_id)) {
            throw new Exception('Student does not exist');
        }

        // Defensive: ensure grades is associative array
        if (is_array($grades) && array_values($grades) === $grades) {
            // If grades is a simple array, convert to associative using course codes
            $grades_assoc = [];
            foreach ($courses as $idx => $course_code) {
                $grades_assoc[$course_code] = isset($grades[$idx]) ? $grades[$idx] : '';
            }
            $grades = $grades_assoc;
        }
        
        // Defensive: ensure professors is associative array
        if (is_array($professors) && array_values($professors) === $professors) {
            // If professors is a simple array, convert to associative using course codes
            $professors_assoc = [];
            foreach ($courses as $idx => $course_code) {
                $professors_assoc[$course_code] = isset($professors[$idx]) ? $professors[$idx] : '';
            }
            $professors = $professors_assoc;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO student_checklists (student_id, course_code, final_grade, evaluator_remarks, professor_instructor, grade_approved, approved_at, approved_by, grade_submitted_at, submitted_by)
            VALUES (?, ?, ?, 'Approved', ?, 1, NOW(), 'adviser', NOW(), 'adviser')
            ON DUPLICATE KEY UPDATE 
            final_grade = VALUES(final_grade), 
            evaluator_remarks = 'Approved',
            professor_instructor = VALUES(professor_instructor),
            grade_approved = 1,
            approved_at = NOW(),
            approved_by = 'adviser',
            grade_submitted_at = IF(grade_submitted_at IS NULL, NOW(), grade_submitted_at),
            submitted_by = IF(submitted_by IS NULL, 'adviser', submitted_by)
        ");
        if (!$stmt) {
            error_log('save_checklist bulk prepare failed: ' . $conn->error);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unable to process the request. Please try again later.',
                'debug' => $debug
            ]);
            exit;
        }
        $successful = 0;
        foreach ($courses as $course_code) {
            $grade = isset($grades[$course_code]) ? $grades[$course_code] : '';
            $professor_instructor = isset($professors[$course_code]) ? $professors[$course_code] : '';
            $stmt->bind_param('ssss', $student_id, $course_code, $grade, $professor_instructor);
            if (!$stmt->execute()) {
                $debug['error'][] = $stmt->error;
                continue;
            }
            $successful++;
        }
        $stmt->close();
        $conn->close();
        
        // Clean output buffer and send JSON
        ob_clean();
        echo json_encode([
            'status' => 'success',
            'message' => "Bulk approved $successful records",
            'debug' => $debug
        ]);
        exit;
    }

    // Standard save (form-data)
    $student_id = $_POST['student_id'];
    $courses = json_decode($_POST['courses'], true);
    $final_grades = json_decode($_POST['final_grades'], true);
    $final_grades_2 = isset($_POST['final_grades_2']) ? json_decode($_POST['final_grades_2'], true) : [];
    $final_grades_3 = isset($_POST['final_grades_3']) ? json_decode($_POST['final_grades_3'], true) : [];
    $evaluator_remarks = json_decode($_POST['evaluator_remarks'], true);
    $professor_instructors = isset($_POST['professor_instructors']) ? json_decode($_POST['professor_instructors'], true) : [];

    if (!$courses || !$final_grades || !$evaluator_remarks) {
        throw new Exception('Invalid data format');
    }

    // Validate student existence
    if (!validateStudentExists($conn, $student_id)) {
        throw new Exception('Student does not exist');
    }

    $stmt = $conn->prepare("
        INSERT INTO student_checklists
            (student_id, course_code, final_grade, evaluator_remarks, professor_instructor,
             final_grade_2, evaluator_remarks_2, final_grade_3, evaluator_remarks_3,
             grade_approved, approved_at, approved_by, grade_submitted_at, submitted_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
            IF(? = 'Approved', 1, 0), IF(? = 'Approved', NOW(), NULL), IF(? = 'Approved', 'adviser', NULL),
            IF(? = 'Approved', NOW(), NULL), IF(? = 'Approved', 'adviser', NULL))
        ON DUPLICATE KEY UPDATE
            final_grade = VALUES(final_grade),
            evaluator_remarks = VALUES(evaluator_remarks),
            professor_instructor = VALUES(professor_instructor),
            final_grade_2 = IF(VALUES(final_grade_2) != '' AND VALUES(final_grade_2) IS NOT NULL, VALUES(final_grade_2), final_grade_2),
            evaluator_remarks_2 = IF(VALUES(final_grade_2) != '' AND VALUES(final_grade_2) IS NOT NULL, VALUES(evaluator_remarks_2), evaluator_remarks_2),
            final_grade_3 = IF(VALUES(final_grade_3) != '' AND VALUES(final_grade_3) IS NOT NULL, VALUES(final_grade_3), final_grade_3),
            evaluator_remarks_3 = IF(VALUES(final_grade_3) != '' AND VALUES(final_grade_3) IS NOT NULL, VALUES(evaluator_remarks_3), evaluator_remarks_3),
            grade_approved = IF(VALUES(evaluator_remarks) = 'Approved', 1, 0),
            approved_at = IF(VALUES(evaluator_remarks) = 'Approved', NOW(), NULL),
            approved_by = IF(VALUES(evaluator_remarks) = 'Approved', 'adviser', NULL),
            grade_submitted_at = IF(
                VALUES(evaluator_remarks) = 'Approved' AND grade_submitted_at IS NULL,
                NOW(),
                grade_submitted_at
            ),
            submitted_by = IF(
                VALUES(evaluator_remarks) = 'Approved' AND (submitted_by IS NULL OR submitted_by = ''),
                'adviser',
                submitted_by
            )
    ");

    if (!$stmt) {
        error_log('save_checklist prepare failed: ' . $conn->error);
        throw new Exception('Unable to process the request. Please try again later.');
    }

    $successful = 0;
    for ($i = 0; $i < count($courses); $i++) {
        $professor_instructor = isset($professor_instructors[$i]) ? $professor_instructors[$i] : '';
        $remarks = $evaluator_remarks[$i];
        $fg2 = isset($final_grades_2[$i]) ? $final_grades_2[$i] : '';
        $fg3 = isset($final_grades_3[$i]) ? $final_grades_3[$i] : '';
        // Propagate the same evaluator_remarks to attempt 2/3 only when their grade has a value
        $er2 = $fg2 !== '' ? $remarks : '';
        $er3 = $fg3 !== '' ? $remarks : '';
        $stmt->bind_param('ssssssssssssss',
            $student_id,
            $courses[$i],
            $final_grades[$i],
            $remarks,
            $professor_instructor,
            $fg2,
            $er2,
            $fg3,
            $er3,
            $remarks,
            $remarks,
            $remarks,
            $remarks,
            $remarks
        );
        if (!$stmt->execute()) {
            continue;
        }
        $successful++;
    }

    $stmt->close();
    $conn->close();

    // Clean output buffer and send JSON
    ob_clean();
    echo json_encode([
        'status' => 'success',
        'message' => "Successfully saved $successful records"
    ]);

} catch (Exception $e) {
    // Clean output buffer and send error JSON
    ob_clean();
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
