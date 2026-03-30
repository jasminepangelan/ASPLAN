<?php
// Database initialization script
// This script ensures all necessary tables and settings exist

$host = 'localhost';
$db = 'e_checklist';
$user = 'root';
$pass = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create system_settings table if it doesn't exist
    $conn->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_name VARCHAR(255) UNIQUE NOT NULL,
            setting_value TEXT NOT NULL,
            updated_by VARCHAR(255),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Check if auto_approve_students setting exists, if not create it
    $stmt = $conn->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_name = 'auto_approve_students'");
    $stmt->execute();
    $exists = $stmt->fetchColumn();
    
    if (!$exists) {
        // Insert default setting (auto-approval disabled by default)
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_name, setting_value, updated_by) VALUES ('auto_approve_students', '0', 'system')");
        $stmt->execute();
        echo "✓ Auto-approval setting initialized (disabled by default)\n";
    }
    
    // Ensure students table has status column
    $stmt = $conn->prepare("SHOW COLUMNS FROM students LIKE 'status'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        // Add status column if it doesn't exist
        $conn->exec("ALTER TABLE students ADD COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
        echo "✓ Status column added to students table\n";
    }
    
    // Ensure students table has approval tracking columns
    $stmt = $conn->prepare("SHOW COLUMNS FROM students LIKE 'approved_by'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE students ADD COLUMN approved_by VARCHAR(255) NULL");
        echo "✓ Approved_by column added to students table\n";
    }
    
    $stmt = $conn->prepare("SHOW COLUMNS FROM students LIKE 'approved_at'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE students ADD COLUMN approved_at TIMESTAMP NULL");
        echo "✓ Approved_at column added to students table\n";
    }
    
    // Ensure students table has created_at column
    $stmt = $conn->prepare("SHOW COLUMNS FROM students LIKE 'created_at'");
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE students ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "✓ Created_at column added to students table\n";
    }
    
    // Update any existing students without status to 'approved' (backward compatibility)
    $stmt = $conn->prepare("UPDATE students SET status = 'approved' WHERE status IS NULL OR status = ''");
    $affected = $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "✓ Updated " . $stmt->rowCount() . " existing students to approved status\n";
    }
    
    echo "✓ Database initialization completed successfully!\n";
    echo "✓ Account approval system is ready to use.\n";
    echo "\nAccess the account management interface at: admin/account_approval_settings.php\n";
    
} catch (PDOException $e) {
    echo "❌ Database initialization failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
