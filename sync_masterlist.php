<?php
require_once 'config/database.php';
try {
    $conn = getDBConnection();
    if ($conn) {
        // Sync all non-archived students back into the masterlist
        $sql = "
            INSERT INTO student_masterlist (student_number, last_name, first_name, middle_initial, program, source_filename, uploaded_by, uploaded_at)
            SELECT 
                student_number, 
                last_name, 
                first_name, 
                LEFT(middle_name, 8), 
                program, 
                'system_sync.csv', 
                'system', 
                NOW()
            FROM student_info
            WHERE status != 'archived'
            ON DUPLICATE KEY UPDATE
                last_name = VALUES(last_name),
                first_name = VALUES(first_name),
                middle_initial = VALUES(middle_initial),
                program = VALUES(program);
        ";
        
        if ($conn->query($sql)) {
            $count = $conn->affected_rows;
            echo "Successfully synchronized the masterlist with the registered students.\n";
            
            $masterlistCount = $conn->query("SELECT COUNT(*) as count FROM student_masterlist")->fetch_assoc()['count'];
            echo "New Authorized Rows count: " . $masterlistCount . "\n";
        } else {
            echo "Error syncing masterlist: " . $conn->error . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
