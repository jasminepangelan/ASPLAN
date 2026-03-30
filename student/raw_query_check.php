<?php
require_once '../config/config.php';

$conn = getDBConnection();
$student_id = 220100064;

echo "<h2>Raw Database Query Check</h2>";
echo "<p>Student ID: $student_id</p>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p><hr>";

// Check all records for this student
echo "<h3>ALL records in student_checklists:</h3>";
$query1 = "SELECT course_code, final_grade, grade_submitted_at, submitted_by, updated_at 
           FROM student_checklists 
           WHERE student_id = ? 
           ORDER BY grade_submitted_at DESC";
$stmt1 = $conn->prepare($query1);
$stmt1->bind_param("i", $student_id);
$stmt1->execute();
$result1 = $stmt1->get_result();

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Course</th><th>Grade</th><th>Submitted At</th><th>Submitted By</th><th>Updated At</th></tr>";
$total_count = 0;
while ($row = $result1->fetch_assoc()) {
    $total_count++;
    echo "<tr>";
    echo "<td>{$row['course_code']}</td>";
    echo "<td>{$row['final_grade']}</td>";
    echo "<td>" . ($row['grade_submitted_at'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['submitted_by'] ?? 'NULL') . "</td>";
    echo "<td>" . ($row['updated_at'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p><strong>Total records: $total_count</strong></p><hr>";

// Check only records with timestamps
echo "<h3>Records WITH grade_submitted_at (should be 4):</h3>";
$query2 = "SELECT course_code, final_grade, grade_submitted_at, submitted_by 
           FROM student_checklists 
           WHERE student_id = ? 
           AND grade_submitted_at IS NOT NULL
           ORDER BY grade_submitted_at DESC";
$stmt2 = $conn->prepare($query2);
$stmt2->bind_param("i", $student_id);
$stmt2->execute();
$result2 = $stmt2->get_result();

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Course</th><th>Grade</th><th>Submitted At</th><th>Submitted By</th></tr>";
$with_timestamp = 0;
while ($row = $result2->fetch_assoc()) {
    $with_timestamp++;
    echo "<tr>";
    echo "<td>{$row['course_code']}</td>";
    echo "<td>{$row['final_grade']}</td>";
    echo "<td>{$row['grade_submitted_at']}</td>";
    echo "<td>{$row['submitted_by']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p><strong>Records with timestamp: $with_timestamp</strong></p><hr>";

// Check what query generate_study_plan.php would use
echo "<h3>Query that generate_study_plan.php uses:</h3>";
$query3 = "SELECT course_code, final_grade, grade_submitted_at
           FROM student_checklists
           WHERE student_id = ?
           AND final_grade IS NOT NULL 
           AND final_grade != '' 
           AND final_grade NOT IN ('INC', 'DRP', 'S')
           AND CAST(final_grade AS DECIMAL(3,2)) >= 1.0 
           AND CAST(final_grade AS DECIMAL(3,2)) <= 3.0";

$stmt3 = $conn->prepare($query3);
$stmt3->bind_param("i", $student_id);
$stmt3->execute();
$result3 = $stmt3->get_result();

echo "<pre>$query3</pre>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Course</th><th>Grade</th><th>Submitted At</th></tr>";
$query_count = 0;
while ($row = $result3->fetch_assoc()) {
    $query_count++;
    echo "<tr>";
    echo "<td>{$row['course_code']}</td>";
    echo "<td>{$row['final_grade']}</td>";
    echo "<td>" . ($row['grade_submitted_at'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p><strong>Query returns: $query_count courses</strong></p>";

if ($query_count > 4) {
    echo "<p style='color: red; font-weight: bold;'>❌ PROBLEM: Query returns $query_count courses but should only return 4!</p>";
    echo "<p>This means there are courses with passing grades but NULL grade_submitted_at timestamps.</p>";
} else {
    echo "<p style='color: green; font-weight: bold;'>✓ Query correctly returns 4 courses</p>";
}

$conn->close();
?>
