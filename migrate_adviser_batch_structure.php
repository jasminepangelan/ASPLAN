<?php
/**
 * Migration: Add adviser_id column to adviser_batch table
 * 
 * Run this script through browser: http://localhost/ASPLAN_v5/dev/migrate_adviser_batch_structure.php
 */

require_once __DIR__ . '/../config/database.php';

ob_start();

try {
    $conn = getDBConnection();
    
    // Check if adviser_id column exists
    $checkColQuery = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_NAME = 'adviser_batch' AND COLUMN_NAME = 'adviser_id'";
    $result = $conn->query($checkColQuery);
    
    if ($result->num_rows > 0) {
        echo json_encode([
            'status' => 'info',
            'message' => 'adviser_id column already exists in adviser_batch table'
        ]);
        ob_end_flush();
        exit;
    }
    
    // Add adviser_id column
    $alterQuery = "ALTER TABLE adviser_batch ADD COLUMN adviser_id INT(11) NOT NULL AFTER id";
    $conn->query($alterQuery);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Successfully added adviser_id column to adviser_batch table'
    ]);
    
    closeDBConnection($conn);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Migration failed: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>
