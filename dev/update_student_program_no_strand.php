<?php
/**
 * Update Student Program for Records with No Strand
 * 
 * Updates all student_info records with no strand value to set program to BSCS
 * Run this script through browser: http://localhost/ASPLAN_v5/dev/update_student_program_no_strand.php
 */

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Start output buffering for clean output
ob_start();

try {
    // Get database connection
    $conn = getDBConnection();
    
    // First, check how many records need updating
    $checkQuery = "SELECT COUNT(*) as count FROM student_info WHERE (strand IS NULL OR strand = '')";
    $checkResult = $conn->query($checkQuery);
    $countRow = $checkResult->fetch_assoc();
    $countToUpdate = $countRow['count'];
    
    if ($countToUpdate === 0) {
        echo json_encode([
            'status' => 'info',
            'message' => 'No students with empty strand found.',
            'count' => 0
        ]);
        ob_end_flush();
        exit;
    }
    
    // Prepare and execute the update query
    $updateQuery = "UPDATE student_info SET program = 'BSCS' WHERE (strand IS NULL OR strand = '')";
    
    if ($conn->query($updateQuery)) {
        $affectedRows = $conn->affected_rows;
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Student program updated successfully.',
            'records_checked' => $countToUpdate,
            'records_updated' => $affectedRows
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Update failed: ' . $conn->error
        ]);
    }
    
    // Close connection
    closeDBConnection($conn);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>
