<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/student_current_enrollment_service.php';
require_once __DIR__ . '/generate_study_plan.php';

header('Content-Type: application/json; charset=UTF-8');

function sceLoadAllowedCurrentEnrollmentCoursesFromSession(string $studentId): array
{
    $cache = $_SESSION['current_enrollment_allowed_courses'] ?? null;
    if (!is_array($cache)) {
        return [];
    }

    $cachedStudentId = trim((string) ($cache['student_id'] ?? ''));
    $generatedAt = (int) ($cache['generated_at'] ?? 0);
    $courses = $cache['courses'] ?? null;

    if ($cachedStudentId !== $studentId || !is_array($courses)) {
        return [];
    }

    if ($generatedAt > 0 && (time() - $generatedAt) > 1800) {
        return [];
    }

    $courseMap = [];
    foreach ($courses as $courseCode => $course) {
        if (!is_array($course)) {
            continue;
        }

        $normalizedCode = sceNormalizeCourseCode((string) ($course['course_code'] ?? $courseCode));
        if ($normalizedCode === '') {
            continue;
        }

        $courseMap[$normalizedCode] = [
            'course_code' => $normalizedCode,
            'course_title' => trim((string) ($course['course_title'] ?? '')),
            'units' => (float) ($course['units'] ?? 0),
            'prerequisite' => trim((string) ($course['prerequisite'] ?? 'None')),
            'reason' => trim((string) ($course['reason'] ?? '')),
            'source_year_level' => trim((string) ($course['source_year_level'] ?? '')),
            'source_semester' => trim((string) ($course['source_semester'] ?? '')),
        ];
    }

    return $courseMap;
}

function sceResolveAllowedCurrentEnrollmentCourses(mysqli $conn, string $studentId): array
{
    $cachedCourses = sceLoadAllowedCurrentEnrollmentCoursesFromSession($studentId);
    if (!empty($cachedCourses)) {
        return $cachedCourses;
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

    if (!empty($allowedCourses)) {
        $_SESSION['current_enrollment_allowed_courses'] = [
            'student_id' => $studentId,
            'generated_at' => time(),
            'courses' => $allowedCourses,
        ];
    }

    return $allowedCourses;
}

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Student session not found.']);
    exit();
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
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
$conn->set_charset('utf8mb4');

try {
    $academicHold = ahsGetStudentAcademicHold($conn, $studentId);
    if (!empty($academicHold['active'])) {
        http_response_code(423);
        echo json_encode([
            'success' => false,
            'message' => (string) ($academicHold['message'] ?? 'Enrollment updates are currently locked.')
        ]);
        exit();
    }

    $allowedCourses = sceResolveAllowedCurrentEnrollmentCourses($conn, $studentId);

    if (empty($allowedCourses)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No available subjects were found for this student.']);
        exit();
    }

    $selectedCourses = [];
    foreach ($courseCodes as $courseCode) {
        if (!isset($allowedCourses[$courseCode])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'One or more selected subjects are invalid for this student.']);
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
    elsError(
        'Current enrollment save failed',
        [
            'student_id' => $studentId,
            'year_level' => $yearLevel,
            'semester' => $semester,
            'course_codes' => $courseCodes,
            'error' => $e->getMessage(),
        ],
        'current_enrollment',
        $e instanceof Exception ? $e : null
    );
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to save the current enrollment right now.',
    ]);
} finally {
    closeDBConnection($conn);
}
