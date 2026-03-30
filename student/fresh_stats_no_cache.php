<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_study_plan.php';

if (!isset($_SESSION['student_id'])) {
    die("Please log in first");
}

$student_id = (string)$_SESSION['student_id'];
$program = StudyPlanGenerator::lookupStudentProgram($student_id);
$generator = StudyPlanGenerator::createForStudent($student_id);
$stats = $generator->getCompletionStats();
$timestamp = date('Y-m-d H:i:s');
$random = rand(1000, 9999);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Fresh Stats - No Cache</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card { background: #fff; padding: 28px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); margin-bottom: 20px; }
        h1 { color: #206018; text-align: center; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-box { padding: 18px; border-radius: 8px; text-align: center; color: #fff; }
        .green { background: linear-gradient(135deg, #4caf50, #388e3c); }
        .blue { background: linear-gradient(135deg, #2196f3, #1565c0); }
        .orange { background: linear-gradient(135deg, #fb8c00, #ef6c00); }
        .purple { background: linear-gradient(135deg, #8e24aa, #6a1b9a); }
        .number { font-size: 32px; font-weight: 700; }
        .label { font-size: 14px; opacity: 0.9; }
        .banner { background: #e8f5e9; border-left: 4px solid #2e7d32; padding: 18px; border-radius: 8px; color: #1b5e20; }
        .timestamp { background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; margin: 20px 0; border: 2px dashed #206018; }
        .btn { display: inline-block; padding: 12px 25px; background: #206018; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; margin: 5px; }
        .btn-primary { background: #2196f3; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Fresh Study Plan Stats</h1>

        <div class="timestamp">
            <strong>Generated At:</strong> <?= htmlspecialchars($timestamp) ?><br>
            <strong>Random ID:</strong> #<?= (int)$random ?><br>
            <strong>Student ID:</strong> <?= htmlspecialchars($student_id) ?><br>
            <strong>Program:</strong> <?= htmlspecialchars($program !== '' ? $program : '[not found]') ?>
        </div>

        <div class="stats-grid">
            <div class="stat-box green">
                <div class="number"><?= htmlspecialchars((string)$stats['completion_percentage']) ?>%</div>
                <div class="label">Completion Rate</div>
            </div>
            <div class="stat-box blue">
                <div class="number"><?= (int)$stats['completed_courses'] ?>/<?= (int)$stats['total_courses'] ?></div>
                <div class="label">Courses Completed</div>
            </div>
            <div class="stat-box orange">
                <div class="number"><?= (int)$stats['completed_units'] ?>/<?= (int)$stats['total_units'] ?></div>
                <div class="label">Units Completed</div>
            </div>
            <div class="stat-box purple">
                <div class="number"><?= (int)$stats['remaining_courses'] ?></div>
                <div class="label">Courses Remaining</div>
            </div>
        </div>

        <div class="banner">
            These numbers come from a fresh generator run using your current student record and program.
            If the main study plan page shows different values, check for cached HTML or a stale session.
        </div>

        <div style="text-align: center; margin-top: 24px;">
            <a href="study_plan.php" class="btn">View Official Study Plan</a>
            <a href="fresh_stats_no_cache.php?t=<?= time() ?>" class="btn btn-primary">Refresh Again</a>
        </div>
    </div>
</body>
</html>
