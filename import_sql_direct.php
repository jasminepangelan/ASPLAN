<?php
$_ENV['MYSQL_PUBLIC_URL'] = 'mysql://root:PIlezyGzBauvijKewcPUtNqUtETTNcfP@hayabusa.proxy.rlwy.net:58143/railway';
require_once 'config/database.php';

try {
    $conn = getDBConnection();
    
    // Create temp tables
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

    $conn->query("DROP TABLE IF EXISTS temp_legacy_checklists");
    $conn->query("
        CREATE TABLE temp_legacy_checklists (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `student_id` varchar(50) NOT NULL,
          `course_code` varchar(20) NOT NULL,
          `final_grade` varchar(10) DEFAULT '',
          `evaluator_remarks` varchar(255) DEFAULT NULL,
          `professor_instructor` varchar(255) DEFAULT '',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $file = "dev/127_0_0_1.sql";
    $content = file_get_contents($file);
    
    $content = str_replace('INSERT INTO `students`', 'INSERT INTO `temp_legacy_students`', $content);
    $content = str_replace('INSERT INTO `student_checklists`', 'INSERT INTO `temp_legacy_checklists`', $content);
    
    preg_match_all('/INSERT INTO `temp_legacy_students`.*?\);\r?\n/s', $content, $stuInserts);
    foreach ($stuInserts[0] as $q) {
        $conn->query($q);
        if ($conn->error) echo "Error in student insert: " . $conn->error . "\n";
    }
    
    preg_match_all('/INSERT INTO `temp_legacy_checklists`.*?\);\r?\n/s', $content, $chkInserts);
    foreach ($chkInserts[0] as $q) {
        $conn->query($q);
        if ($conn->error) echo "Error in checklist insert: " . $conn->error . "\n";
    }

    $res = $conn->query("SELECT count(*) as c FROM temp_legacy_students");
    echo "Temp students: " . $res->fetch_assoc()['c'] . "\n";
    $res = $conn->query("SELECT count(*) as c FROM temp_legacy_checklists");
    echo "Temp checklists: " . $res->fetch_assoc()['c'] . "\n";

    $mlSql = "
        INSERT IGNORE INTO student_masterlist (student_number, last_name, first_name, middle_initial, program, source_filename, uploaded_by, uploaded_at)
        SELECT 
            student_id, 
            last_name, 
            first_name, 
            LEFT(IFNULL(middle_name, ''), 8), 
            'Bachelor of Science in Computer Science', 
            '127_0_0_1.sql', 
            'system_migration', 
            NOW()
        FROM temp_legacy_students
        WHERE student_id NOT IN (SELECT student_number FROM student_info)
    ";
    $conn->query($mlSql);
    echo "Inserted " . $conn->affected_rows . " missing students into masterlist.\n";

    $infoSql = "
        INSERT IGNORE INTO student_info (student_number, last_name, first_name, middle_name, email, password, contact_number, house_number_street, program, status, created_at)
        SELECT 
            student_id, 
            last_name, 
            first_name, 
            IFNULL(middle_name, ''), 
            email, 
            password, 
            IFNULL(contact_no, ''), 
            IFNULL(address, ''), 
            'Bachelor of Science in Computer Science', 
            IFNULL(status, 'approved'), 
            created_at
        FROM temp_legacy_students
        WHERE student_id NOT IN (SELECT student_number FROM student_info)
    ";
    $conn->query($infoSql);
    $newStudents = $conn->affected_rows;
    echo "Inserted {$newStudents} missing students into student_info.\n";

    $gradeSql = "
        INSERT IGNORE INTO student_checklists (student_id, course_code, final_grade, grade, evaluator_remarks, professor_instructor, status, created_at)
        SELECT 
            t.student_id, 
            t.course_code, 
            t.final_grade, 
            t.final_grade, 
            IFNULL(t.evaluator_remarks, ''), 
            IFNULL(t.professor_instructor, ''), 
            CASE 
                WHEN UPPER(t.final_grade) = 'S' THEN 'PASSED'
                WHEN CAST(t.final_grade AS DECIMAL(4,2)) > 0 AND CAST(t.final_grade AS DECIMAL(4,2)) <= 3.0 THEN 'PASSED'
                WHEN CAST(t.final_grade AS DECIMAL(4,2)) > 3.0 THEN 'FAILED'
                ELSE 'PENDING'
            END,
            NOW()
        FROM temp_legacy_checklists t
        WHERE NOT EXISTS (
            SELECT 1 FROM student_checklists sc 
            WHERE sc.student_id = t.student_id COLLATE utf8mb4_unicode_ci 
            AND sc.course_code = t.course_code COLLATE utf8mb4_unicode_ci
        )
    ";
    $conn->query($gradeSql);
    $newGrades = $conn->affected_rows;
    echo "Inserted {$newGrades} missing grades into student_checklists.\n";
    
    // Cleanup
    $conn->query("DROP TABLE IF EXISTS temp_legacy_students");
    $conn->query("DROP TABLE IF EXISTS temp_legacy_checklists");

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
