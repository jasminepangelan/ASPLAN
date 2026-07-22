<?php
require_once 'config/database.php';
try {
    $conn = getDBConnection();
    if ($conn) {
        $student_num = '240100001';
        
        $stmt = $conn->prepare("SELECT * FROM student_masterlist WHERE student_number = ?");
        $stmt->bind_param("s", $student_num);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Check if already exists
            $check = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ?");
            $check->bind_param("s", $student_num);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                echo "$student_num already exists in student_info.\n";
            } else {
                $insertStmt = $conn->prepare("
                    INSERT INTO student_info (
                        student_number, last_name, first_name, middle_name, 
                        program, status, password, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'approved', ?, NOW())
                ");
                
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
                    echo "Account created for $student_num successfully!\n";
                } else {
                    echo "Failed to insert $student_num: " . $conn->error . "\n";
                }
            }
        } else {
            echo "Student not found in masterlist.\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
