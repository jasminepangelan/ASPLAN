<?php
// Set JSON content type header
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';

// Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to submit grades.']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['grades']) || !is_array($input['grades']) || count($input['grades']) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No grades provided.']);
    exit;
}

$student_id = $_SESSION['student_id'];
$grades = $input['grades'];

// Get database connection
$conn = getDBConnection();

$academicHold = ahsGetStudentAcademicHold($conn, (string)$student_id);
if (!empty($academicHold['active'])) {
    closeDBConnection($conn);
    echo json_encode([
        'status' => 'error',
        'message' => (string)($academicHold['message'] ?? 'Your account is currently read-only.')
    ]);
    exit;
}

// Check retention policy status - block disqualified students
$grades_sql = "
    SELECT sc.course_code, sc.final_grade,
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
        END AS semester
    FROM student_checklists sc
    JOIN cvsucarmona_courses c ON SUBSTRING(c.curriculumyear_coursecode, 6) = sc.course_code
    WHERE FIND_IN_SET('BSCS', REPLACE(c.programs, ', ', ',')) > 0
    AND sc.student_id = ? AND sc.final_grade IS NOT NULL AND sc.final_grade != '' AND sc.final_grade != 'No Grade'
    ORDER BY 
        FIELD(c.year_level, 'Fourth Year', 'Third Year', 'Second Year', 'First Year'),
        FIELD(c.semester, 'Second Semester', 'First Semester', 'Mid Year', 'Midyear')
";

$grades_stmt = $conn->prepare($grades_sql);
$grades_stmt->bind_param("s", $student_id);
$grades_stmt->execute();
$grades_result = $grades_stmt->get_result();

$grades_by_semester = [];
while ($grade_row = $grades_result->fetch_assoc()) {
    $key = $grade_row['year'] . '_' . $grade_row['semester'];
    if (!isset($grades_by_semester[$key])) {
        $grades_by_semester[$key] = [];
    }
    $grades_by_semester[$key][] = $grade_row;
}
$grades_stmt->close();

// Check if student is disqualified
if (!empty($grades_by_semester)) {
    $latest_key = array_key_first($grades_by_semester);
    $latest_subjects = $grades_by_semester[$latest_key];
    
    $total_with_grades = count($latest_subjects);
    $failed_count = 0;
    
    foreach ($latest_subjects as $subject) {
        $grade = $subject['final_grade'];
        
        // Check if subject is failed
        $is_failed = false;
        
        if ($grade === 'INC' || $grade === 'DRP' || $grade === 'W') {
            $is_failed = true;
        } else {
            $numeric_grade = floatval($grade);
            if ($numeric_grade === 0.00 || $numeric_grade > 3.0) {
                $is_failed = true;
            }
        }
        
        if ($is_failed) {
            $failed_count++;
        }
    }
    
    // Calculate failure percentage
    if ($total_with_grades > 0) {
        $failed_percentage = ($failed_count / $total_with_grades) * 100;
        
        // Block if disqualified (75% or more failed)
        if ($failed_percentage >= 75) {
            closeDBConnection($conn);
            echo json_encode(['status' => 'error', 'message' => 'You are disqualified from submitting grades due to failing 75% or more of your subjects. Please contact your adviser.']);
            exit;
        }
    }
}

// Validate grades format
$valid_grades = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00', 'INC', 'DRP'];
$errors = [];
$success_count = 0;

foreach ($grades as $grade_entry) {
    $course_code = trim($grade_entry['course_code']);
    $final_grade = strtoupper(trim($grade_entry['final_grade']));
    
    // Validate grade format
    if (!in_array($final_grade, $valid_grades)) {
        $errors[] = "Invalid grade format for $course_code: $final_grade";
        continue;
    }
    
    // Check if course exists in curriculum
    $check_course = $conn->prepare("SELECT curriculumyear_coursecode FROM cvsucarmona_courses WHERE SUBSTRING(curriculumyear_coursecode, 6) = ?");
    $check_course->bind_param("s", $course_code);
    $check_course->execute();
    $course_result = $check_course->get_result();
    
    if ($course_result->num_rows === 0) {
        $errors[] = "Course code not found: $course_code";
        $check_course->close();
        continue;
    }
    $check_course->close();
    
    // Check if record already exists in student_checklists
    $check_stmt = $conn->prepare("SELECT student_id FROM student_checklists WHERE student_id = ? AND course_code = ?");
    $check_stmt->bind_param("ss", $student_id, $course_code);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing record - set grade and mark as Pending
        $update_stmt = $conn->prepare("UPDATE student_checklists SET final_grade = ?, evaluator_remarks = 'Pending', grade_submitted_at = NOW(), submitted_by = 'student' WHERE student_id = ? AND course_code = ?");
        $update_stmt->bind_param("sss", $final_grade, $student_id, $course_code);
        
        if ($update_stmt->execute()) {
            $success_count++;
        } else {
            $errors[] = "Failed to update grade for $course_code: " . $update_stmt->error;
        }
        $update_stmt->close();
    } else {
        // Insert new record - set grade and mark as Pending
        $insert_stmt = $conn->prepare("INSERT INTO student_checklists (student_id, course_code, final_grade, evaluator_remarks, grade_submitted_at, submitted_by) VALUES (?, ?, ?, 'Pending', NOW(), 'student')");
        $insert_stmt->bind_param("sss", $student_id, $course_code, $final_grade);
        
        if ($insert_stmt->execute()) {
            $success_count++;
        } else {
            $errors[] = "Failed to save grade for $course_code: " . $insert_stmt->error;
        }
        $insert_stmt->close();
    }
    $check_stmt->close();
}

closeDBConnection($conn);

// Return response
if ($success_count > 0 && count($errors) === 0) {
    echo json_encode([
        'status' => 'success',
        'message' => "Successfully saved $success_count grade(s) to your checklist."
    ]);
} elseif ($success_count > 0 && count($errors) > 0) {
    echo json_encode([
        'status' => 'partial',
        'message' => "Saved $success_count grade(s). Some errors occurred: " . implode(', ', $errors)
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to save grades. ' . implode(', ', $errors)
    ]);
}
?>
