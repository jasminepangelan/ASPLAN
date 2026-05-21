<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/program_shift_service.php';
require_once __DIR__ . '/includes/laravel_bridge.php';

// Disable any output buffering or errors that might corrupt JSON
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any stray output
ob_start();

// Set JSON header immediately
header('Content-Type: application/json');

function csStaffChecklistIsApprovedRemarkLocal($remark): bool
{
    $normalized = strtoupper(trim((string)$remark));
    if ($normalized === 'APPROVED') {
        return true;
    }

    return $normalized !== '' && strpos($normalized, 'CREDITED') !== false;
}

function csStaffChecklistNormalizeCourseTokenLocal($value): string
{
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/^([A-Z]{2,})(\d+[A-Z]*)$/', '$1 $2', $value);
    $value = preg_replace('/^([A-Z]{2,}(?:\s+[A-Z]{1,})?)[\s-]+(\d+[A-Z]*)$/', '$1 $2', $value);
    return trim((string)$value);
}

function csStaffChecklistIsPassingFinalGradeLocal($grade): bool
{
    $normalized = strtoupper(trim((string)$grade));
    if ($normalized === 'S' || $normalized === 'PASSED') {
        return true;
    }

    if (is_numeric($grade)) {
        $numeric_grade = (float)$grade;
        return $numeric_grade >= 1.0 && $numeric_grade <= 3.0;
    }

    return false;
}

function csStaffChecklistResolvePersistedRemarkLocal($incomingRemark, $existingRemark): string
{
    $incomingRemark = trim((string)$incomingRemark);
    if ($incomingRemark !== '') {
        return $incomingRemark;
    }

    $existingRemark = trim((string)$existingRemark);
    if ($existingRemark !== '' && stripos($existingRemark, 'credited') !== false) {
        return $existingRemark;
    }

    return $incomingRemark;
}

function csStaffChecklistResolveEffectiveApprovedGradeLocal(array $row): ?string
{
    $attempts = [
        3 => ['grade' => trim((string)($row['final_grade_3'] ?? '')), 'remark' => (string)($row['evaluator_remarks_3'] ?? '')],
        2 => ['grade' => trim((string)($row['final_grade_2'] ?? '')), 'remark' => (string)($row['evaluator_remarks_2'] ?? '')],
        1 => ['grade' => trim((string)($row['final_grade'] ?? '')), 'remark' => (string)($row['evaluator_remarks'] ?? '')],
    ];

    foreach ([3, 2, 1] as $slot) {
        $grade = $attempts[$slot]['grade'] ?? '';
        if ($grade === '' || strtoupper($grade) === 'NO GRADE') {
            continue;
        }

        if (csStaffChecklistIsApprovedRemarkLocal($attempts[$slot]['remark'] ?? '')) {
            return $grade;
        }
    }

    return null;
}

