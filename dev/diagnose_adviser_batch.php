<?php
/**
 * Diagnostic: Check adviser_batch table structure and constraints
 * 
 * Run this script through browser: http://localhost/ASPLAN_v5/dev/diagnose_adviser_batch.php
 */

require_once __DIR__ . '/../config/database.php';

ob_start();

try {
    $conn = getDBConnection();
    
    // Get table structure
    $tableQuery = "DESCRIBE adviser_batch";
    $result = $conn->query($tableQuery);
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }
    
    // Get constraints
    $constraintsQuery = "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                        WHERE TABLE_NAME = 'adviser_batch'";
    $constraintResult = $conn->query($constraintsQuery);
    $constraints = [];
    while ($row = $constraintResult->fetch_assoc()) {
        $constraints[] = $row;
    }
    
    // Get indexes
    $indexQuery = "SHOW INDEX FROM adviser_batch";
    $indexResult = $conn->query($indexQuery);
    $indexes = [];
    while ($row = $indexResult->fetch_assoc()) {
        $indexes[] = $row;
    }
    
    echo json_encode([
        'status' => 'success',
        'columns' => $columns,
        'constraints' => $constraints,
        'indexes' => $indexes
    ], JSON_PRETTY_PRINT);
    
    closeDBConnection($conn);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

ob_end_flush();
?>
