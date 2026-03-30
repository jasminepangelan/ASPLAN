<?php
require __DIR__ . '/../config/config.php';

$student_id = $argv[1] ?? '220100064';
$conn = getDBConnection();

echo "=== Analyzing Student $student_id's Grades ===\n\n";

// Get all grades with details
$query = $conn->prepare("
    SELECT 
        CASE c.year_level
            WHEN 'First Year' THEN '1st Yr'
            WHEN 'Second Year' THEN '2nd Yr'
            WHEN 'Third Year' THEN '3rd Yr'
            WHEN 'Fourth Year' THEN '4th Yr'
            ELSE c.year_level
        END AS year,
        CASE c.semester
            WHEN 'First Semester' THEN '1st Sem'
            WHEN 'Second Semester' THEN '2nd Sem'
            WHEN 'Mid Year' THEN 'Mid Year'
            WHEN 'Midyear' THEN 'Mid Year'
            ELSE c.semester
        END AS semester,
        sc.course_code,
        sc.final_grade,
        sc.grade_submitted_at,
        sc.submitted_by,
        sc.grade_approved
    FROM student_checklists sc
    JOIN cvsucarmona_courses c ON SUBSTRING(c.curriculumyear_coursecode, 6) = sc.course_code
    WHERE FIND_IN_SET('BSCS', REPLACE(c.programs, ', ', ',')) > 0
    AND sc.student_id = ?
    ORDER BY 
        FIELD(c.year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
        FIELD(c.semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear'),
        sc.course_code
");
$query->bind_param("s", $student_id);
$query->execute();
$result = $query->get_result();

$by_year_sem = [];
$total_with_grades = 0;
$total_with_timestamps = 0;

while ($row = $result->fetch_assoc()) {
    $key = $row['year'] . ' - ' . $row['semester'];
    if (!isset($by_year_sem[$key])) {
        $by_year_sem[$key] = [];
    }
    $by_year_sem[$key][] = $row;
    
    if (!empty($row['final_grade']) && $row['final_grade'] != '') {
        $total_with_grades++;
    }
    if (!empty($row['grade_submitted_at'])) {
        $total_with_timestamps++;
    }
}

echo "Summary:\n";
echo "- Total records: " . $result->num_rows . "\n";
echo "- Records with grades: $total_with_grades\n";
echo "- Records with timestamps: $total_with_timestamps\n\n";

foreach ($by_year_sem as $year_sem => $courses) {
    echo "=== $year_sem ===\n";
    $count_with_grades = 0;
    $count_with_timestamps = 0;
    
    foreach ($courses as $course) {
        $has_grade = !empty($course['final_grade']) && $course['final_grade'] != '';
        $has_timestamp = !empty($course['grade_submitted_at']);
        
        if ($has_grade) $count_with_grades++;
        if ($has_timestamp) $count_with_timestamps++;
        
        if ($has_grade || $has_timestamp) {
            $status = $has_timestamp ? '[HAS TIMESTAMP]' : '[NO TIMESTAMP]';
            $grade = $has_grade ? $course['final_grade'] : '(empty)';
            $submitted = $has_timestamp ? $course['grade_submitted_at'] : 'NULL';
            
            echo "  {$course['course_code']}: $grade $status (submitted: $submitted)\n";
        }
    }
    
    echo "  Subtotal: $count_with_grades with grades, $count_with_timestamps with timestamps\n\n";
}

closeDBConnection($conn);
?>
