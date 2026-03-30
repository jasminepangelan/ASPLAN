<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

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

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/study-plan/override',
        $input
    );
    if (is_array($bridgeData)) {
        echo json_encode($bridgeData);
        exit();
    }
}

if ($studentId === '' || $courseCode === '' || $targetYear === '' || $targetSemester === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$validYears = ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
$validSemesters = ['1st Sem', '2nd Sem', 'Mid Year'];
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

$conn->query(
    "CREATE TABLE IF NOT EXISTS student_study_plan_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(32) NOT NULL,
        course_code VARCHAR(64) NOT NULL,
        target_year VARCHAR(20) NOT NULL,
        target_semester VARCHAR(20) NOT NULL,
        updated_by VARCHAR(120) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_course (student_id, course_code),
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$stmt = $conn->prepare(
    "INSERT INTO student_study_plan_overrides
        (student_id, course_code, target_year, target_semester, updated_by)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        target_year = VALUES(target_year),
        target_semester = VALUES(target_semester),
        updated_by = VALUES(updated_by),
        updated_at = CURRENT_TIMESTAMP"
);
$updatedBy = $isAdmin
    ? (string)($_SESSION['admin_username'] ?? 'admin')
    : (string)($_SESSION['username'] ?? '');
$stmt->bind_param('sssss', $studentId, $courseCode, $targetYear, $targetSemester, $updatedBy);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Failed to save override']);
    closeDBConnection($conn);
    exit();
}

echo json_encode(['success' => true, 'message' => 'Course moved successfully']);
closeDBConnection($conn);
?>
