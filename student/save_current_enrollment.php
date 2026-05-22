<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/student_current_enrollment_service.php';
require_once __DIR__ . '/generate_study_plan.php';

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Student session not found.']);
    exit();
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
    exit();
}

$csrfToken = trim((string) ($payload['csrf_token'] ?? ''));
if ($csrfToken === '' || !validateCSRFToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit();
}

$studentId = (string) $_SESSION['student_id'];
$yearLevel = trim((string) ($payload['year_level'] ?? ''));
$semester = trim((string) ($payload['semester'] ?? ''));
$courseCodes = array_values(array_unique(array_filter(array_map(
    static fn($code): string => sceNormalizeCourseCode((string) $code),
    (array) ($payload['course_codes'] ?? [])
))));

if (!in_array($yearLevel, sceValidYears(), true) || !in_array($semester, sceValidSemesters(), true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please choose a valid year level and semester.']);
    exit();
}

if (empty($courseCodes)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select at least one currently enrolled subject.']);
    exit();
}

$conn = getDBConnection();

try {
    $academicHold = ahsGetStudentAcademicHold($conn, $studentId);
    if (!empty($academicHold['active'])) {
        http_response_code(423);
        echo json_encode([
            'success' => false,
            'message' => (string) ($academicHold['message'] ?? 'Enrollment updates are currently locked.')
        ]);
        closeDBConnection($conn);
        exit();
    }

    $program = trim((string) ($_SESSION['program'] ?? ''));
    $programStmt = $conn->prepare("SELECT program FROM student_info WHERE student_number = ? LIMIT 1");
    if ($programStmt) {
        $programStmt->bind_param('s', $studentId);
        $programStmt->execute();
        $programResult = $programStmt->get_result();
        $programRow = $programResult ? $programResult->fetch_assoc() : null;
        if ($programRow && trim((string) ($programRow['program'] ?? '')) !== '') {
            $program = trim((string) $programRow['program']);
        }
        $programStmt->close();
    }

    if ($program === '') {
        throw new RuntimeException('Student program could not be resolved.');
    }

    $generator = new StudyPlanGenerator($studentId, $program);
    $allowedTerms = sceBuildSelectableTermMap($generator->getAllCoursesGroupedByTerm());
    $allowedCourses = sceBuildSelectableCourseMap($allowedTerms);

    if (empty($allowedCourses)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No available subjects were found for this student.']);
        closeDBConnection($conn);
        exit();
    }

    $selectedCourses = [];
    foreach ($courseCodes as $courseCode) {
        if (!isset($allowedCourses[$courseCode])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'One or more selected subjects are invalid for this student.']);
            closeDBConnection($conn);
            exit();
        }
        $selectedCourses[] = $allowedCourses[$courseCode];
    }

    $savedEnrollment = sceSaveStudentCurrentEnrollment($conn, $studentId, $yearLevel, $semester, $selectedCourses);

    echo json_encode([
        'success' => true,
        'message' => 'Current enrollment saved successfully.',
        'enrollment' => $savedEnrollment,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to save the current enrollment right now.',
    ]);
} finally {
    closeDBConnection($conn);
}
