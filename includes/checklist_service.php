<?php
/**
 * Checklist Service
 * Handles student checklist/grade submissions with bulk approval support
 */

/**
 * Validate if user has permission to save checklist
 * 
 * @return array ['authorized' => bool, 'role' => 'admin'|'adviser'|'coordinator'|null, 'error' => string|null]
 */
function csValidateUserAccess(): array {
    $isAdmin = isset($_SESSION['admin_id']) || isset($_SESSION['admin_username']);
    $isAdviser = isset($_SESSION['id']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'adviser');
    $isProgramCoordinator = isset($_SESSION['username']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator';

    if ($isAdmin) {
        return ['authorized' => true, 'role' => 'admin', 'error' => null];
    } elseif ($isAdviser) {
        return ['authorized' => true, 'role' => 'adviser', 'error' => null];
    } elseif ($isProgramCoordinator) {
        return ['authorized' => true, 'role' => 'coordinator', 'error' => null];
    }

    return ['authorized' => false, 'role' => null, 'error' => 'Unauthorized request'];
}

/**
 * Get and validate CSRF token from multiple sources
 * 
 * @return array ['valid' => bool, 'token' => string, 'error' => string|null]
 */
function csValidateCSRFToken(): array {
    // Try POST parameter first
    $csrfToken = trim((string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    
    if ($csrfToken === '') {
        // Try JSON body if POST is empty
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
        return ['valid' => false, 'token' => $csrfToken, 'error' => 'Invalid security token. Please refresh and try again.'];
    }

    return ['valid' => true, 'token' => $csrfToken, 'error' => null];
}

/**
 * Parse checklist input from POST/JSON and determine submission type
 * 
 * @return array [
 *     'mode' => 'bulk'|'standard'|null,
 *     'student_id' => string,
 *     'courses' => array,
 *     'grades' => array|null,
 *     'evaluator_remarks' => array|null,
 *     'professors' => array|null,
 *     'error' => string|null
 * ]
 */
function csParseChecklistInput(): array {
    $rawInput = null;
    $mode = null;
    $student_id = '';
    $courses = [];
    $grades = [];
    $evaluator_remarks = [];
    $professors = [];

    // Check for bulk approval via form-data
    if (isset($_POST['bulk_approve']) && $_POST['bulk_approve']) {
        return [
            'mode' => 'bulk',
            'student_id' => $_POST['student_id'] ?? '',
            'courses' => isset($_POST['courses']) ? json_decode($_POST['courses'], true) : [],
            'grades' => isset($_POST['grades']) ? json_decode($_POST['grades'], true) : [],
            'evaluator_remarks' => null,
            'professors' => isset($_POST['professors']) ? json_decode($_POST['professors'], true) : [],
            'error' => null
        ];
    }

    // Check for JSON input
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? $_SERVER["CONTENT_TYPE"] : '';
    if (strpos($contentType, 'application/json') !== false) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (!is_array($input)) {
            return ['mode' => null, 'error' => 'Invalid JSON format'];
        }

        // Check for bulk approval via JSON
        if (isset($input['bulk_approve']) && $input['bulk_approve']) {
            return [
                'mode' => 'bulk',
                'student_id' => $input['student_id'] ?? '',
                'courses' => $input['courses'] ?? [],
                'grades' => $input['grades'] ?? [],
                'evaluator_remarks' => null,
                'professors' => isset($input['professors']) ? $input['professors'] : [],
                'error' => null
            ];
        }
    }

    // Standard save from form-data
    return [
        'mode' => 'standard',
        'student_id' => $_POST['student_id'] ?? '',
        'courses' => isset($_POST['courses']) ? json_decode($_POST['courses'], true) : [],
        'grades' => isset($_POST['final_grades']) ? json_decode($_POST['final_grades'], true) : [],
        'evaluator_remarks' => isset($_POST['evaluator_remarks']) ? json_decode($_POST['evaluator_remarks'], true) : [],
        'professors' => isset($_POST['professor_instructors']) ? json_decode($_POST['professor_instructors'], true) : [],
        'error' => null
    ];
}

/**
 * Validate student exists
 * 
 * @param mysqli $conn Database connection
 * @param string $studentId Student ID to validate
 * @return bool True if student exists
 */
function csValidateStudentExists($conn, string $studentId): bool {
    $stmt = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ?");
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    return $exists;
}

/**
 * Normalize array data: convert indexed arrays to associative using course codes
 * 
 * @param array $array Array to normalize
 * @param array $keys Key array (course codes)
 * @return array Associative array
 */
function csNormalizeArrayData(array $array, array $keys): array {
    // If already associative, return as-is
    if (array_values($array) !== $array) {
        return $array;
    }

    // Convert indexed to associative
    $normalized = [];
    foreach ($keys as $idx => $key) {
        $normalized[$key] = isset($array[$idx]) ? $array[$idx] : '';
    }
    
    return $normalized;
}

/**
 * Save bulk checklist approvals (all courses marked as approved)
 * 
 * @param mysqli $conn Database connection
 * @param string $studentId Student ID
 * @param array $courses Course codes
 * @param array $grades Grades (indexed or associative)
 * @param array $professors Professors (indexed or associative)
 * @return array ['success' => bool, 'count' => int, 'errors' => array]
 */
function csSaveBulkChecklistApprovals($conn, string $studentId, array $courses, array $grades, array $professors): array {
    if (!csValidateStudentExists($conn, $studentId)) {
        return ['success' => false, 'count' => 0, 'errors' => ['Student ID does not exist']];
    }

    // Normalize grade and professor arrays
    $grades = csNormalizeArrayData($grades, $courses);
    $professors = csNormalizeArrayData($professors, $courses);

    $stmt = $conn->prepare("
        INSERT INTO student_checklists (
            student_id, course_code, final_grade, evaluator_remarks, professor_instructor,
            grade_approved, approved_at, approved_by, grade_submitted_at, submitted_by
        )
        VALUES (?, ?, ?, 'Approved', ?, 1, NOW(), 'adviser', NOW(), 'adviser')
        ON DUPLICATE KEY UPDATE 
        final_grade = VALUES(final_grade), 
        evaluator_remarks = 'Approved',
        professor_instructor = VALUES(professor_instructor),
        grade_approved = 1,
        approved_at = NOW(),
        approved_by = 'adviser',
        grade_submitted_at = IF(grade_submitted_at IS NULL, NOW(), grade_submitted_at),
        submitted_by = IF(submitted_by IS NULL OR submitted_by = '', 'adviser', submitted_by)
    ");

    if (!$stmt) {
        return ['success' => false, 'count' => 0, 'errors' => [$conn->error]];
    }

    $successful = 0;
    $errors = [];

    foreach ($courses as $course_code) {
        $grade = isset($grades[$course_code]) ? $grades[$course_code] : '';
        $professor = isset($professors[$course_code]) ? $professors[$course_code] : '';
        
        $stmt->bind_param('ssss', $studentId, $course_code, $grade, $professor);
        
        if (!$stmt->execute()) {
            $errors[] = "Course $course_code: " . $stmt->error;
        } else {
            $successful++;
        }
    }

    $stmt->close();

    return [
        'success' => (count($errors) === 0),
        'count' => $successful,
        'errors' => $errors
    ];
}

/**
 * Save standard checklist records
 * 
 * @param mysqli $conn Database connection
 * @param string $studentId Student ID
 * @param array $courses Course codes
 * @param array $grades Final grades
 * @param array $remarks Evaluator remarks
 * @param array $professors Professors (indexed or associative)
 * @return array ['success' => bool, 'count' => int, 'errors' => array]
 */
function csSaveChecklistRecords($conn, string $studentId, array $courses, array $grades, array $remarks, array $professors): array {
    if (!csValidateStudentExists($conn, $studentId)) {
        return ['success' => false, 'count' => 0, 'errors' => ['Student ID does not exist']];
    }

    // Validate required data
    if (empty($courses) || empty($grades) || empty($remarks)) {
        return ['success' => false, 'count' => 0, 'errors' => ['Missing required course data']];
    }

    if (count($courses) !== count($grades) || count($courses) !== count($remarks)) {
        return ['success' => false, 'count' => 0, 'errors' => ['Data array length mismatch']];
    }

    // Normalize professor array
    $professors = csNormalizeArrayData($professors, $courses);

    $stmt = $conn->prepare("
        INSERT INTO student_checklists (
            student_id, course_code, final_grade, evaluator_remarks, professor_instructor,
            grade_approved, approved_at, approved_by, grade_submitted_at, submitted_by
        )
        VALUES (?, ?, ?, ?, ?,
            IF(? = 'Approved', 1, 0),
            IF(? = 'Approved', NOW(), NULL),
            IF(? = 'Approved', 'adviser', NULL),
            IF(? = 'Approved', NOW(), NULL),
            IF(? = 'Approved', 'adviser', NULL)
        )
        ON DUPLICATE KEY UPDATE 
        final_grade = VALUES(final_grade), 
        evaluator_remarks = VALUES(evaluator_remarks),
        professor_instructor = VALUES(professor_instructor),
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
        return ['success' => false, 'count' => 0, 'errors' => [$conn->error]];
    }

    $successful = 0;
    $errors = [];

    for ($i = 0; $i < count($courses); $i++) {
        $professor = isset($professors[$courses[$i]]) ? $professors[$courses[$i]] : '';
        
        if (!$stmt->bind_param(
            'ssssssssss',
            $studentId,
            $courses[$i],
            $grades[$i],
            $remarks[$i],
            $professor,
            $remarks[$i],
            $remarks[$i],
            $remarks[$i],
            $remarks[$i],
            $remarks[$i]
        )) {
            $errors[] = "Course {$courses[$i]}: " . $stmt->error;
            continue;
        }

        if (!$stmt->execute()) {
            $errors[] = "Course {$courses[$i]}: " . $stmt->error;
        } else {
            $successful++;
        }
    }

    $stmt->close();

    return [
        'success' => (count($errors) === 0),
        'count' => $successful,
        'errors' => $errors
    ];
}
?>
