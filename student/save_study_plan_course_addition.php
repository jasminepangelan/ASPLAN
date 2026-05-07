<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/study_plan_course_addition_service.php';

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$sessionStudentId = trim((string)($_SESSION['student_id'] ?? ''));
$studentId = trim((string)($input['student_id'] ?? ''));
$courseCode = trim((string)($input['course_code'] ?? ''));
$targetYear = trim((string)($input['target_year'] ?? ''));
$targetSemester = trim((string)($input['target_semester'] ?? ''));
$isAdded = !empty($input['added']);

if ($sessionStudentId === '' || $studentId === '' || $courseCode === '' || $targetYear === '' || $targetSemester === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if ($studentId !== $sessionStudentId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized student update']);
    exit;
}

$conn = getDBConnection();
$conn->set_charset('utf8mb4');

$academicHold = ahsGetStudentAcademicHold($conn, $sessionStudentId);
if (!empty($academicHold['active'])) {
    closeDBConnection($conn);
    echo json_encode([
        'success' => false,
        'message' => (string)($academicHold['message'] ?? 'Your account is currently read-only.')
    ]);
    exit;
}

$updatedBy = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? $sessionStudentId);
$result = spcaSaveCourseAdditionState($conn, $sessionStudentId, $courseCode, $targetYear, $targetSemester, $isAdded, $updatedBy);
$success = !empty($result['success']);

echo json_encode([
    'success' => $success,
    'message' => $success
        ? ($isAdded ? 'Course marked as added' : 'Course reset to to-be-added')
        : (string)($result['message'] ?? 'Failed to update course addition state'),
    'added' => $isAdded,
]);

closeDBConnection($conn);
?>
