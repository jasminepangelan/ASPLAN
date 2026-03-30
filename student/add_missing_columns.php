<?php
require_once '../config/config.php';

$conn = getDBConnection();

echo "<h2>Adding Required Columns to student_checklists</h2>";
echo "<p>Starting migration at: " . date('Y-m-d H:i:s') . "</p><hr>";

$sql = "ALTER TABLE student_checklists
ADD COLUMN grade_submitted_at DATETIME NULL DEFAULT NULL COMMENT 'When student submitted this grade',
ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
ADD COLUMN submitted_by VARCHAR(50) NULL DEFAULT NULL COMMENT 'Who submitted: student, adviser, admin',
ADD COLUMN grade_approved TINYINT(1) NULL DEFAULT 0 COMMENT '0=pending, 1=approved',
ADD COLUMN approved_at DATETIME NULL DEFAULT NULL COMMENT 'When grade was approved',
ADD COLUMN approved_by VARCHAR(100) NULL DEFAULT NULL COMMENT 'Adviser who approved'";

echo "<h3>Executing SQL:</h3>";
echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 4px;'>$sql</pre>";

try {
    $conn->query($sql);
    echo "<div style='background: #e8f5e9; padding: 15px; border-left: 4px solid #4CAF50; margin: 20px 0;'>";
    echo "<strong>✓ SUCCESS!</strong> All 6 columns added to student_checklists table.";
    echo "</div>";
    
    // Verify columns were added
    $verify = $conn->query("DESCRIBE student_checklists");
    $columns = [];
    while ($row = $verify->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    
    echo "<h3>Verification - Columns now in table:</h3>";
    echo "<ul>";
    foreach (['grade_submitted_at', 'updated_at', 'submitted_by', 'grade_approved', 'approved_at', 'approved_by'] as $col) {
        if (in_array($col, $columns)) {
            echo "<li style='color: green;'>✓ <strong>$col</strong></li>";
        } else {
            echo "<li style='color: red;'>✗ <strong>$col</strong> - Still missing!</li>";
        }
    }
    echo "</ul>";
    
    echo "<h3>Next Steps:</h3>";
    echo "<ol style='line-height: 2;'>";
    echo "<li>Columns are now added with default values</li>";
    echo "<li>Existing records have <code>grade_approved = 0</code> (pending)</li>";
    echo "<li>When students submit new grades, <code>grade_submitted_at</code> and <code>submitted_by</code> will be set</li>";
    echo "<li>Advisers must approve grades to set <code>grade_approved = 1</code></li>";
    echo "<li>Academic Progress will only count approved grades</li>";
    echo "</ol>";
    
    echo "<div style='background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 20px 0;'>";
    echo "<strong>⚠️ IMPORTANT:</strong><br>";
    echo "Since all existing grades now have <code>grade_approved = 0</code>, the Academic Progress will show <strong>0%</strong> until an adviser approves them.<br>";
    echo "This is the correct behavior - only approved grades count toward completion.";
    echo "</div>";
    
    echo "<div style='margin-top: 30px;'>";
    echo "<a href='force_refresh_study_plan.php' style='display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; font-weight: bold;'>→ Check Study Plan Now</a>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #ffebee; padding: 15px; border-left: 4px solid #f44336; margin: 20px 0;'>";
    echo "<strong>✗ ERROR:</strong><br>";
    echo $e->getMessage();
    echo "</div>";
}

$conn->close();
?>
<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    h2 { color: #1976D2; }
    h3 { color: #424242; margin-top: 30px; }
    pre { overflow-x: auto; }
    code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
</style>
