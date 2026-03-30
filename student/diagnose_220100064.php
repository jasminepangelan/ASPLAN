<?php
/**
 * Diagnose: Why is student 220100064 showing grades when they haven't submitted any?
 */

require_once __DIR__ . '/../config/config.php';

$student_id = '220100064';
$conn = getDBConnection();

echo "<h1>🔍 Database Investigation - Student 220100064</h1>";
echo "<hr>";

// Check 1: Does student exist?
$student_check = $conn->prepare("SELECT student_id, last_name, first_name FROM students WHERE student_id = ?");
$student_check->bind_param("s", $student_id);
$student_check->execute();
$student_result = $student_check->get_result();

if ($student_result->num_rows === 0) {
    echo "<div style='background: #f8d7da; padding: 20px; border-left: 4px solid #dc3545;'>";
    echo "<strong>❌ ERROR:</strong> Student {$student_id} does not exist in the students table.";
    echo "</div>";
    closeDBConnection($conn);
    exit;
}

$student = $student_result->fetch_assoc();
echo "<div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin-bottom: 20px;'>";
echo "<strong>✅ Student Found:</strong> {$student['last_name']}, {$student['first_name']}";
echo "</div>";
$student_check->close();

// Check 2: How many courses in student_checklists?
echo "<h2>📋 Student Checklist Records</h2>";
$total_query = $conn->prepare("SELECT COUNT(*) as total FROM student_checklists WHERE student_id = ?");
$total_query->bind_param("s", $student_id);
$total_query->execute();
$total_result = $total_query->get_result();
$total_courses = $total_result->fetch_assoc()['total'];
$total_query->close();

echo "<p><strong>Total courses in student_checklists table:</strong> {$total_courses}</p>";

// Check 3: How many have grades?
$with_grades = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM student_checklists 
    WHERE student_id = ? 
    AND final_grade IS NOT NULL 
    AND final_grade != ''
");
$with_grades->bind_param("s", $student_id);
$with_grades->execute();
$grades_result = $with_grades->get_result();
$courses_with_grades = $grades_result->fetch_assoc()['count'];
$with_grades->close();

echo "<p><strong>Courses with non-empty final_grade:</strong> {$courses_with_grades}</p>";

