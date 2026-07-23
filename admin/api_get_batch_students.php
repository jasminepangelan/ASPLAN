<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$prefix = trim($_GET['prefix'] ?? '');
$program = trim($_GET['program'] ?? '');
if (empty($prefix) || empty($program)) {
    echo json_encode(['success' => false, 'error' => 'Prefix and program are required']);
    exit();
}

try {
    $conn = getDBConnection();
    
    $prefixLike = $prefix . '%';
    
    // We want students matching prefix whose status is NOT archived
    $stmt = $conn->prepare("
        SELECT student_number, first_name, last_name, status 
        FROM student_info 
        WHERE student_number LIKE ? 
          AND program = ?
          AND (status IS NULL OR status != 'archived')
        ORDER BY last_name ASC, first_name ASC
    ");
    
    if (!$stmt) {
        throw new Exception("Query preparation failed.");
    }
    
    $stmt->bind_param('ss', $prefixLike, $program);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_number' => $row['student_number'],
            'name' => trim($row['last_name'] . ', ' . $row['first_name'])
        ];
    }
    
    $stmt->close();
    
    echo json_encode(['success' => true, 'students' => $students]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
