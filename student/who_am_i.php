<?php
require_once __DIR__ . '/../config/config.php';

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<title>Current Session Check</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; padding: 40px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }";
echo ".card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-width: 560px; margin: 0 auto; }";
echo "h1 { color: #206018; margin-bottom: 30px; }";
echo ".student-id { font-size: 48px; font-weight: bold; color: #dc3545; margin: 20px 0; }";
echo ".info { background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 15px 0; text-align: left; }";
echo ".btn { display: inline-block; padding: 12px 30px; background: #206018; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px; font-weight: 600; }";
echo ".btn-danger { background: #dc3545; }";
echo "</style>";
echo "</head><body>";

echo "<div class='card'>";
echo "<h1>Current Session Check</h1>";

if (isset($_SESSION['student_id'])) {
    $student_id = (string)$_SESSION['student_id'];

    echo "<p style='font-size: 18px; color: #666;'>You are currently logged in as:</p>";
    echo "<div class='student-id'>" . htmlspecialchars($student_id) . "</div>";

    if ($student_id === '220100064') {
        echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; border-left: 4px solid #28a745; margin: 20px 0;'>";
        echo "<strong style='color: #155724;'>Session matches student 220100064.</strong><br>";
        echo "The Study Plan page should reflect the approved grades currently stored for this student.";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 20px 0;'>";
        echo "<strong style='color: #721c24;'>This is not student 220100064.</strong><br>";
        echo "If you are validating that student's study plan, log in with the correct account first.";
        echo "</div>";
    }

    echo "<div class='info'>";
    echo "<strong>Additional Session Info:</strong><br>";
    echo "First Name: " . htmlspecialchars((string)($_SESSION['first_name'] ?? '')) . "<br>";
    echo "Last Name: " . htmlspecialchars((string)($_SESSION['last_name'] ?? '')) . "<br>";
    echo "User Type: " . htmlspecialchars((string)($_SESSION['user_type'] ?? ''));
    echo "</div>";

    echo "<a href='../auth/signout.php' class='btn btn-danger'>Logout</a>";
    echo "<a href='study_plan.php' class='btn'>View Study Plan</a>";
} else {
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 8px; border-left: 4px solid #dc3545;'>";
    echo "<strong>No active session found.</strong>";
    echo "</div>";
    echo "<a href='../index.php' class='btn'>Go to Login</a>";
}

echo "</div>";
echo "</body></html>";