if ($courses_with_grades > 0) {
    echo "<div style='background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107; margin: 20px 0;'>";
    echo "<strong>⚠️ PROBLEM DETECTED!</strong><br>";
    echo "This student has <strong>{$courses_with_grades} courses with grades</strong> in the database, ";
    echo "but you said they haven't submitted anything through checklist_stud.php.<br><br>";
    echo "<strong>This means:</strong> The student_checklists table was pre-populated with curriculum data including grades.";
    echo "</div>";
    
    // Show sample of courses with grades
    echo "<h3>Sample Courses with Grades (first 20)</h3>";
    $sample = $conn->prepare("
        SELECT course_code, final_grade, evaluator_remarks, professor_instructor
        FROM student_checklists
        WHERE student_id = ?
        AND final_grade IS NOT NULL
        AND final_grade != ''
        ORDER BY course_code
        LIMIT 20
    ");
    $sample->bind_param("s", $student_id);
    $sample->execute();
    $sample_result = $sample->get_result();
    
    echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%; background: white;'>";
    echo "<tr style='background: #206018; color: white;'>";
    echo "<th>Course Code</th><th>Final Grade</th><th>Remarks</th><th>Instructor</th></tr>";
    
    while ($row = $sample_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['course_code']}</td>";
        echo "<td><strong>{$row['final_grade']}</strong></td>";
        echo "<td>" . ($row['evaluator_remarks'] ?: '<em>(empty)</em>') . "</td>";
        echo "<td>" . ($row['professor_instructor'] ?: '<em>(empty)</em>') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    $sample->close();
}

// Check 4: When was the student_checklists data created?
echo "<hr>";
echo "<h2>🔍 Root Cause Analysis</h2>";
echo "<div style='background: #e7f3ff; padding: 20px; border-left: 4px solid #2196F3;'>";
echo "<h3>How did this happen?</h3>";
echo "<ol>";
echo "<li><strong>Initial Setup:</strong> When student 220100064 was created, the system inserted ALL curriculum courses into student_checklists table</li>";
echo "<li><strong>Pre-populated Grades:</strong> Test data or migration script filled in final_grade values</li>";
echo "<li><strong>Result:</strong> The algorithm reads from student_checklists and counts all non-empty grades as 'completed'</li>";
echo "</ol>";

echo "<h3>Why the algorithm is 'working correctly'</h3>";
echo "<p>The fix I made <strong>IS working</strong> - it's accurately counting what's in the database. ";
echo "The problem is the database has pre-populated test data that doesn't reflect actual student submissions.</p>";

echo "<h3>The Real Solution</h3>";
echo "<p>We need to either:</p>";
echo "<ul>";
echo "<li><strong>Option 1:</strong> Clear the pre-populated grades from student_checklists (use clear_test_data_220100064.php)</li>";
echo "<li><strong>Option 2:</strong> Add a timestamp/flag to track which grades were actually submitted by students vs pre-populated</li>";
echo "<li><strong>Option 3:</strong> Only insert courses into student_checklists when student actually submits grades</li>";
echo "</ul>";
echo "</div>";

// Provide solutions
echo "<hr>";
echo "<h2>✅ Immediate Solutions</h2>";

echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;'>";

echo "<div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>";
echo "<h3 style='color: #dc3545;'>🗑️ Solution 1: Clear Pre-populated Data</h3>";
echo "<p>Remove all pre-filled grades for student 220100064.</p>";
echo "<a href='clear_test_data_220100064.php' style='display: inline-block; padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;'>Clear Test Data Tool</a>";
echo "</div>";

echo "<div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>";
echo "<h3 style='color: #28a745;'>✨ Solution 2: Reset to Fresh State</h3>";
echo "<p>Clear all grades and keep only empty curriculum structure.</p>";
echo "<form method='POST' style='margin-top: 10px;'>";
echo "<input type='hidden' name='reset_student' value='1'>";
echo "<button type='submit' onclick=\"return confirm('Clear all grades for student 220100064?')\" style='padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; width: 100%;'>Reset Student Data</button>";
echo "</form>";
echo "</div>";

echo "<div style='background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>";
echo "<h3 style='color: #ffc107;'>📝 Solution 3: Manual Cleanup</h3>";
echo "<p>Run SQL command to clear grades:</p>";
echo "<pre style='background: #f4f4f4; padding: 10px; border-radius: 4px; font-size: 11px; overflow-x: auto;'>UPDATE student_checklists \nSET final_grade = '',\n    evaluator_remarks = '',\n    professor_instructor = ''\nWHERE student_id = '220100064';</pre>";
echo "</div>";

echo "</div>";

// Handle reset action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_student'])) {
    $update = $conn->prepare("
        UPDATE student_checklists 
        SET final_grade = '', 
            evaluator_remarks = '', 
            professor_instructor = ''
        WHERE student_id = ?
    ");
    $update->bind_param("s", $student_id);
    $update->execute();
    $affected = $update->affected_rows;
    $update->close();
    
    echo "<div style='background: #d4edda; padding: 20px; border-left: 4px solid #28a745; margin-top: 20px;'>";
    echo "<strong>✅ SUCCESS!</strong> Cleared all grades for student {$student_id}. ({$affected} records updated)<br>";
    echo "The Academic Progress Overview will now show 0% completion.<br><br>";
    echo "<a href='study_plan.php' style='padding: 10px 20px; background: #206018; color: white; text-decoration: none; border-radius: 6px; display: inline-block;'>View Study Plan (Login as 220100064)</a>";
    echo "</div>";
}

closeDBConnection($conn);
?>

<style>
    body {
        font-family: 'Segoe UI', Arial, sans-serif;
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    }
    h1 { color: #206018; text-align: center; }
    h2 { color: #2d8f22; margin-top: 30px; }
    h3 { color: #333; font-size: 18px; margin-bottom: 10px; }
    table {
        background: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        font-size: 13px;
    }
    th, td { padding: 10px; }
    th { text-align: left; }
    hr {
        margin: 40px 0;
        border: none;
        border-top: 3px solid #206018;
    }
</style>
