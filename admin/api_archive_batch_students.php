<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$studentNumbers = $input['student_numbers'] ?? [];

if (!is_array($studentNumbers) || empty($studentNumbers)) {
    echo json_encode(['success' => false, 'error' => 'No students provided for archiving.']);
    exit();
}

try {
    $conn = getDBConnection();

    // Check freeze settings just like the individual archive logic
    $freezeApprovalsEnabled = false;
    $freezeResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'freeze_approvals' ORDER BY id DESC LIMIT 1");
    if ($freezeResult && $freezeRow = $freezeResult->fetch_assoc()) {
        $freezeApprovalsEnabled = ((int)($freezeRow['setting_value'] ?? 0) === 1);
    }
    
    if ($freezeApprovalsEnabled) {
        throw new Exception('Approval actions are currently frozen by admin settings.');
    }

    $approvedBy = trim((string)($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? ''));

    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE student_info SET status = 'archived', approved_by = ? WHERE student_number = ?");
    if (!$stmt) {
        throw new Exception("Query preparation failed.");
    }

    $successCount = 0;
    foreach ($studentNumbers as $studentId) {
        if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $studentId)) {
            continue; // Skip invalid IDs
        }
        $stmt->bind_param('ss', $approvedBy, $studentId);
        $stmt->execute();
        $successCount++;
    }
    
    $stmt->close();
    $conn->commit();
    
    echo json_encode(['success' => true, 'count' => $successCount]);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
