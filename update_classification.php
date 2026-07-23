<?php
$_ENV['MYSQL_PUBLIC_URL'] = 'mysql://root:PIlezyGzBauvijKewcPUtNqUtETTNcfP@hayabusa.proxy.rlwy.net:58143/railway';
require_once 'config/database.php';

try {
    $conn = getDBConnection();
    
    // Create temp table for students from dump
    $conn->query("DROP TABLE IF EXISTS temp_legacy_students");
    $conn->query("
        CREATE TABLE temp_legacy_students (
          `student_id` varchar(50) NOT NULL,
          `last_name` varchar(50) NOT NULL,
          `first_name` varchar(50) NOT NULL,
          `middle_name` varchar(50) DEFAULT NULL,
          `email` varchar(255) NOT NULL,
          `password` varchar(255) NOT NULL,
          `contact_no` varchar(50) DEFAULT NULL,
          `address` varchar(255) DEFAULT NULL,
          `admission_date` date DEFAULT NULL,
          `picture` varchar(255) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `status` enum('pending','approved','rejected') DEFAULT 'pending',
          `remember_token` varchar(255) DEFAULT NULL,
          `remember_token_expiry` datetime DEFAULT NULL,
          PRIMARY KEY (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $file = "dev/127_0_0_1.sql";
    $content = file_get_contents($file);
    
    $content = str_replace('INSERT INTO `students`', 'INSERT INTO `temp_legacy_students`', $content);
    preg_match_all('/INSERT INTO `temp_legacy_students`.*?\);\r?\n/s', $content, $stuInserts);
    foreach ($stuInserts[0] as $q) {
        $conn->query($q);
    }
    
    $res = $conn->query("SELECT count(*) as c FROM temp_legacy_students");
    $count = $res->fetch_assoc()['c'];
    echo "Loaded $count students into temp table.\n";

    if ($count > 0) {
        // "Old" is the value for registration_classification which maps to "Student Classification" in the UI.
        $sql = "UPDATE student_info SET registration_classification = 'Old' WHERE student_number IN (SELECT student_id FROM temp_legacy_students)";
        $conn->query($sql);
        echo "Successfully updated Student Classification to 'Old' for " . $conn->affected_rows . " student accounts.\n";
    }
    
    $conn->query("DROP TABLE IF EXISTS temp_legacy_students");

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
