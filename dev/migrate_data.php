<?php
header('Content-Type: text/plain');
$conn = new mysqli('localhost', 'root', '');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "=== Data Migration from e_checklist to osas_db ===\n\n";

// Check if e_checklist exists
$db_check = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'e_checklist'");
if ($db_check->num_rows == 0) {
    die("ERROR: e_checklist database not found.\n");
}
echo "e_checklist found.\n";

// Switch to osas_db
$conn->select_db('osas_db');

// Add missing columns to adviser table
echo "\nAdding missing columns to adviser...\n";
$conn->query("ALTER TABLE adviser ADD COLUMN IF NOT EXISTS id INT AUTO_INCREMENT PRIMARY KEY FIRST");
$conn->query("ALTER TABLE adviser ADD COLUMN IF NOT EXISTS sex ENUM('Male','Female') DEFAULT NULL");
$conn->query("ALTER TABLE adviser ADD COLUMN IF NOT EXISTS pronoun ENUM('Mr.','Ms.','Mrs.') DEFAULT NULL");
echo "Done.\n";

// Migrate students to student_info
echo "\n--- Migrating students ---\n";
$result = $conn->query("SELECT * FROM e_checklist.students");
if ($result && $result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $conn->query("INSERT IGNORE INTO student_info (student_number, last_name, first_name, middle_name, password, email, contact_number, house_number_street, date_of_admission, status, created_at, remember_token, remember_token_expiry) VALUES (
            {$row['student_id']},
            '" . $conn->real_escape_string($row['last_name']) . "',
            '" . $conn->real_escape_string($row['first_name']) . "',
            '" . $conn->real_escape_string($row['middle_name'] ?? '') . "',
            '" . $conn->real_escape_string($row['password']) . "',
            '" . $conn->real_escape_string($row['email']) . "',
            '" . $conn->real_escape_string($row['contact_no'] ?? '') . "',
            '" . $conn->real_escape_string($row['address'] ?? '') . "',
            " . ($row['admission_date'] ? "'" . $row['admission_date'] . "'" : 'NULL') . ",
            '" . $conn->real_escape_string($row['status'] ?? 'approved') . "',
            " . ($row['created_at'] ? "'" . $row['created_at'] . "'" : 'NULL') . ",
            " . ($row['remember_token'] ? "'" . $conn->real_escape_string($row['remember_token']) . "'" : 'NULL') . ",
            " . ($row['remember_token_expiry'] ? "'" . $row['remember_token_expiry'] . "'" : 'NULL') . "
        )");
        if ($conn->affected_rows > 0) $count++;
    }
    echo "Migrated $count students\n";
} else {
    echo "No students found in e_checklist\n";
}

// Migrate admins
echo "\n--- Migrating admins ---\n";
$result = $conn->query("SELECT * FROM e_checklist.admins");
if ($result && $result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $name_parts = explode(' ', $row['full_name'], 2);
        $first_name = $conn->real_escape_string($name_parts[0] ?? 'Admin');
        $last_name = $conn->real_escape_string($name_parts[1] ?? '');
        
        $conn->query("INSERT IGNORE INTO admin (first_name, last_name, username, password) VALUES (
            '$first_name',
            '$last_name',
            '" . $conn->real_escape_string($row['username']) . "',
            '" . $conn->real_escape_string($row['password']) . "'
        )");
        if ($conn->affected_rows > 0) $count++;
    }
    echo "Migrated $count admins\n";
} else {
    echo "No admins found in e_checklist\n";
}

// Migrate advisers
echo "\n--- Migrating advisers ---\n";
$result = $conn->query("SELECT * FROM e_checklist.adviser");
if ($result && $result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $name_parts = explode(' ', $row['full_name'], 2);
        $first_name = $conn->real_escape_string($name_parts[0] ?? 'Adviser');
        $last_name = $conn->real_escape_string($name_parts[1] ?? '');
        $sex = isset($row['sex']) ? "'" . $conn->real_escape_string($row['sex']) . "'" : 'NULL';
        $pronoun = isset($row['pronoun']) ? "'" . $conn->real_escape_string($row['pronoun']) . "'" : 'NULL';
        
        $conn->query("INSERT IGNORE INTO adviser (first_name, last_name, username, password, sex, pronoun) VALUES (
            '$first_name',
            '$last_name',
            '" . $conn->real_escape_string($row['username']) . "',
            '" . $conn->real_escape_string($row['password']) . "',
            $sex,
            $pronoun
        )");
        if ($conn->affected_rows > 0) $count++;
    }
    echo "Migrated $count advisers\n";
} else {
    echo "No advisers found in e_checklist\n";
}

// Migrate adviser_batch
echo "\n--- Migrating adviser_batch ---\n";
$result = $conn->query("SELECT * FROM e_checklist.adviser_batch");
if ($result && $result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $conn->query("INSERT IGNORE INTO adviser_batch (id, batch) VALUES ({$row['id']}, {$row['batch']})");
        if ($conn->affected_rows > 0) $count++;
    }
    echo "Migrated $count adviser_batch entries\n";
} else {
    echo "No adviser_batch found in e_checklist\n";
}

// Migrate student_checklists
echo "\n--- Migrating student_checklists ---\n";
$result = $conn->query("SELECT * FROM e_checklist.student_checklists");
if ($result && $result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $grade = isset($row['grade']) && is_numeric($row['grade']) ? floatval($row['grade']) : 'NULL';
        $student_id = intval($row['student_id']);
        $course_code = $conn->real_escape_string($row['course_code']);
        
        $conn->query("INSERT IGNORE INTO student_checklists (student_number, course_code, final_grade) VALUES ($student_id, '$course_code', $grade)");
        if ($conn->affected_rows > 0) $count++;
    }
    echo "Migrated $count checklist entries\n";
} else {
    echo "No student_checklists found in e_checklist\n";
}

// Migrate system_settings
echo "\n--- Migrating system_settings ---\n";
$result = $conn->query("SELECT * FROM e_checklist.system_settings");
if ($result && $result->num_rows > 0) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $name = $conn->real_escape_string($row['setting_name']);
        $value = intval($row['setting_value']);
        $conn->query("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES ('$name', $value)");
        if ($conn->affected_rows > 0) $count++;
    }
    echo "Migrated $count settings\n";
} else {
    echo "No system_settings found in e_checklist\n";
}

// Add default settings
echo "\n--- Adding default settings ---\n";
$conn->query("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES ('auto_approve_students', 1)");

// Final counts
echo "\n=== Final Table Counts in osas_db ===\n";
$tables = ['student_info', 'admin', 'adviser', 'adviser_batch', 'student_checklists', 'system_settings'];
foreach ($tables as $table) {
    $result = $conn->query("SELECT COUNT(*) as cnt FROM $table");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "$table: {$row['cnt']} rows\n";
    }
}

echo "\n=== MIGRATION COMPLETE ===\n";
$conn->close();
?>
