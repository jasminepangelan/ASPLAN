<?php
/**
 * Update all adviser passwords to "12345678"
 * Run this script once, then delete it
 */

require_once __DIR__ . '/../../config/database.php';

$newPassword = "12345678";
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$conn = getDBConnection();

$stmt = $conn->prepare("UPDATE adviser SET password = ?");
$stmt->bind_param("s", $hashedPassword);

if ($stmt->execute()) {
    $count = $stmt->affected_rows;
    echo "Successfully updated $count adviser account(s) with password '12345678'\n";
    echo "Hashed password: $hashedPassword\n";
    echo "\n⚠️ DELETE THIS SCRIPT after use for security!";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
closeDBConnection($conn);
