<?php
require __DIR__ . '/../config/config.php';
$conn = getDBConnection();

echo "Students with completed courses:\n\n";

$query = $conn->query("
    SELECT 
        student_id, 
        COUNT(*) as total_records,
        COUNT(CASE WHEN grade_submitted_at IS NOT NULL 
              AND CAST(final_grade AS DECIMAL(3,1)) BETWEEN 1.0 AND 3.0 
              THEN 1 END) as completed_courses
    FROM student_checklists 
    GROUP BY student_id 
    HAVING completed_courses > 0 
    ORDER BY completed_courses DESC 
    LIMIT 10
");

while ($row = $query->fetch_assoc()) {
    echo "{$row['student_id']} - {$row['completed_courses']}/{$row['total_records']} courses completed\n";
}

closeDBConnection($conn);
?>
