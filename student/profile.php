<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

$isAdmin = isset($_SESSION['admin_id']) || isset($_SESSION['admin_username']);
$sessionStudentId = trim((string) ($_SESSION['student_id'] ?? $_SESSION['student_number'] ?? ''));
$requestedStudentId = trim((string) ($_GET['student_id'] ?? ''));

if ($isAdmin) {
    $student_id = $requestedStudentId;
} else {
    if ($sessionStudentId === '') {
        header('Location: ../index.php');
        exit;
    }

    if ($requestedStudentId !== '' && !hash_equals($sessionStudentId, $requestedStudentId)) {
        http_response_code(403);
        exit('Access denied.');
    }

    $student_id = $sessionStudentId;
}

if ($student_id === '') {
    exit('No student selected.');
}

$student = null;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $payload = [
        'bridge_authorized' => true,
        'student_id' => $student_id,
    ];

    if ($isAdmin) {
        $payload['profile_context'] = 'admin';
        $payload['admin_id'] = (string) ($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? '');
    } else {
        $payload['profile_context'] = 'student';
        $payload['session_student_id'] = $sessionStudentId;
    }

    $bridgeData = postLaravelJsonBridge(
        '/api/student-profile/view',
        $payload
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
        $student = $bridgeData['student'];
    }
}

if ($student === null) {
    $conn = getDBConnection();
    $query = "SELECT *, student_number AS student_id FROM student_info WHERE student_number = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
}
?>
<!-- Display student profile -->
