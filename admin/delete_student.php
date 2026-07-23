<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

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

$studentId = $_POST['student_id'] ?? '';
if (trim($studentId) === '') {
    echo json_encode(['success' => false, 'error' => 'Missing student ID']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // We will attempt to delete from all related tables.
    // Some might fail if they don't exist, so we will ignore errors per table for missing tables,
    // but the query will just be a string of tables.
    // It's safer to delete one by one.
    
    $conn->begin_transaction();
    
    $tablesWithStudentNumber = [
        'accounts',
        'certificate',
        'counseling_req',
        'curriculum_feedback',
        'employment_history',
        'event_req',
        'graduates',
        'notification',
        'password_history',
        'program_shift_credit_map',
        'program_shift_requests',
        'stud_educ_background',
        'student_email_verifications',
        'student_masterlist',
        'student_rejection_log',
        'student_info'
    ];
    
    $tablesWithStudentId = [
        'good_moral_req',
        'job_applications',
        'ojt_records',
        'profile_completion_trac',
        'recommendations',
        'student_checklists',
        'student_current_enrollments',
        'student_skills',
        'student_study_plan_course_additions',
        'student_study_plan_overrides'
    ];

    foreach ($tablesWithStudentId as $table) {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE student_id = ?");
        if ($stmt) {
            $stmt->execute([$studentId]);
            $stmt->close();
        }
    }
    
    foreach ($tablesWithStudentNumber as $table) {
        $stmt = $conn->prepare("DELETE FROM `$table` WHERE student_number = ?");
        if ($stmt) {
            $stmt->execute([$studentId]);
            $stmt->close();
        }
    }
    
    $conn->commit();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    error_log("Delete Student Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error during deletion']);
}
