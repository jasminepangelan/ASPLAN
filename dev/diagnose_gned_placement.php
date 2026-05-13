<?php
require_once __DIR__ . '/../config/config.php';

$student_id = '220100021';
$program = 'Bachelor of Science in Computer Engineering';

$conn = getDBConnection();

echo "=== DIAGNOSTIC: GNED 14 and GNED 09 Placement for Student $student_id (BSCpE) ===\n\n";

// 1. Check curriculum mapping for GNED 14 and GNED 09 in BSCpE
echo "1. BSCpE Curriculum Mapping for GNED Courses:\n";
$sql = "
    SELECT 
        TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
        year_level,
        semester,
        course_title,
        credit_units_lec,
        credit_units_lab,
        pre_requisite
    FROM curriculum_courses
    WHERE program LIKE '%Computer Engineering%'
    AND (
        UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) = 'GNED 14'
        OR UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) = 'GNED 09'
    )
    ORDER BY year_level, semester
";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  " . $row['course_code'] . " -> " . $row['year_level'] . " | " . $row['semester'] . "\n";
        echo "    Title: " . $row['course_title'] . "\n";
        echo "    Units: " . ((float)$row['credit_units_lec'] + (float)$row['credit_units_lab']) . " (" . $row['credit_units_lec'] . " Lec + " . $row['credit_units_lab'] . " Lab)\n";
        echo "    Prerequisite: " . $row['pre_requisite'] . "\n\n";
    }
} else {
    echo "  No GNED 14 or GNED 09 found in curriculum_courses for BSCpE\n";
}

// 2. Check legacy curriculum mapping
echo "\n2. Legacy Curriculum (cvsucarmona_courses) for GNED Courses:\n";
$sql = "
    SELECT 
        TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
        year_level,
        semester,
        course_title,
        credit_units_lec,
        credit_units_lab,
        pre_requisite
    FROM cvsucarmona_courses
    WHERE programs LIKE '%BSCpE%'
    AND (
        UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) = 'GNED 14'
        OR UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) = 'GNED 09'
    )
    ORDER BY year_level, semester
";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  " . $row['course_code'] . " -> " . $row['year_level'] . " | " . $row['semester'] . "\n";
        echo "    Title: " . $row['course_title'] . "\n";
        echo "    Units: " . ((float)$row['credit_units_lec'] + (float)$row['credit_units_lab']) . "\n";
        echo "    Prerequisite: " . $row['pre_requisite'] . "\n\n";
    }
} else {
    echo "  No GNED 14 or GNED 09 found in cvsucarmona_courses for BSCpE\n";
}

// 3. Check student's transcript for these courses
echo "\n3. Student Transcript Status (GNED 14 and GNED 09):\n";
$sql = "
    SELECT 
        sc.course_code,
        sc.final_grade,
        sc.grade_remarks,
        sc.grade_submitted_at,
        cc.year_level,
        cc.semester,
        cc.course_title
    FROM student_checklists sc
    LEFT JOIN curriculum_courses cc ON sc.course_code = TRIM(cc.course_code)
    WHERE sc.student_id = ?
    AND (
        UPPER(TRIM(sc.course_code)) = 'GNED 14'
        OR UPPER(TRIM(sc.course_code)) = 'GNED 09'
    )
    UNION ALL
    SELECT 
        sc.course_code,
        sc.final_grade,
        sc.grade_remarks,
        sc.grade_submitted_at,
        cc.year_level,
        cc.semester,
        cc.course_title
    FROM student_checklists sc
    LEFT JOIN cvsucarmona_courses cc ON sc.course_code = TRIM(SUBSTRING_INDEX(cc.curriculumyear_coursecode, '_', -1))
    WHERE sc.student_id = ?
    AND (
        UPPER(TRIM(sc.course_code)) = 'GNED 14'
        OR UPPER(TRIM(sc.course_code)) = 'GNED 09'
    )
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $student_id, $student_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  " . $row['course_code'] . "\n";
        echo "    Grade: " . $row['final_grade'] . " (" . $row['grade_remarks'] . ")\n";
        echo "    Curriculum Position: " . $row['year_level'] . " | " . $row['semester'] . "\n";
        echo "    Submitted: " . $row['grade_submitted_at'] . "\n\n";
    }
} else {
    echo "  No grades found for GNED 14 or GNED 09\n";
}

