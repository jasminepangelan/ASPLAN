<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_study_plan.php';

$student_id = '220100064';
$program = StudyPlanGenerator::lookupStudentProgram($student_id);
$generator = StudyPlanGenerator::createForStudent($student_id);
$stats = $generator->getCompletionStats();

$conn = getDBConnection();

$raw_stmt = $conn->prepare("
    SELECT course_code, final_grade, grade_approved, grade_submitted_at
    FROM student_checklists
    WHERE student_id = ?
      AND final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
    ORDER BY grade_submitted_at DESC, course_code
");
$raw_stmt->bind_param("s", $student_id);
$raw_stmt->execute();
$raw_rows = $raw_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$raw_stmt->close();

closeDBConnection($conn);

$passing_rows = array_values(array_filter($raw_rows, function ($row) {
    $grade = (float)($row['final_grade'] ?? 0);
    return $grade >= 1.0 && $grade <= 3.0 && (int)($row['grade_approved'] ?? 0) === 1;
}));

$matches = count($passing_rows) === (int)$stats['completed_courses'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deep Diagnostic - Student 220100064</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }
        h1 { color: #206018; text-align: center; }
        h2 { color: #2d8f22; margin-top: 28px; }
        .card { background: #fff; padding: 20px; border-radius: 10px; margin: 14px 0; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .ok { border-left: 4px solid #2e7d32; background: #edf8ee; }
        .warn { border-left: 4px solid #ef6c00; background: #fff7e6; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #206018; color: #fff; }
        pre { background: #f7f7f7; border: 1px solid #ddd; padding: 12px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Deep Diagnostic - Student 220100064</h1>

    <div class="card">
        <p><strong>Program:</strong> <?= htmlspecialchars($program !== '' ? $program : '[not found]') ?></p>
        <p><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>
    </div>

    <div class="card">
        <h2>Direct Grade Rows</h2>
        <p><strong>Numeric grade rows:</strong> <?= count($raw_rows) ?></p>
        <p><strong>Approved passing rows:</strong> <?= count($passing_rows) ?></p>
        <?php if (!empty($raw_rows)): ?>
            <table>
                <tr>
                    <th>#</th>
                    <th>Course Code</th>
                    <th>Final Grade</th>
                    <th>Approved</th>
                    <th>Submitted At</th>
                </tr>
                <?php foreach ($raw_rows as $index => $row): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars((string)$row['course_code']) ?></td>
                        <td><?= htmlspecialchars((string)$row['final_grade']) ?></td>
                        <td><?= htmlspecialchars((string)$row['grade_approved']) ?></td>
                        <td><?= htmlspecialchars((string)($row['grade_submitted_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No numeric grade rows were found.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>StudyPlanGenerator Output</h2>
        <p><strong>Completed Courses:</strong> <?= (int)$stats['completed_courses'] ?>/<?= (int)$stats['total_courses'] ?></p>
        <p><strong>Completion Percentage:</strong> <?= htmlspecialchars((string)$stats['completion_percentage']) ?>%</p>
        <p><strong>Completed Units:</strong> <?= (int)$stats['completed_units'] ?>/<?= (int)$stats['total_units'] ?></p>
        <pre><?= htmlspecialchars(json_encode($stats, JSON_PRETTY_PRINT)) ?></pre>
    </div>

    <div class="card <?= $matches ? 'ok' : 'warn' ?>">
        <h2>Conclusion</h2>
        <?php if ($matches): ?>
            <p>The study-plan generator matches the database for student <strong>220100064</strong>.</p>
            <p>Approved passing rows: <strong><?= count($passing_rows) ?></strong></p>
            <p>Generator completed courses: <strong><?= (int)$stats['completed_courses'] ?></strong></p>
        <?php else: ?>
            <p>The study-plan generator does not match the current database rows for student <strong>220100064</strong>.</p>
            <p>Approved passing rows: <strong><?= count($passing_rows) ?></strong></p>
            <p>Generator completed courses: <strong><?= (int)$stats['completed_courses'] ?></strong></p>
        <?php endif; ?>
    </div>
</body>
</html>
