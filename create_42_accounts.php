<?php
require_once 'config/database.php';
try {
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare("SELECT * FROM student_masterlist WHERE uploaded_at = '2026-07-22 07:17:05'");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $insertStmt = $conn->prepare("
            INSERT INTO student_info (
                student_number, last_name, first_name, middle_name, 
                program, status, password, created_at
            ) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW())
        ");
        
        $created = 0;
        $failed = 0;
        
        while ($row = $result->fetch_assoc()) {
            $student_num = $row['student_number'];
            
            // Check if already exists
            $check = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ?");
            $check->bind_param("s", $student_num);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                echo "- $student_num already exists in student_info.\n";
                $failed++;
                continue;
            }
            
            $defaultPassword = password_hash($student_num, PASSWORD_BCRYPT);
            
            $insertStmt->bind_param(
                "ssssss",
                $student_num,
                $row['last_name'],
                $row['first_name'],
                $row['middle_initial'],
                $row['program'],
                $defaultPassword
            );
            
            if ($insertStmt->execute()) {
                $created++;
            } else {
                echo "- Failed to insert $student_num: " . $conn->error . "\n";
                $failed++;
            }
        }
        
        echo "\nSummary: Created $created accounts. Skipped/Failed: $failed.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