// 4. Get all courses in BSCpE curriculum
echo "\n4. Full BSCpE Curriculum Structure:\n";
$sql = "
    SELECT DISTINCT
        year_level,
        semester
    FROM curriculum_courses
    WHERE program LIKE '%Computer Engineering%'
    ORDER BY 
        FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
        FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer')
";
$result = $conn->query($sql);
if ($result) {
    $terms = [];
    while ($row = $result->fetch_assoc()) {
        $terms[] = $row['year_level'] . " - " . $row['semester'];
    }
    echo "  Terms: " . implode(", ", $terms) . "\n";
}

// 5. Check all GNED courses in BSCpE curriculum
echo "\n5. All GNED Courses in BSCpE Curriculum:\n";
$sql = "
    SELECT 
        TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
        year_level,
        semester,
        course_title,
        (credit_units_lec + credit_units_lab) AS total_units
    FROM curriculum_courses
    WHERE program LIKE '%Computer Engineering%'
    AND UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) LIKE 'GNED%'
    ORDER BY 
        FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
        FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer'),
        SUBSTRING_INDEX(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)), ' ', -1) + 0
";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $gned_courses = [];
    while ($row = $result->fetch_assoc()) {
        $code = $row['course_code'];
        $term = $row['year_level'] . " | " . $row['semester'];
        if (!isset($gned_courses[$code])) {
            $gned_courses[$code] = $term;
        }
        echo "  " . $code . " -> " . $term . " (" . $row['total_units'] . " units)\n";
    }
    echo "\nSummary:\n";
    if (isset($gned_courses['GNED 14'])) {
        echo "  GNED 14 should be in: " . $gned_courses['GNED 14'] . "\n";
    } else {
        echo "  GNED 14: NOT FOUND in BSCpE curriculum\n";
    }
    if (isset($gned_courses['GNED 09'])) {
        echo "  GNED 09 should be in: " . $gned_courses['GNED 09'] . "\n";
    } else {
        echo "  GNED 09: NOT FOUND in BSCpE curriculum\n";
    }
} else {
    echo "  No GNED courses found in BSCpE curriculum\n";
}

// 6. Check student's current completed courses
echo "\n6. Student's Completed Courses (Passed Grades):\n";
$sql = "
    SELECT 
        sc.course_code,
        sc.final_grade,
        COUNT(*) as attempt_count
    FROM student_checklists sc
    WHERE sc.student_id = ?
    AND sc.final_grade IN ('1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00')
    GROUP BY sc.course_code
    ORDER BY sc.course_code
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$completed = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $completed[] = $row['course_code'];
        echo "  ✓ " . $row['course_code'] . " (Grade: " . $row['final_grade'] . ", Attempts: " . $row['attempt_count'] . ")\n";
    }
    echo "\n  Total Completed: " . count($completed) . " courses\n";
} else {
    echo "  No completed courses found\n";
}

// 7. Check if GNED 14 or 09 have prerequisites
echo "\n7. Prerequisite Analysis for GNED Courses:\n";
$sql = "
    SELECT 
        TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
        pre_requisite,
        COUNT(*) as count
    FROM curriculum_courses
    WHERE UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) IN ('GNED 14', 'GNED 09')
    GROUP BY course_code, pre_requisite
";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $prereq = trim($row['pre_requisite']);
        echo "  " . $row['course_code'] . ": " . ($prereq ? $prereq : "NONE") . "\n";
        
        if ($prereq && strtoupper($prereq) !== 'NONE') {
            $prereq_parts = preg_split('/[,;]/', $prereq);
            foreach ($prereq_parts as $pre) {
                $pre_code = trim($pre);
                if ($pre_code && strtoupper($pre_code) !== 'NONE') {
                    $is_completed = in_array($pre_code, $completed);
                    echo "    └─ Prerequisite: " . $pre_code . " -> " . ($is_completed ? "✓ COMPLETED" : "✗ NOT COMPLETED") . "\n";
                }
            }
        }
    }
} else {
    echo "  No prerequisite data found\n";
}

$conn->close();
echo "\n=== END DIAGNOSTIC ===\n";
