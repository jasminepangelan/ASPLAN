<?php
require_once '../config/config.php';

$conn = getDBConnection();
$student_id = 220100064;

echo "<h2>Complete Academic Progress Data Audit</h2>";
echo "<p>Student ID: $student_id</p>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p><hr>";

// 1. Check student's submitted grades
echo "<h3>1. Student's Submitted Grades (grade_submitted_at IS NOT NULL):</h3>";
$query1 = "SELECT course_code, final_grade, grade_submitted_at, grade_approved, approved_at, approved_by 
           FROM student_checklists 
           WHERE student_id = ? 
           AND grade_submitted_at IS NOT NULL
           ORDER BY grade_submitted_at DESC";
$stmt1 = $conn->prepare($query1);
$stmt1->bind_param("i", $student_id);
$stmt1->execute();
$result1 = $stmt1->get_result();

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Course</th><th>Grade</th><th>Submitted At</th><th>Approved</th><th>Approved At</th><th>Approved By</th></tr>";
$submitted_count = 0;
while ($row = $result1->fetch_assoc()) {
    $submitted_count++;
    $approved_status = $row['grade_approved'] == 1 ? '✓ Yes' : '✗ No';
    $row_color = $row['grade_approved'] == 1 ? 'background: #e8f5e9;' : 'background: #fff3cd;';
    echo "<tr style='$row_color'>";
    echo "<td>{$row['course_code']}</td>";
    echo "<td>{$row['final_grade']}</td>";
    echo "<td>{$row['grade_submitted_at']}</td>";
    echo "<td><strong>$approved_status</strong></td>";
    echo "<td>" . ($row['approved_at'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['approved_by'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p><strong>Submitted grades: $submitted_count</strong></p><hr>";

// 2. Check approved grades only
echo "<h3>2. Approved Grades ONLY (grade_approved = 1):</h3>";
$query2 = "SELECT course_code, final_grade, grade_submitted_at, approved_at, approved_by 
           FROM student_checklists 
           WHERE student_id = ? 
           AND grade_submitted_at IS NOT NULL
           AND grade_approved = 1
           ORDER BY approved_at DESC";
$stmt2 = $conn->prepare($query2);
$stmt2->bind_param("i", $student_id);
$stmt2->execute();
$result2 = $stmt2->get_result();

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Course</th><th>Grade</th><th>Submitted At</th><th>Approved At</th><th>Approved By</th></tr>";
$approved_count = 0;
while ($row = $result2->fetch_assoc()) {
    $approved_count++;
    echo "<tr style='background: #e8f5e9;'>";
    echo "<td>{$row['course_code']}</td>";
    echo "<td>{$row['final_grade']}</td>";
    echo "<td>{$row['grade_submitted_at']}</td>";
    echo "<td>{$row['approved_at']}</td>";
    echo "<td>{$row['approved_by']}</td>";
    echo "</tr>";
}
if ($approved_count == 0) {
    echo "<tr><td colspan='5' style='text-align: center; padding: 20px; color: #f44336;'><strong>No approved grades found!</strong></td></tr>";
}
echo "</table>";
echo "<p><strong>Approved grades: $approved_count</strong></p><hr>";

// 3. Check what query the system SHOULD use
echo "<h3>3. Query that should be used (with approval check):</h3>";
$query3 = "SELECT course_code, final_grade, grade_submitted_at, grade_approved
           FROM student_checklists 
           WHERE student_id = ? 
           AND final_grade IS NOT NULL 
           AND final_grade != '' 
           AND final_grade NOT IN ('INC', 'DRP', 'S')
           AND final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
           AND grade_submitted_at IS NOT NULL
           AND grade_approved = 1";
$stmt3 = $conn->prepare($query3);
$stmt3->bind_param("i", $student_id);
$stmt3->execute();
$result3 = $stmt3->get_result();

echo "<pre style='background: #f5f5f5; padding: 10px;'>$query3</pre>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Course</th><th>Grade</th><th>Submitted At</th><th>Approved</th></tr>";
$correct_count = 0;
while ($row = $result3->fetch_assoc()) {
    $correct_count++;
    echo "<tr>";
    echo "<td>{$row['course_code']}</td>";
    echo "<td>{$row['final_grade']}</td>";
    echo "<td>{$row['grade_submitted_at']}</td>";
    echo "<td>✓ Yes</td>";
    echo "</tr>";
}
if ($correct_count == 0) {
    echo "<tr><td colspan='4' style='text-align: center; padding: 20px; color: #ff9800;'><strong>Zero courses match criteria (waiting for adviser approval)</strong></td></tr>";
}
echo "</table>";
echo "<p><strong>Courses that SHOULD count: $correct_count</strong></p><hr>";

// 4. Calculate what the stats SHOULD be
echo "<h3>4. Expected Academic Progress Stats:</h3>";
$total_courses = 57; // BSCS has 57 courses
$expected_percentage = $total_courses > 0 ? round(($correct_count / $total_courses) * 100, 1) : 0;

echo "<div style='background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 10px 0;'>";
echo "<strong>✓ CORRECT Stats (what should display):</strong><br>";
echo "Completion: <strong>{$expected_percentage}%</strong><br>";
echo "Courses Completed: <strong>$correct_count/$total_courses</strong><br>";
echo "</div>";

echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336; margin: 10px 0;'>";
echo "<strong>✗ WRONG Stats (what currently displays):</strong><br>";
echo "Completion: <strong>93%</strong><br>";
echo "Courses Completed: <strong>53/57</strong><br>";
echo "</div>";

if ($correct_count == 0) {
    echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 10px 0;'>";
    echo "<strong>⚠️ Why 0%?</strong><br>";
    echo "Student has submitted $submitted_count grades, but <strong>NONE are approved yet</strong>.<br>";
    echo "Grades must be approved by an adviser before they count toward completion.<br>";
    echo "</div>";
}

// 5. Summary
echo "<h3>5. Summary & Action Required:</h3>";
echo "<ul style='line-height: 1.8;'>";
echo "<li>Submitted grades: <strong>$submitted_count</strong></li>";
echo "<li>Approved grades: <strong>$approved_count</strong></li>";
echo "<li>Should count toward completion: <strong>$correct_count courses</strong></li>";
echo "<li>Expected completion: <strong>{$expected_percentage}%</strong></li>";
echo "<li>Currently displays: <strong>93% (WRONG)</strong></li>";
echo "</ul>";

echo "<div style='background: #f44336; color: white; padding: 15px; border-radius: 4px; margin: 20px 0;'>";
echo "<strong>🔴 ISSUE: Browser is showing cached data (93%) instead of fresh data ({$expected_percentage}%)</strong><br>";
echo "<br><strong>SOLUTION:</strong><br>";
echo "1. Press <code style='background: rgba(255,255,255,0.2); padding: 2px 6px;'>Ctrl + Shift + R</code> to hard refresh<br>";
echo "2. Or clear browser cache completely<br>";
echo "3. Or open in incognito/private window<br>";
echo "</div>";

$conn->close();
?>
<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    table { border-collapse: collapse; width: 100%; background: white; margin: 10px 0; }
    th { background: #2196F3; color: white; padding: 10px; text-align: left; }
    td { padding: 8px; }
    code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    pre { overflow-x: auto; }
    h2 { color: #1976D2; }
    h3 { color: #424242; margin-top: 30px; }
</style>
