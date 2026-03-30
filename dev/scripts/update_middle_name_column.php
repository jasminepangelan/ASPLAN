<?php
/**
 * Database migration script to update middle_name column to allow NULL values
 * This ensures students without middle names can be properly stored in the database
 */

// Database connection parameters
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'e_checklist';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Starting middle_name column update...\n";

try {
    // First, check current column definition
    $check_query = "SHOW COLUMNS FROM students LIKE 'middle_name'";
    $result = $conn->query($check_query);
    
    if ($result && $result->num_rows > 0) {
        $column_info = $result->fetch_assoc();
        echo "Current middle_name column definition:\n";
        echo "Type: " . $column_info['Type'] . "\n";
        echo "Null: " . $column_info['Null'] . "\n";
        echo "Default: " . $column_info['Default'] . "\n\n";
        
        // Update column to allow NULL values
        $alter_query = "ALTER TABLE students MODIFY COLUMN middle_name VARCHAR(100) NULL";
        
        if ($conn->query($alter_query) === TRUE) {
            echo "✓ Successfully updated middle_name column to allow NULL values\n";
            
            // Update any existing empty string middle names to NULL
            $update_query = "UPDATE students SET middle_name = NULL WHERE middle_name = '' OR middle_name IS NULL";
            
            if ($conn->query($update_query) === TRUE) {
                $affected_rows = $conn->affected_rows;
                echo "✓ Updated $affected_rows rows with empty middle names to NULL\n";
            } else {
                echo "⚠ Warning: Could not update existing empty middle names: " . $conn->error . "\n";
            }
            
        } else {
            echo "✗ Error updating middle_name column: " . $conn->error . "\n";
        }
        
        // Verify the change
        $verify_result = $conn->query($check_query);
        if ($verify_result && $verify_result->num_rows > 0) {
            $new_column_info = $verify_result->fetch_assoc();
            echo "\nUpdated middle_name column definition:\n";
            echo "Type: " . $new_column_info['Type'] . "\n";
            echo "Null: " . $new_column_info['Null'] . "\n";
            echo "Default: " . $new_column_info['Default'] . "\n";
        }
        
    } else {
        echo "✗ Error: middle_name column not found in students table\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error occurred: " . $e->getMessage() . "\n";
}

$conn->close();
echo "\nMiddle name column update completed.\n";
?>
