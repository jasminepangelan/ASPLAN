<?php
/**
 * Rebuild osas_db using schema file and migrate data from e_checklist
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

echo "<pre>";
echo "=== OSAS_DB Rebuild Script ===\n\n";

$conn = new mysqli('localhost', 'root', '');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "Connected to MySQL\n";

// Step 1: Drop osas_db
echo "\nStep 1: Dropping osas_db...\n";
$conn->query("DROP DATABASE IF EXISTS osas_db");
echo "  Done.\n";

// Step 2: Create database
echo "\nStep 2: Creating osas_db...\n";
$conn->query("CREATE DATABASE osas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db('osas_db');
echo "  Done.\n";

// Step 3: Import schema from file
echo "\nStep 3: Importing schema from osas_db_schema_final...\n";
$schema_file = __DIR__ . '/../osas_db_schema_final';
if (!file_exists($schema_file)) {
    die("ERROR: Schema file not found at: $schema_file\n");
}

$schema_content = file_get_contents($schema_file);

// Split into individual statements
$statements = [];
$current_stmt = '';
$lines = explode("\n", $schema_content);
foreach ($lines as $line) {
    $trimmed = trim($line);
    // Skip comments and empty lines
    if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
        continue;
    }
    $current_stmt .= ' ' . $line;
    if (substr($trimmed, -1) === ';') {
        $statements[] = trim($current_stmt);
        $current_stmt = '';
    }
}

$success_count = 0;
$error_count = 0;
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if (empty($stmt)) continue;
    
    // Skip USE and CREATE DATABASE statements (we already selected db)
    if (stripos($stmt, 'USE `') === 0 || stripos($stmt, 'CREATE DATABASE') !== false) {
        continue;
    }
    
    if ($conn->query($stmt)) {
        $success_count++;
    } else {
        // Only show actual errors, not warnings
        if ($conn->errno != 0) {
            echo "  Warning: " . $conn->error . "\n";
            $error_count++;
        }
    }
}
echo "  Executed $success_count statements, $error_count errors\n";

// Step 4: Add missing columns
echo "\nStep 4: Adding missing columns...\n";
$conn->query("ALTER TABLE adviser ADD COLUMN IF NOT EXISTS id INT DEFAULT NULL");
$conn->query("ALTER TABLE adviser ADD COLUMN IF NOT EXISTS sex ENUM('Male','Female') DEFAULT NULL");
$conn->query("ALTER TABLE adviser ADD COLUMN IF NOT EXISTS pronoun ENUM('Mr.','Ms.','Mrs.') DEFAULT NULL");
echo "  Done.\n";

// Step 5: Create password_resets if not exists
echo "\nStep 5: Ensuring password_resets table...\n";
$conn->query("
CREATE TABLE IF NOT EXISTS password_resets (
  email VARCHAR(255) NOT NULL PRIMARY KEY,
  code VARCHAR(10) DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "  Done.\n";

// Step 6: Verify tables
echo "\nStep 6: Verifying tables created...\n";
$result = $conn->query("SHOW TABLES FROM osas_db");
$tables = [];
while ($row = $result->fetch_array()) {
    $tables[] = $row[0];
}
echo "  Created " . count($tables) . " tables\n";

// Step 7: Migrate data from e_checklist
echo "\n=== Migrating Data from e_checklist ===\n";

// Check if e_checklist exists
$db_check = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'e_checklist'");
if ($db_check->num_rows == 0) {
    echo "Warning: e_checklist database not found. Skipping data migration.\n";
} else {
    // Migrate students
    echo "\nMigrating students...\n";
    $result = $conn->query("SELECT * FROM e_checklist.students");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $stmt = $conn->prepare("INSERT INTO student_info (student_number, last_name, first_name, middle_name, password, email, contact_number, house_number_street, date_of_admission, status, created_at, remember_token, remember_token_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("issssssssssss", 
                    $row['student_id'],
                    $row['last_name'],
                    $row['first_name'],
                    $row['middle_name'],
                    $row['password'],
                    $row['email'],
                    $row['contact_no'],
                    $row['address'],
                    $row['admission_date'],
                    $row['status'],
                    $row['created_at'],
                    $row['remember_token'],
                    $row['remember_token_expiry']
                );
                if ($stmt->execute()) {
                    $count++;
                }
                $stmt->close();
            }
        }
        echo "  Migrated $count students\n";
    }

    // Migrate admins
    echo "\nMigrating admins...\n";
    $result = $conn->query("SELECT * FROM e_checklist.admins");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $name_parts = explode(' ', $row['full_name'], 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO admin (first_name, last_name, username, password) VALUES (?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("ssss", $first_name, $last_name, $row['username'], $row['password']);
                if ($stmt->execute()) {
                    $count++;
                }
                $stmt->close();
            }
        }
        echo "  Migrated $count admins\n";
    }

    // Migrate advisers
    echo "\nMigrating advisers...\n";
    $result = $conn->query("SELECT * FROM e_checklist.adviser");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $name_parts = explode(' ', $row['full_name'], 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO adviser (id, first_name, last_name, username, password, sex, pronoun) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("issssss", $row['id'], $first_name, $last_name, $row['username'], $row['password'], $row['sex'], $row['pronoun']);
                if ($stmt->execute()) {
                    $count++;
                }
                $stmt->close();
            }
        }
        echo "  Migrated $count advisers\n";
    }

    // Migrate batches
    echo "\nMigrating batches...\n";
    $result = $conn->query("SELECT DISTINCT batch FROM e_checklist.adviser_batch");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $batch_val = $row['batch'];
            $batch_str = strval($batch_val);
            $conn->query("INSERT IGNORE INTO batches (id, batches) VALUES ($batch_val, '$batch_str')");
            $count++;
        }
        echo "  Migrated $count batches\n";
    }

    // Migrate adviser_batch
    echo "\nMigrating adviser_batch...\n";
    $result = $conn->query("SELECT * FROM e_checklist.adviser_batch");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $conn->query("INSERT IGNORE INTO adviser_batch (id, batch) VALUES ({$row['id']}, {$row['batch']})");
            $count++;
        }
        echo "  Migrated $count adviser_batch entries\n";
    }

    // Migrate student_checklists
    echo "\nMigrating student_checklists...\n";
    $result = $conn->query("SELECT * FROM e_checklist.student_checklists");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $grade = isset($row['grade']) && is_numeric($row['grade']) ? floatval($row['grade']) : 'NULL';
            $student_id = intval($row['student_id']);
            $course_code = $conn->real_escape_string($row['course_code']);
            $conn->query("INSERT IGNORE INTO student_checklists (student_number, course_code, final_grade) VALUES ($student_id, '$course_code', $grade)");
            $count++;
        }
        echo "  Migrated $count checklist entries\n";
    }

    // Migrate system_settings
    echo "\nMigrating system_settings...\n";
    $result = $conn->query("SELECT * FROM e_checklist.system_settings");
    if ($result && $result->num_rows > 0) {
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $name = $conn->real_escape_string($row['setting_name']);
            $value = intval($row['setting_value']);
            $conn->query("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES ('$name', $value)");
            $count++;
        }
        echo "  Migrated $count settings\n";
    }
}

// Step 8: Add default settings
echo "\nStep 8: Adding default settings...\n";
$conn->query("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES ('auto_approve_students', 1)");
echo "  Done.\n";

// Step 9: Final counts
echo "\n=== Final Table Counts ===\n";
$check_tables = ['student_info', 'admin', 'adviser', 'adviser_batch', 'batches', 'student_checklists', 'system_settings', 'password_resets'];
foreach ($check_tables as $table) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM $table");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "  $table: {$row['cnt']} rows\n";
    } else {
        echo "  $table: TABLE NOT FOUND\n";
    }
}

echo "\n=== MIGRATION COMPLETE ===\n";
echo "You can now login with your existing credentials.\n";
echo "</pre>";

$conn->close();
?>
