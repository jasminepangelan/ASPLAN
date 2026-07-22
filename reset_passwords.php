<?php
require_once 'config/database.php';
try {
    $conn = getDBConnection();
    if ($conn) {
        $newPassword = password_hash('12345678', PASSWORD_BCRYPT);
        
        $stmt = $conn->prepare("UPDATE student_info SET password = ?");
        $stmt->bind_param("s", $newPassword);
        
        if ($stmt->execute()) {
            echo "Successfully updated passwords for " . $stmt->affected_rows . " student accounts.\n";
        } else {
            echo "Failed to update passwords: " . $conn->error . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
