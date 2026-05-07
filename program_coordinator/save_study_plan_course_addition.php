<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/study_plan_course_addition_service.php';

header('Content-Type: application/json');

$isAdmin = isset($_SESSION['admin_id']) || isset($_SESSION['admin_username']);
$isProgramCoordinator = isset($_SESSION['username']) && (!isset($_SESSION['user_type']) || $_SESSION['user_type'] === 'program_coordinator');

if (!$isAdmin && !$isProgramCoordinator) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit();
}

$studentId = trim((string)($input['student_id'] ?? ''));
$courseCode = trim((string)($input['course_code'] ?? ''));
$targetYear = trim((string)($input['target_year'] ?? ''));
$targetSemester = trim((string)($input['target_semester'] ?? ''));
$isAdded = !empty($input['added']);

if ($studentId === '' || $courseCode === '' || $targetYear === '' || $targetSemester === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$conn = getDBConnection();
$conn->set_charset('utf8mb4');

$updatedBy = $isAdmin
    ? (string)($_SESSION['admin_username'] ?? 'admin')
    : (string)($_SESSION['username'] ?? 'program_coordinator');

$result = spcaSaveCourseAdditionState($conn, $studentId, $courseCode, $targetYear, $targetSemester, $isAdded, $updatedBy);
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
