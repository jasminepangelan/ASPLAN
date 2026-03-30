<?php
require_once __DIR__ . '/../../config/database.php';
$conn = getDBConnection();

// Get distinct programs
$programs = [];
$r = $conn->query("SELECT DISTINCT programs FROM cvsucarmona_courses WHERE course_title IS NOT NULL AND course_title != '' AND (credit_units_lec > 0 OR credit_units_lab > 0)");
while ($row = $r->fetch_assoc()) {
    foreach (explode(',', str_replace(', ', ',', $row['programs'])) as $p) {
        $programs[trim($p)] = true;
    }
}
$programs = array_keys($programs);
sort($programs);

echo str_pad('PROGRAM', 15) . str_pad('YEAR', 10) . str_pad('SEM', 10) . str_pad('UNITS', 8) . "COURSES\n";
echo str_repeat('-', 55) . "\n";

foreach ($programs as $prog) {
    $stmt = $conn->prepare("
        SELECT 
            CASE year_level
                WHEN 'First Year' THEN '1st Yr'
                WHEN 'Second Year' THEN '2nd Yr'
                WHEN 'Third Year' THEN '3rd Yr'
                WHEN 'Fourth Year' THEN '4th Yr'
                ELSE year_level
            END AS yr,
            CASE semester
                WHEN 'First Semester' THEN '1st Sem'
                WHEN 'Second Semester' THEN '2nd Sem'
                WHEN 'Mid Year' THEN 'Mid Year'
                WHEN 'Midyear' THEN 'Mid Year'
                ELSE semester
            END AS sem,
            SUM(credit_units_lec + credit_units_lab) AS total_units,
            COUNT(*) AS course_count
        FROM cvsucarmona_courses
        WHERE FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0
        AND course_title IS NOT NULL AND course_title != ''
        AND (credit_units_lec > 0 OR credit_units_lab > 0)
        GROUP BY yr, sem
        ORDER BY FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
                 FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year')
    ");
    $stmt->bind_param("s", $prog);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        echo str_pad($prog, 15) . str_pad($row['yr'], 10) . str_pad($row['sem'], 10) . str_pad($row['total_units'], 8) . $row['course_count'] . "\n";
    }
    $stmt->close();
}
$conn->close();
