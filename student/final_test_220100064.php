<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/generate_study_plan.php';

if (!isset($_SESSION['student_id'])) {
    die("Not logged in");
}

$student_id = (string)$_SESSION['student_id'];
$program = StudyPlanGenerator::lookupStudentProgram($student_id);
$generator = StudyPlanGenerator::createForStudent($student_id);
$stats = $generator->getCompletionStats();

$conn = getDBConnection();

$stmt = $conn->prepare("
    SELECT course_code, final_grade, grade_approved, grade_submitted_at
    FROM student_checklists
    WHERE student_id = ?
      AND final_grade REGEXP '^[0-9]+(\\.[0-9]+)?$'
    ORDER BY grade_submitted_at DESC, course_code
");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
closeDBConnection($conn);

$passing = array_values(array_filter($rows, function ($row) {
    $grade = (float)($row['final_grade'] ?? 0);
    return $grade >= 1.0 && $grade <= 3.0 && (int)($row['grade_approved'] ?? 0) === 1;
}));
$matches = count($passing) === (int)$stats['completed_courses'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Final Study Plan Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .card { background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .ok { background: #edf8ee; border-left: 4px solid #2e7d32; }
        .warn { background: #fff7e6; border-left: 4px solid #ef6c00; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Final Study Plan Test</h1>
        <p><strong>Student ID:</strong> <?= htmlspecialchars($student_id) ?></p>
        <p><strong>Program:</strong> <?= htmlspecialchars($program !== '' ? $program : '[not found]') ?></p>
        <p><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>
    </div>

    <div class="card">
        <h2>Generator Stats</h2>
        <p><strong>Completed Courses:</strong> <?= (int)$stats['completed_courses'] ?>/<?= (int)$stats['total_courses'] ?></p>
        <p><strong>Completion:</strong> <?= htmlspecialchars((string)$stats['completion_percentage']) ?>%</p>
        <p><strong>Completed Units:</strong> <?= (int)$stats['completed_units'] ?>/<?= (int)$stats['total_units'] ?></p>
    </div>

    <div class="card">
        <h2>Approved Grade Rows</h2>
        <p><strong>Approved passing rows:</strong> <?= count($passing) ?></p>
        <?php if (!empty($rows)): ?>
            <table>
                <tr>
                    <th>Course</th>
                    <th>Grade</th>
                    <th>Approved</th>
                    <th>Submitted At</th>
                </tr>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['course_code']) ?></td>
                        <td><?= htmlspecialchars((string)$row['final_grade']) ?></td>
                        <td><?= htmlspecialchars((string)$row['grade_approved']) ?></td>
                        <td><?= htmlspecialchars((string)($row['grade_submitted_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No numeric grade rows found.</p>
        <?php endif; ?>
    </div>

    <div class="card <?= $matches ? 'ok' : 'warn' ?>">
        <h2>Conclusion</h2>
        <?php if ($matches): ?>
            <p>The study-plan generator matches the current database rows for this session.</p>
        <?php else: ?>
            <p>The study-plan generator does not match the current database rows for this session.</p>
        <?php endif; ?>
    </div>
</body>
</html>
