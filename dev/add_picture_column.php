<?php
require_once __DIR__ . '/../config/config.php';

$conn = getDBConnection();

$result = $conn->query("SHOW COLUMNS FROM student_info LIKE 'picture'");
if ($result->num_rows > 0) {
    echo "picture column already exists\n";
} else {
    $conn->query("ALTER TABLE student_info ADD COLUMN picture VARCHAR(255) DEFAULT NULL AFTER middle_name");
    if ($conn->error) {
        echo "Error: " . $conn->error . "\n";
    } else {
        echo "picture column added successfully\n";
    }
}

closeDBConnection($conn);