function csStaffChecklistParsePrerequisitesLocal($prereq_string): array
{
    $looksNonCourse = static function ($value): bool {
        $upper = strtoupper(trim((string)$value));
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

    $prereq_string = trim((string)$prereq_string);
    if ($prereq_string === '' || strtoupper($prereq_string) === 'NONE') {
        return [];
    }

    $normalized = str_replace(["\r\n", "\r", "\n"], ', ', $prereq_string);
    $normalized = preg_replace('/\s*[;\/]\s*/', ', ', $normalized);
    $normalized = preg_replace('/\s+(?:AND|and)\s+/', ', ', $normalized);
    $normalized = preg_replace_callback(
        '/\b([A-Z]{2,}(?:\s+[A-Z]{1,})?[\s-]*\d+[A-Z]*)\s*(?:&|,)\s*((?:\d+[A-Z]*\s*(?:,|&)\s*)*\d+[A-Z]*)/i',
        static function ($m) {
            $first = csStaffChecklistNormalizeCourseTokenLocal($m[1]);
            if ($first === '') {
                return $m[0];
            }

            $prefix = preg_replace('/\s+\d+[A-Z]*$/', '', $first);
            $tailParts = preg_split('/\s*(?:,|&)\s*/', trim((string)$m[2]));
            $expanded = [$first];
            foreach ($tailParts as $part) {
                $part = trim((string)$part);
                if ($part === '') {
                    continue;
                }
                $expanded[] = csStaffChecklistNormalizeCourseTokenLocal($prefix . ' ' . $part);
            }

            return implode(', ', array_filter($expanded));
        },
        $normalized
    );

    $segments = preg_split('/\s*,\s*/', (string)$normalized);
    $valid_prereqs = [];
    $seen = [];
    $last_prefix = '';

    foreach ($segments as $segment) {
        $segment = trim((string)$segment);
        if ($segment === '' || $looksNonCourse($segment)) {
            continue;
        }

        $matches = [];
        preg_match_all('/\b([A-Z]{2,}(?:\s+[A-Z]{1,})?[\s-]*\d+[A-Z]*)\b/i', $segment, $matches);

        $codes = [];
        if (!empty($matches[1])) {
            foreach ($matches[1] as $match) {
                $normalizedCode = csStaffChecklistNormalizeCourseTokenLocal($match);
                if ($normalizedCode !== '') {
                    $codes[] = $normalizedCode;
                }
            }
        } elseif ($last_prefix !== '' && preg_match('/^\d+[A-Z]*$/i', $segment)) {
            $normalizedCode = csStaffChecklistNormalizeCourseTokenLocal($last_prefix . ' ' . $segment);
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

function csStaffChecklistBuildPrereqBlockersLocal($conn, string $studentId, string $programView): array
{
    $prereqBlockersByCourse = [];

    try {
        $studentProgramLabel = '';
        $progStmt = $conn->prepare('SELECT program FROM student_info WHERE student_number = ? LIMIT 1');
        if ($progStmt) {
            $progStmt->bind_param('s', $studentId);
            $progStmt->execute();
            $progResult = $progStmt->get_result();
            if ($progResult && ($progRow = $progResult->fetch_assoc())) {
                $studentProgramLabel = trim((string)($progRow['program'] ?? ''));
            }
            $progStmt->close();
        }

        $programKey = $programView !== '' ? $programView : psNormalizeProgramKey($studentProgramLabel);
        $programLabel = $studentProgramLabel;
        $canonical = psCanonicalProgramLabel($programKey);
        if ($canonical !== '') {
            $programLabel = $canonical;
        }

        $rowsForPrereq = [];
        if (function_exists('psFetchChecklistCourses') && ($programLabel !== '' || $programKey !== '')) {
            $rowsForPrereq = psFetchChecklistCourses($conn, (string)$studentId, (string)$programLabel, (string)$programKey);
        }

        if (empty($rowsForPrereq)) {
            return [];
        }

        $completed = [];
        foreach ($rowsForPrereq as $r) {
            $codeNorm = csStaffChecklistNormalizeCourseTokenLocal($r['course_code'] ?? '');
            if ($codeNorm === '') {
                continue;
            }

            $effective = csStaffChecklistResolveEffectiveApprovedGradeLocal((array)$r);
            if ($effective !== null && csStaffChecklistIsPassingFinalGradeLocal($effective)) {
                $completed[$codeNorm] = true;
            }
        }

        foreach ($rowsForPrereq as $r) {
            $codeNorm = csStaffChecklistNormalizeCourseTokenLocal($r['course_code'] ?? '');
            if ($codeNorm === '') {
                continue;
            }

            $prereqs = csStaffChecklistParsePrerequisitesLocal($r['pre_requisite'] ?? '');
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
                $prereqBlockersByCourse[$codeNorm] = $blockers;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $prereqBlockersByCourse;
}

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
    $program_view = '';
    $courses = [];
    $grades = [];
    $professors = [];
    $debug = [];
    if (isset($_POST['bulk_approve']) && $_POST['bulk_approve']) {
        // Bulk approve via form-data.
        // Note: `program_view` must be supplied for staff bulk approvals so prerequisite
        // blocker calculation uses the same curriculum/program view as the UI.
        $isBulkApprove = true;
        $student_id = $_POST['student_id'];
        $program_view = trim((string)($_POST['program_view'] ?? ''));
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
                $program_view = trim((string)($input['program_view'] ?? ''));
                $courses = $input['courses'];
                $grades = $input['grades'];
                $professors = isset($input['professors']) ? $input['professors'] : [];
                $debug['source'] = 'json';
            }
        }
    }
    $debug['student_id'] = $student_id;
    $debug['program_view'] = $program_view;
    $debug['courses'] = $courses;
    $debug['grades'] = $grades;
    $debug['professors'] = $professors;

    if ($useLaravelBridge) {
        if ($isBulkApprove) {
            $bridgeData = postLaravelJsonBridge(
                '/api/save-checklist',
                [
                    'bulk_approve' => true,
                    'save_context' => 'staff',
                    'student_id' => $student_id,
                    'program_view' => $program_view,
                    'courses' => $courses,
                    'grades' => $grades,
                    'professors' => $professors,
                ]
            );
        } else {
            $bridgeData = postLaravelJsonBridge(
                '/api/save-checklist',
                [
                    'save_context' => 'staff',
                    'student_id' => (string) ($_POST['student_id'] ?? $student_id),
                    'program_view' => trim((string)($_POST['program_view'] ?? '')),
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

        $prereqBlockersByCourse = csStaffChecklistBuildPrereqBlockersLocal($conn, (string)$student_id, (string)$program_view);
        $errors = [];

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

            $gradeNorm = trim((string)$grade);
            $hasIncomingSubmittedAttempt = ($gradeNorm !== '' && strtoupper($gradeNorm) !== 'NO GRADE');
            $courseCodeNorm = csStaffChecklistNormalizeCourseTokenLocal($course_code);
            if ($hasIncomingSubmittedAttempt && $courseCodeNorm !== '' && isset($prereqBlockersByCourse[$courseCodeNorm])) {
                $errors[] = "Prerequisite(s) not cleared for {$course_code}: " . implode(', ', (array)$prereqBlockersByCourse[$courseCodeNorm]);
                continue;
            }

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
        $status = 'success';
        if ($successful === 0 && !empty($errors)) {
            $status = 'error';
        }
        echo json_encode([
            'status' => $status,
            'message' => $status === 'error'
                ? ($errors[0] ?? 'Unable to approve selected grades.')
                : "Bulk approved $successful records",
            'debug' => $debug
            ,
            'errors' => $errors
        ]);
        exit;
    }

    // Standard save (form-data)
    $student_id = $_POST['student_id'];
    $program_view = trim((string)($_POST['program_view'] ?? ''));
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

    $prereqBlockersByCourse = csStaffChecklistBuildPrereqBlockersLocal($conn, (string)$student_id, (string)$program_view);
    $errors = [];

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

    $existingRemarkStmt = $conn->prepare("
        SELECT evaluator_remarks
        FROM student_checklists
        WHERE student_id = ? AND course_code = ?
        LIMIT 1
    ");

    $successful = 0;
    for ($i = 0; $i < count($courses); $i++) {
        $professor_instructor = isset($professor_instructors[$i]) ? $professor_instructors[$i] : '';
        $courseCode = trim((string)($courses[$i] ?? ''));
        $remarks = trim((string)($evaluator_remarks[$i] ?? ''));
        $fg2 = isset($final_grades_2[$i]) ? $final_grades_2[$i] : '';
        $fg3 = isset($final_grades_3[$i]) ? $final_grades_3[$i] : '';

        if ($existingRemarkStmt && $courseCode !== '') {
            $existingRemarkStmt->bind_param('ss', $student_id, $courseCode);
            if ($existingRemarkStmt->execute()) {
                $existingRemarkResult = $existingRemarkStmt->get_result();
                $existingRemarkRow = $existingRemarkResult ? $existingRemarkResult->fetch_assoc() : null;
                $remarks = csStaffChecklistResolvePersistedRemarkLocal(
                    $remarks,
                    $existingRemarkRow['evaluator_remarks'] ?? ''
                );
            }
        }

        $fg1Norm = trim((string)($final_grades[$i] ?? ''));
        $fg2Norm = trim((string)$fg2);
        $fg3Norm = trim((string)$fg3);
        $hasIncomingSubmittedAttempt = ($fg1Norm !== '' && strtoupper($fg1Norm) !== 'NO GRADE')
            || ($fg2Norm !== '' && strtoupper($fg2Norm) !== 'NO GRADE')
            || ($fg3Norm !== '' && strtoupper($fg3Norm) !== 'NO GRADE');

        $courseCodeNorm = csStaffChecklistNormalizeCourseTokenLocal($courseCode);
        if ($hasIncomingSubmittedAttempt && $courseCodeNorm !== '' && isset($prereqBlockersByCourse[$courseCodeNorm])) {
            $errors[] = "Prerequisite(s) not cleared for {$courseCode}: " . implode(', ', (array)$prereqBlockersByCourse[$courseCodeNorm]);
            continue;
        }

        // Propagate the same evaluator_remarks to attempt 2/3 only when their grade has a value
        $er2 = $fg2 !== '' ? $remarks : '';
        $er3 = $fg3 !== '' ? $remarks : '';
        $stmt->bind_param('ssssssssssssss',
            $student_id,
            $courseCode,
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

    if ($existingRemarkStmt) {
        $existingRemarkStmt->close();
    }

    $stmt->close();
    $conn->close();

    // Clean output buffer and send JSON
    ob_clean();
    echo json_encode([
        'status' => ($successful === 0 && !empty($errors)) ? 'error' : 'success',
        'message' => ($successful === 0 && !empty($errors))
            ? ($errors[0] ?? 'Unable to save checklist.')
            : "Successfully saved $successful records",
        'errors' => $errors
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

