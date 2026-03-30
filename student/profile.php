<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

$student_id = $_GET['student_id']; // Passed via URL
$student = null;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/dashboard/overview',
        [
            'bridge_authorized' => true,
            'role' => 'student',
            'student_id' => $student_id,
        ]
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
