<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_study_plan.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$student_id = isset($_GET['id']) ? trim((string)$_GET['id']) : '220100064';
$program = StudyPlanGenerator::lookupStudentProgram($student_id);
$generator = StudyPlanGenerator::createForStudent($student_id);
$stats = $generator->getCompletionStats();

$conn = getDBConnection();

$summary_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS checklist_rows,
        COUNT(
            CASE
                WHEN final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
                 AND CAST(final_grade AS DECIMAL(3,1)) BETWEEN 1.0 AND 3.0
                 AND grade_submitted_at IS NOT NULL
                 AND (grade_approved = 1 OR grade_approved IS NULL)
                THEN 1
            END
        ) AS approved_passing
    FROM student_checklists
    WHERE student_id = ?
");
$summary_stmt->bind_param("s", $student_id);
$summary_stmt->execute();
$db_summary = $summary_stmt->get_result()->fetch_assoc();
$summary_stmt->close();

$sample_stmt = $conn->prepare("
    SELECT course_code, final_grade, grade_approved, grade_submitted_at, submitted_by
    FROM student_checklists
    WHERE student_id = ?
      AND final_grade IS NOT NULL
      AND final_grade != ''
    ORDER BY grade_submitted_at DESC, course_code
    LIMIT 10
");
$sample_stmt->bind_param("s", $student_id);
$sample_stmt->execute();
$sample_rows = $sample_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sample_stmt->close();

closeDBConnection($conn);

$completion_from_db = ((int)$stats['total_courses'] > 0)
    ? round(((int)$db_summary['approved_passing'] / (int)$stats['total_courses']) * 100, 1)
    : 0;
$generator_matches_db = (int)$stats['completed_courses'] === (int)$db_summary['approved_passing'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Study Plan Stats Test - <?= htmlspecialchars($student_id) ?></title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; color: #222; }
        .box { background: #fff; padding: 20px; margin: 12px 0; border-radius: 10px; border: 2px solid #ddd; }
        .correct { border-color: #4caf50; background: #edf8ee; }
        .error { border-color: #f44336; background: #fff0f0; }
        .stats { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px; }
        .stat { background: #1e88e5; color: #fff; border-radius: 8px; padding: 10px 14px; font-weight: 700; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        pre { background: #fafafa; border: 1px solid #ddd; padding: 12px; overflow-x: auto; }
        a { color: #1565c0; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Study Plan Statistics Verification</h1>
        <p><strong>Student ID:</strong> <?= htmlspecialchars($student_id) ?></p>
        <p><strong>Program:</strong> <?= htmlspecialchars($program !== '' ? $program : '[not found]') ?></p>
        <p><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>
    </div>

    <div class="box">
        <h2>Direct Database Query</h2>
        <div class="stats">
            <div class="stat">Checklist Rows: <?= (int)$db_summary['checklist_rows'] ?></div>
            <div class="stat">Approved Passing: <?= (int)$db_summary['approved_passing'] ?></div>
            <div class="stat">Completion: <?= $completion_from_db ?>%</div>
        </div>
    </div>

    <div class="box">
        <h2>StudyPlanGenerator</h2>
        <div class="stats">
            <div class="stat">Completed: <?= (int)$stats['completed_courses'] ?>/<?= (int)$stats['total_courses'] ?></div>
            <div class="stat">Units: <?= (int)$stats['completed_units'] ?>/<?= (int)$stats['total_units'] ?></div>
            <div class="stat">Completion: <?= htmlspecialchars((string)$stats['completion_percentage']) ?>%</div>
            <div class="stat">Remaining: <?= (int)$stats['remaining_courses'] ?></div>
        </div>
        <pre><?= htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT)) ?></pre>
    </div>

    <div class="box <?= $generator_matches_db ? 'correct' : 'error' ?>">
        <h2>Comparison</h2>
        <p>Direct database passing-count: <strong><?= (int)$db_summary['approved_passing'] ?></strong></p>
        <p>Generator completed-count: <strong><?= (int)$stats['completed_courses'] ?></strong></p>
        <?php if ($generator_matches_db): ?>
            <p><strong>Result:</strong> The study-plan generator matches the current database records.</p>
        <?php else: ?>
            <p><strong>Result:</strong> The study-plan generator does not match the current database records.</p>
        <?php endif; ?>
    </div>

    <div class="box">
        <h2>Recent Grade Rows</h2>
        <?php if (!empty($sample_rows)): ?>
            <table>
                <tr>
                    <th>Course</th>
                    <th>Grade</th>
                    <th>Approved</th>
                    <th>Submitted At</th>
                    <th>Submitted By</th>
                </tr>
                <?php foreach ($sample_rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['course_code']) ?></td>
                        <td><?= htmlspecialchars((string)($row['final_grade'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['grade_approved'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['grade_submitted_at'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($row['submitted_by'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No grade rows found for this student.</p>
        <?php endif; ?>
    </div>

    <div class="box">
        <p><a href="study_plan.php">Back to Study Plan</a></p>
        <p><a href="verify_stats.php?id=<?= urlencode($student_id) ?>&t=<?= time() ?>">Reload This Page</a></p>
        <p><a href="diagnose_study_plan.php">Full Diagnostic Report</a></p>
    </div>
</body>
</html>
