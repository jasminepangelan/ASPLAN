<?php
/**
 * Rebuild osas_db from e_checklist data
 * Run this script once to fix the corrupted database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli('localhost', 'root', '');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Connected to MySQL\n";

// Drop and recreate osas_db
echo "Dropping osas_db...\n";
$conn->query("DROP DATABASE IF EXISTS osas_db");

echo "Creating osas_db...\n";
$conn->query("CREATE DATABASE osas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db('osas_db');

// Create student_info table
echo "Creating student_info table...\n";
$conn->query("
CREATE TABLE student_info (
  student_number INT(9) NOT NULL PRIMARY KEY,
  last_name VARCHAR(255) DEFAULT NULL,
  first_name VARCHAR(255) DEFAULT NULL,
  middle_name VARCHAR(255) DEFAULT NULL,
  contact_number VARCHAR(50) DEFAULT NULL,
  date_of_admission DATE DEFAULT NULL,
  stud_landline VARCHAR(255) DEFAULT NULL,
  program VARCHAR(255) DEFAULT NULL,
  cvsu_email VARCHAR(255) DEFAULT NULL,
  if_ojt VARCHAR(255) DEFAULT NULL,
  house_number_street VARCHAR(255) DEFAULT NULL,
  brgy VARCHAR(255) DEFAULT NULL,
  town VARCHAR(255) DEFAULT NULL,
  province VARCHAR(255) DEFAULT NULL,
  zip_code INT(10) DEFAULT NULL,
  prefix VARCHAR(255) DEFAULT NULL,
  suffix VARCHAR(255) DEFAULT NULL,
  stud_classification VARCHAR(255) DEFAULT NULL,
  reg_status VARCHAR(255) DEFAULT NULL,
  date_of_birth DATE DEFAULT NULL,
  place_of_birth VARCHAR(255) DEFAULT NULL,
  age INT DEFAULT NULL,
  sex VARCHAR(255) DEFAULT NULL,
  religion VARCHAR(255) DEFAULT NULL,
  nationality VARCHAR(255) DEFAULT NULL,
  civil_status VARCHAR(255) DEFAULT NULL,
  parent_guardian VARCHAR(255) DEFAULT NULL,
  parent_guardian_addrs VARCHAR(255) DEFAULT NULL,
  parent_guardian_occup VARCHAR(255) DEFAULT NULL,
  parent_guardian_landline VARCHAR(255) DEFAULT NULL,
  parent_guardian_number INT(11) DEFAULT NULL,
  student_special_popu VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  created_at DATETIME DEFAULT NULL,
  status VARCHAR(255) DEFAULT 'approved',
  approved_by VARCHAR(255) DEFAULT NULL,
  year_level TINYINT DEFAULT NULL,
  remember_token VARCHAR(255) DEFAULT NULL,
  remember_token_expiry DATETIME DEFAULT NULL,
  ojt_completed BOOLEAN DEFAULT NULL,
  ojt_eligible BOOLEAN DEFAULT NULL,
  general_weighted_average DECIMAL(3,2) DEFAULT NULL,
  course VARCHAR(100) DEFAULT NULL,
  section VARCHAR(20) DEFAULT NULL,
  honors VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create admin table
echo "Creating admin table...\n";
$conn->query("
CREATE TABLE admin (
  last_name VARCHAR(255) DEFAULT NULL,
  first_name VARCHAR(255) DEFAULT NULL,
  middle_name VARCHAR(255) DEFAULT NULL,
  username VARCHAR(255) NOT NULL PRIMARY KEY,
  password VARCHAR(255) NOT NULL,
  prefix VARCHAR(255) DEFAULT NULL,
  suffix VARCHAR(255) DEFAULT NULL,
  admin_email VARCHAR(255) DEFAULT NULL,
  can_manage_job BOOLEAN DEFAULT NULL,
  can_manage_user BOOLEAN DEFAULT NULL,
  can_manage_announcement BOOLEAN DEFAULT NULL,
  can_view_reports BOOLEAN DEFAULT NULL,
  admin_id INT DEFAULT NULL,
  department VARCHAR(255) DEFAULT NULL,
  position VARCHAR(255) DEFAULT NULL,
  office_location VARCHAR(255) DEFAULT NULL,
  internal_phone VARCHAR(255) DEFAULT NULL,
  admin_level ENUM('super_admin', 'admin', 'moderator') DEFAULT NULL,
  can_manage_users BOOLEAN DEFAULT NULL,
  can_manage_jobs BOOLEAN DEFAULT NULL,
  can_manage_announcements BOOLEAN DEFAULT NULL,
  last_activity DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create adviser table
echo "Creating adviser table...\n";
$conn->query("
CREATE TABLE adviser (
  last_name VARCHAR(255) DEFAULT NULL,
  first_name VARCHAR(255) DEFAULT NULL,
  middle_name VARCHAR(255) DEFAULT NULL,
  username VARCHAR(255) NOT NULL PRIMARY KEY,
  password VARCHAR(255) DEFAULT NULL,
  prefix VARCHAR(255) DEFAULT NULL,
  suffix VARCHAR(255) DEFAULT NULL,
  id INT DEFAULT NULL,
  adviser_email VARCHAR(255) DEFAULT NULL,
  sex ENUM('Male','Female') DEFAULT NULL,
  pronoun ENUM('Mr.','Ms.','Mrs.') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create adviser_batch table
echo "Creating adviser_batch table...\n";
$conn->query("
CREATE TABLE adviser_batch (
  id INT NOT NULL,
  batch INT NOT NULL,
  PRIMARY KEY (id, batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create system_settings table
echo "Creating system_settings table...\n";
$conn->query("
CREATE TABLE system_settings (
  setting_name VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create password_resets table
echo "Creating password_resets table...\n";
$conn->query("
CREATE TABLE password_resets (
  email VARCHAR(255) NOT NULL PRIMARY KEY,
  code VARCHAR(10) DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create student_checklists table
echo "Creating student_checklists table...\n";
$conn->query("
CREATE TABLE student_checklists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(20) NOT NULL,
  course_code VARCHAR(20) NOT NULL,
  status ENUM('pending','enrolled','passed','failed') DEFAULT 'pending',
  grade VARCHAR(10) DEFAULT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  school_year VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_student_course (student_id, course_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create programs table
echo "Creating programs table...\n";
$conn->query("
CREATE TABLE programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_code VARCHAR(20) NOT NULL,
  program_name VARCHAR(255) NOT NULL,
  department VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Create curriculum_courses table
echo "Creating curriculum_courses table...\n";
$conn->query("
CREATE TABLE curriculum_courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(20) NOT NULL,
  course_name VARCHAR(255) NOT NULL,
  units INT DEFAULT 3,
  year_level INT DEFAULT NULL,
  semester VARCHAR(20) DEFAULT NULL,
  program_code VARCHAR(20) DEFAULT NULL,
  prerequisites TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Now migrate data from e_checklist
echo "\nMigrating data from e_checklist...\n";

// Migrate students to student_info
echo "Migrating students...\n";
$result = $conn->query("SELECT * FROM e_checklist.students");
if ($result) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $stmt = $conn->prepare("INSERT INTO student_info (student_number, last_name, first_name, middle_name, password, email, contact_number, house_number_street, date_of_admission, status, created_at, remember_token, remember_token_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
        $stmt->execute();
        $count++;
    }
    echo "  Migrated $count students\n";
}

// Migrate admins
echo "Migrating admins...\n";
$result = $conn->query("SELECT * FROM e_checklist.admins");
if ($result) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        // Split full_name into parts
        $name_parts = explode(' ', $row['full_name'], 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = $name_parts[1] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO admin (first_name, last_name, username, password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $first_name, $last_name, $row['username'], $row['password']);
        $stmt->execute();
        $count++;
    }
    echo "  Migrated $count admins\n";
}

// Migrate advisers
echo "Migrating advisers...\n";
$result = $conn->query("SELECT * FROM e_checklist.adviser");
if ($result) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        // Split full_name into parts
        $name_parts = explode(' ', $row['full_name'], 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = $name_parts[1] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO adviser (id, first_name, last_name, username, password, sex, pronoun) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssss", $row['id'], $first_name, $last_name, $row['username'], $row['password'], $row['sex'], $row['pronoun']);
        $stmt->execute();
        $count++;
    }
    echo "  Migrated $count advisers\n";
}

// Migrate adviser_batch
echo "Migrating adviser batches...\n";
$result = $conn->query("SELECT * FROM e_checklist.adviser_batch");
if ($result) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $stmt = $conn->prepare("INSERT INTO adviser_batch (id, batch) VALUES (?, ?)");
        $stmt->bind_param("ii", $row['id'], $row['batch']);
        $stmt->execute();
        $count++;
    }
    echo "  Migrated $count adviser batches\n";
}

// Migrate student_checklists
echo "Migrating student checklists...\n";
$result = $conn->query("SELECT * FROM e_checklist.student_checklists");
if ($result) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $stmt = $conn->prepare("INSERT IGNORE INTO student_checklists (student_id, course_code, status, grade, semester, school_year) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $row['student_id'], $row['course_code'], $row['status'], $row['grade'], $row['semester'], $row['school_year']);
        $stmt->execute();
        $count++;
    }
    echo "  Migrated $count checklist entries\n";
}

// Migrate system_settings
echo "Migrating system settings...\n";
$result = $conn->query("SELECT * FROM e_checklist.system_settings");
if ($result) {
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_name, setting_value) VALUES (?, ?)");
        $stmt->bind_param("ss", $row['setting_name'], $row['setting_value']);
        $stmt->execute();
        $count++;
    }
    echo "  Migrated $count settings\n";
}

// Add default auto-approve setting if not exists
$conn->query("INSERT IGNORE INTO system_settings (setting_name, setting_value) VALUES ('auto_approve_students', '1')");

echo "\n=== Migration Complete ===\n";
echo "You can now login using your e_checklist credentials.\n";

$conn->close();
?>
