<?php
require_once '../config/config.php';

// Force complete cache bypass
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("ETag: " . md5(microtime() . rand()));

// Clear any session cache
unset($_SESSION['study_plan_cache']);
unset($_SESSION['completion_stats']);

$student_id = $_SESSION['student_id'] ?? null;

if (!$student_id) {
    die("Not logged in");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Force Refresh</title>
    <style>
        body { font-family: Arial; padding: 40px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .status { padding: 15px; margin: 15px 0; border-radius: 4px; }
        .info { background: #e3f2fd; border-left: 4px solid #2196F3; }
        .success { background: #e8f5e9; border-left: 4px solid #4CAF50; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; }
        .button { display: inline-block; padding: 12px 24px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; margin-top: 20px; font-weight: bold; }
        .button:hover { background: #45a049; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🔄 Cache Clear & Force Refresh</h2>
        
        <div class="status info">
            <strong>Student ID:</strong> <?= htmlspecialchars($student_id) ?><br>
            <strong>Current Time:</strong> <?= date('Y-m-d H:i:s') ?><br>
            <strong>Page ID:</strong> <?= uniqid('', true) ?>
        </div>
        
        <?php
        // Get actual current stats
        require_once 'generate_study_plan.php';
        $generator = StudyPlanGenerator::createForStudent($student_id);
        $stats = $generator->getCompletionStats();
        ?>
        
        <div class="status success">
            <h3>✅ Current Actual Stats from Database:</h3>
            <ul>
                <li><strong>Completion:</strong> <?= $stats['completion_percentage'] ?>%</li>
                <li><strong>Courses Completed:</strong> <?= $stats['completed_courses'] ?>/<?= $stats['total_courses'] ?></li>
                <li><strong>Units Completed:</strong> <?= $stats['completed_units'] ?>/<?= $stats['total_units'] ?></li>
            </ul>
        </div>
        
        <div class="status warning">
            <strong>⚠️ If you see different numbers in your Study Plan:</strong>
            <ol style="margin-top: 10px;">
                <li>Press <code>Ctrl + Shift + R</code> (or <code>Ctrl + F5</code>)</li>
                <li>Or click the button below to reload with cache bypass</li>
            </ol>
        </div>
        
        <a href="study_plan.php?nocache=<?= uniqid() ?>&t=<?= time() ?>" class="button">
            🔄 Load Fresh Study Plan
        </a>
        
        <div style="margin-top: 30px; padding: 15px; background: #f5f5f5; border-radius: 4px; font-size: 12px;">
            <strong>Technical Info:</strong><br>
            Cache headers sent: no-store, no-cache<br>
            Session cache cleared: Yes<br>
            ETag: <?= md5(microtime() . rand()) ?><br>
            Generated: <?= microtime(true) ?>
        </div>
    </div>
</body>
</html>
