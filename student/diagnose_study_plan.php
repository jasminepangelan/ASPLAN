<?php
/**
 * Study Plan Diagnostic Tool
 * Analyzes a student's checklist data to identify potential issues
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';

// Check if running from command line or web
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    // Check if user is logged in
    if (!isset($_SESSION['student_id'])) {
        header("Location: ../index.php");
        exit();
    }
    $student_id = $_SESSION['student_id'];
} else {
    // CLI mode: accept student ID as argument
    $student_id = $argv[1] ?? '220100031';
}

$conn = getDBConnection();

$student_program = '';
$program_stmt = $conn->prepare("
    SELECT program
    FROM student_info
    WHERE student_number = ?
    LIMIT 1
");
if ($program_stmt) {
    $program_stmt->bind_param("s", $student_id);
    $program_stmt->execute();
    $program_result = $program_stmt->get_result();
    if ($program_row = $program_result->fetch_assoc()) {
        $student_program = trim((string)($program_row['program'] ?? ''));
    }
    $program_stmt->close();
}

$program_code = function_exists('resolveProgramAbbreviation') ? resolveProgramAbbreviation($student_program) : '';
if ($program_code === '' && function_exists('psNormalizeProgramKey')) {
    $program_code = psNormalizeProgramKey($student_program);
}
$curriculum_program_labels = function_exists('psResolveChecklistProgramLabels')
    ? psResolveChecklistProgramLabels($student_program, $program_code)
    : [$student_program];
$legacy_program_tokens = function_exists('psResolveProgramTokens')
    ? psResolveProgramTokens($program_code !== '' ? $program_code : $student_program)
    : [$program_code];

function bindDynamicParams(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $stmt->bind_param($types, ...$params);
}

echo $is_cli ? "\n" : "<pre style='background: #f5f5f5; padding: 20px; font-family: monospace;'>";
echo "=".str_repeat("=", 60)."=\n";
echo "  STUDY PLAN DIAGNOSTIC REPORT\n";
echo "  Student ID: $student_id\n";
echo "  Program: " . ($student_program !== '' ? $student_program : 'N/A') . "\n";
echo "=".str_repeat("=", 60)."=\n\n";

// 1. Check total curriculum courses
echo "1. CURRICULUM ANALYSIS\n";
echo str_repeat("-", 62)."\n";
if (function_exists('psTableExists') && psTableExists($conn, 'curriculum_courses') && !empty($curriculum_program_labels)) {
    $conditions = [];
    $params = [];
    $types = '';
    foreach ($curriculum_program_labels as $label) {
        $conditions[] = 'UPPER(TRIM(program)) = ?';
        $params[] = strtoupper(trim((string)$label));
        $types .= 's';
    }
    $curriculum_stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(credit_units_lec + credit_units_lab) as total_units
        FROM curriculum_courses
        WHERE (" . implode(' OR ', $conditions) . ")
        AND TRIM(course_code) != ''
        AND (credit_units_lec > 0 OR credit_units_lab > 0)
    ");
    bindDynamicParams($curriculum_stmt, $types, $params);
    $curriculum_stmt->execute();
    $curriculum_stats = $curriculum_stmt->get_result()->fetch_assoc();
    $curriculum_stmt->close();
} else {
    $conditions = [];
    $params = [];
    $types = '';
    foreach ($legacy_program_tokens as $token) {
        $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), ", ", ",")) > 0';
        $params[] = strtoupper(trim((string)$token));
        $types .= 's';
    }
    $curriculum_stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(credit_units_lec + credit_units_lab) as total_units
        FROM cvsucarmona_courses
        WHERE (" . implode(' OR ', $conditions) . ")
        AND TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) != ''
        AND (credit_units_lec > 0 OR credit_units_lab > 0)
    ");
    bindDynamicParams($curriculum_stmt, $types, $params);
    $curriculum_stmt->execute();
    $curriculum_stats = $curriculum_stmt->get_result()->fetch_assoc();
    $curriculum_stmt->close();
}
echo "Total courses in curriculum: {$curriculum_stats['total']}\n";
echo "Total units in curriculum: {$curriculum_stats['total_units']}\n\n";

// 2. Check student's checklist records
echo "2. STUDENT CHECKLIST RECORDS\n";
echo str_repeat("-", 62)."\n";
$total_records_query = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM student_checklists 
    WHERE student_id = ?
");
$total_records_query->bind_param("s", $student_id);
$total_records_query->execute();
$total_records = $total_records_query->get_result()->fetch_assoc()['total'];
echo "Total checklist records: $total_records\n";

// If no records, list available student IDs
if ($total_records === 0) {
    echo "\n⚠️  No records found for student ID: $student_id\n";
    echo "Available student IDs in database:\n";
    $student_list = $conn->query("
        SELECT DISTINCT student_id, COUNT(*) as records 
        FROM student_checklists 
        GROUP BY student_id 
        ORDER BY student_id 
        LIMIT 10
    ");
    while ($student_row = $student_list->fetch_assoc()) {
        echo "  - {$student_row['student_id']} ({$student_row['records']} records)\n";
    }
    echo "\nTry running with one of the above student IDs.\n";
}

// 3. Analyze grade distribution
echo "\n3. GRADE DISTRIBUTION\n";
echo str_repeat("-", 62)."\n";

$grade_analysis_query = $conn->prepare("
    SELECT 
        final_grade,
        COUNT(*) as count,
        GROUP_CONCAT(course_code SEPARATOR ', ') as courses
    FROM student_checklists 
    WHERE student_id = ?
    GROUP BY final_grade
    ORDER BY 
        CASE 
            WHEN final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$' THEN 0
            ELSE 1
        END,
        final_grade
");
$grade_analysis_query->bind_param("s", $student_id);
$grade_analysis_query->execute();
$grade_results = $grade_analysis_query->get_result();

while ($row = $grade_results->fetch_assoc()) {
    $grade = $row['final_grade'] ?? 'NULL/EMPTY';
    $count = $row['count'];
    $courses = strlen($row['courses']) > 50 ? substr($row['courses'], 0, 50) . "..." : $row['courses'];
    echo sprintf("%-15s : %3d courses (%s)\n", $grade, $count, $courses);
}

// 4. Count "completed" courses using current logic
echo "\n4. COMPLETED COURSES (CURRENT LOGIC)\n";
echo str_repeat("-", 62)."\n";

// First check what columns exist
$schema_check = $conn->query("SHOW COLUMNS FROM student_checklists");
$has_submitted_at = false;
$has_updated_at = false;
$columns = [];
while ($col = $schema_check->fetch_assoc()) {
    $columns[] = $col['Field'];
    if ($col['Field'] === 'grade_submitted_at') $has_submitted_at = true;
    if ($col['Field'] === 'updated_at') $has_updated_at = true;
}

// Build query based on available columns
$select_fields = "course_code, final_grade";
if ($has_submitted_at) $select_fields .= ", grade_submitted_at";
if ($has_updated_at) $select_fields .= ", updated_at";
if (in_array('evaluator_remarks', $columns)) $select_fields .= ", evaluator_remarks";

$completed_query = $conn->prepare("
    SELECT $select_fields
    FROM student_checklists 
    WHERE student_id = ? 
    AND final_grade IS NOT NULL 
    AND final_grade != '' 
    AND final_grade != 'INC' 
    AND final_grade != 'DRP'
    AND final_grade != 'S'
    AND final_grade != 'N/A'
    AND final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
");
$completed_query->bind_param("s", $student_id);
$completed_query->execute();
$completed_result = $completed_query->get_result();

$passing_count = 0;
$failing_count = 0;
$total_completed_units = 0;

echo "Courses with numeric grades:\n";
if ($completed_result->num_rows === 0) {
    echo "  (No completed courses found)\n";
}
while ($row = $completed_result->fetch_assoc()) {
    $grade = floatval($row['final_grade']);
    $is_passing = ($grade >= 1.0 && $grade <= 3.0);
    $status = $is_passing ? "[PASS]" : "[FAIL]";
    
    if ($is_passing) {
        $passing_count++;
        // Get units from curriculum
        if (function_exists('psTableExists') && psTableExists($conn, 'curriculum_courses') && !empty($curriculum_program_labels)) {
            $conditions = [];
            $params = [$row['course_code']];
            $types = 's';
            foreach ($curriculum_program_labels as $label) {
                $conditions[] = 'UPPER(TRIM(program)) = ?';
                $params[] = strtoupper(trim((string)$label));
                $types .= 's';
            }
            $units_query = $conn->prepare("
                SELECT credit_units_lec + credit_units_lab as total_units 
                FROM curriculum_courses 
                WHERE TRIM(course_code) = ?
                AND (" . implode(' OR ', $conditions) . ")
                LIMIT 1
            ");
            bindDynamicParams($units_query, $types, $params);
        } else {
            $conditions = [];
            $params = [$row['course_code']];
            $types = 's';
            foreach ($legacy_program_tokens as $token) {
                $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), ", ", ",")) > 0';
                $params[] = strtoupper(trim((string)$token));
                $types .= 's';
            }
            $units_query = $conn->prepare("
                SELECT credit_units_lec + credit_units_lab as total_units 
                FROM cvsucarmona_courses 
                WHERE TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) = ?
                AND (" . implode(' OR ', $conditions) . ")
                LIMIT 1
            ");
            bindDynamicParams($units_query, $types, $params);
        }
        $units_query->execute();
        $units_result = $units_query->get_result();
        if ($units_row = $units_result->fetch_assoc()) {
            $total_completed_units += $units_row['total_units'];
        }
        $units_query->close();
    } else {
        $failing_count++;
    }
    
    $timestamp_info = "";
    if ($has_submitted_at && !empty($row['grade_submitted_at'])) {
        $timestamp_info = " [Submitted: {$row['grade_submitted_at']}]";
    } elseif ($has_updated_at && !empty($row['updated_at'])) {
        $timestamp_info = " [Updated: {$row['updated_at']}]";
    } elseif (isset($row['evaluator_remarks']) && !empty($row['evaluator_remarks'])) {
        $timestamp_info = " [Has remarks]";
    } else {
        $timestamp_info = " [NO TIMESTAMP - Possible pre-fill]";
    }
    
    echo sprintf("  %s %-15s: %.2f%s\n", $status, $row['course_code'], $grade, $timestamp_info);
}

echo "\nSummary:\n";
echo "  Passing courses (1.0-3.0): $passing_count\n";
echo "  Failing courses (>3.0): $failing_count\n";
echo "  Completed units: $total_completed_units\n";

// 5. Check for pre-populated records
echo "\n5. PRE-POPULATED RECORD DETECTION\n";
echo str_repeat("-", 62)."\n";

// Build query dynamically based on available columns
$where_conditions = [
    "student_id = ?",
    "final_grade IS NOT NULL",
    "final_grade != ''",
    "final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'"
];

if ($has_submitted_at) $where_conditions[] = "grade_submitted_at IS NULL";
if ($has_updated_at) $where_conditions[] = "updated_at IS NULL";
if (in_array('evaluator_remarks', $columns)) {
    $where_conditions[] = "(evaluator_remarks IS NULL OR evaluator_remarks = '')";
}

$where_clause = implode(" AND ", $where_conditions);

$prepopulated_query = $conn->prepare("
    SELECT course_code, final_grade
    FROM student_checklists 
    WHERE $where_clause
");
$prepopulated_query->bind_param("s", $student_id);
$prepopulated_query->execute();
$prepopulated_result = $prepopulated_query->get_result();
$prepopulated_count = $prepopulated_result->num_rows;

if ($prepopulated_count > 0) {
    echo "⚠️  WARNING: Found $prepopulated_count potentially pre-filled records:\n";
    while ($row = $prepopulated_result->fetch_assoc()) {
        echo "  - {$row['course_code']}: {$row['final_grade']}\n";
    }
    echo "\nThese records have grades but no timestamps or remarks.\n";
    echo "They may be counting toward completion incorrectly.\n";
} else {
    echo "✓ No obvious pre-populated records found.\n";
    echo "  All graded courses have timestamps or remarks.\n";
}

// 6. Calculate expected statistics
echo "\n6. EXPECTED vs ACTUAL STATISTICS\n";
echo str_repeat("-", 62)."\n";

$expected_completion = $curriculum_stats['total'] > 0 
    ? round(($passing_count / $curriculum_stats['total']) * 100, 1) 
    : 0;
$remaining_courses = $curriculum_stats['total'] - $passing_count;
$remaining_units = $curriculum_stats['total_units'] - $total_completed_units;

echo "Expected Statistics:\n";
echo "  Completion Rate: $expected_completion%\n";
echo "  Completed: $passing_count / {$curriculum_stats['total']} courses\n";
echo "  Remaining: $remaining_courses courses\n";
echo "  Units Completed: $total_completed_units / {$curriculum_stats['total_units']}\n";
echo "  Units Remaining: $remaining_units\n";

// 7. Check database schema
echo "\n7. DATABASE SCHEMA CHECK\n";
echo str_repeat("-", 62)."\n";

echo "Available columns in student_checklists:\n";
foreach ($columns as $col) {
    echo "  - $col\n";
}

echo "\nTimestamp columns present:\n";
echo "  grade_submitted_at: " . ($has_submitted_at ? "✓ YES" : "✗ NO (Fallback mode will be used)") . "\n";
echo "  updated_at: " . ($has_updated_at ? "✓ YES" : "✗ NO") . "\n";

if (!$has_submitted_at && !$has_updated_at) {
    echo "\n⚠️  WARNING: No timestamp columns found!\n";
    echo "  The system will use fallback validation which may be less accurate.\n";
    echo "  Consider adding timestamp columns to track user submissions.\n";
}

echo "\n".str_repeat("=", 62)."\n";
echo "END OF DIAGNOSTIC REPORT\n";
echo str_repeat("=", 62)."\n";

if (!$is_cli) {
    echo "</pre>";
}

closeDBConnection($conn);
?>
