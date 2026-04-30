<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/study_plan_override_service.php';

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
$bridgeFailureMessage = '';

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
    $bridgePayload = $input;
    $bridgePayload['bridge_authorized'] = true;
    if ($isAdmin) {
        $bridgePayload['admin_id'] = (string)($_SESSION['admin_id'] ?? '');
        $bridgePayload['admin_username'] = (string)($_SESSION['admin_username'] ?? '');
    } else {
        $bridgePayload['username'] = (string)($_SESSION['username'] ?? '');
        $bridgePayload['user_type'] = 'program_coordinator';
    }

    $bridgeData = postLaravelJsonBridge(
        '/api/study-plan/override',
        $bridgePayload
    );
    if (is_array($bridgeData)) {
        if (!empty($bridgeData['success'])) {
            echo json_encode($bridgeData);
            exit();
        }

        $bridgeFailureMessage = trim((string)($bridgeData['message'] ?? ''));
    }
}

if ($studentId === '' || $courseCode === '' || $targetYear === '' || $targetSemester === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$validYears = spoValidOverrideYears();
$validSemesters = spoValidOverrideSemesters();
if (!in_array($targetYear, $validYears, true) || !in_array($targetSemester, $validSemesters, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid target term']);
    exit();
}

$conn = getDBConnection();
$conn->set_charset('utf8mb4');

$studentStmt = $conn->prepare('SELECT program FROM student_info WHERE student_number = ? LIMIT 1');
$studentStmt->bind_param('s', $studentId);
$studentStmt->execute();
$studentRes = $studentStmt->get_result();
$student = $studentRes ? $studentRes->fetch_assoc() : null;
$studentStmt->close();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    closeDBConnection($conn);
    exit();
}

$updatedBy = $isAdmin
    ? (string)($_SESSION['admin_username'] ?? 'admin')
    : (string)($_SESSION['username'] ?? '');
$saveResult = spoSaveStudyPlanOverride($conn, $studentId, $courseCode, $targetYear, $targetSemester, $updatedBy);
$ok = !empty($saveResult['success']);
$dbError = (string)($saveResult['message'] ?? '');

if (!$ok) {
    error_log('Failed to save study plan override for student ' . $studentId . ' course ' . $courseCode . ': ' . $dbError);
    $message = $bridgeFailureMessage !== '' ? $bridgeFailureMessage : 'Failed to save override';
    echo json_encode(['success' => false, 'message' => $message]);
    closeDBConnection($conn);
    exit();
}

echo json_encode(['success' => true, 'message' => 'Course moved successfully']);
closeDBConnection($conn);
?>
