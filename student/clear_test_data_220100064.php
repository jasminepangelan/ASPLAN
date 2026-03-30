<?php
/**
 * Clear/Reset Test Data for Student 220100064
 * This tool helps clean up pre-populated test data
 */

require_once __DIR__ . '/../config/config.php';

$student_id = '220100064';
$conn = getDBConnection();

// Handle form submission
$action_taken = false;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'clear_all') {
        // Clear all grades for this student
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
        
        $action_taken = true;
        $message = "✅ Successfully cleared ALL grades for student {$student_id}. ({$affected} records updated)";
        
    } elseif ($action === 'keep_1st_sem_only') {
        // Get 1st year 1st sem courses
        $courses_query = $conn->query("
            SELECT SUBSTRING(curriculumyear_coursecode, 6) AS course_code 
            FROM cvsucarmona_courses 
            WHERE FIND_IN_SET('BSCS', REPLACE(programs, ', ', ',')) > 0
            AND year_level = 'First Year' AND semester = 'First Semester'
        ");
        
        $first_sem_courses = [];
        while ($row = $courses_query->fetch_assoc()) {
            $first_sem_courses[] = $row['course_code'];
        }
        
        if (count($first_sem_courses) > 0) {
            $placeholders = str_repeat('?,', count($first_sem_courses) - 1) . '?';
            
            // Clear grades for courses NOT in 1st year 1st sem
            $update = $conn->prepare("
                UPDATE student_checklists 
                SET final_grade = '', 
                    evaluator_remarks = '', 
                    professor_instructor = ''
                WHERE student_id = ?
                AND course_code NOT IN ({$placeholders})
            ");
            
            $types = str_repeat('s', count($first_sem_courses) + 1);
            $params = array_merge([$student_id], $first_sem_courses);
            $update->bind_param($types, ...$params);
            $update->execute();
            $affected = $update->affected_rows;
            $update->close();
            
            $action_taken = true;
            $message = "✅ Successfully kept only 1st Year 1st Semester grades. Cleared {$affected} other courses.";
        }
        
    } elseif ($action === 'set_sample_grades') {
        // First clear all
        $conn->query("
            UPDATE student_checklists 
            SET final_grade = '', evaluator_remarks = '', professor_instructor = ''
            WHERE student_id = '{$student_id}'
        ");
        
        // Get 1st year 1st sem courses and add sample grades
        $courses = $conn->query("
            SELECT SUBSTRING(curriculumyear_coursecode, 6) AS course_code 
            FROM cvsucarmona_courses 
            WHERE FIND_IN_SET('BSCS', REPLACE(programs, ', ', ',')) > 0
            AND year_level = 'First Year' AND semester = 'First Semester'
            ORDER BY curriculumyear_coursecode
            LIMIT 7
        ");
        
        $sample_grades = ['1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00'];
        $index = 0;
        
        while ($row = $courses->fetch_assoc() && $index < count($sample_grades)) {
            $course_code = $row['course_code'];
            $grade = $sample_grades[$index];
            
            $update = $conn->prepare("
                UPDATE student_checklists 
                SET final_grade = ?, 
                    evaluator_remarks = 'Approved', 
                    professor_instructor = 'Test Instructor'
                WHERE student_id = ? AND course_code = ?
            ");
            $update->bind_param("sss", $grade, $student_id, $course_code);
            $update->execute();
            $update->close();
            
            $index++;
        }
        
        $action_taken = true;
        $message = "✅ Successfully set sample grades for {$index} courses in 1st Year 1st Semester.";
    }
}

// Get current data
$query = $conn->prepare("
    SELECT sc.course_code, sc.final_grade, sc.evaluator_remarks, sc.professor_instructor,
           cb.course_title, 
           CASE cb.year_level
               WHEN 'First Year' THEN '1st Yr'
               WHEN 'Second Year' THEN '2nd Yr'
               WHEN 'Third Year' THEN '3rd Yr'
               WHEN 'Fourth Year' THEN '4th Yr'
               ELSE cb.year_level
           END AS year,
           CASE cb.semester
               WHEN 'First Semester' THEN '1st Sem'
               WHEN 'Second Semester' THEN '2nd Sem'
               WHEN 'Mid Year' THEN 'Mid Year'
               WHEN 'Midyear' THEN 'Mid Year'
               ELSE cb.semester
           END AS semester,
           cb.credit_units_lec AS credit_unit_lec, cb.credit_units_lab AS credit_unit_lab
    FROM student_checklists sc
    LEFT JOIN cvsucarmona_courses cb ON SUBSTRING(cb.curriculumyear_coursecode, 6) = sc.course_code
        AND FIND_IN_SET('BSCS', REPLACE(cb.programs, ', ', ',')) > 0
    WHERE sc.student_id = ?
    ORDER BY 
        FIELD(cb.year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year'),
        FIELD(cb.semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear'),
        sc.course_code
");
$query->bind_param("s", $student_id);
$query->execute();
$result = $query->get_result();

$courses_with_grades = 0;
$courses_without_grades = 0;
$first_sem_with_grades = 0;
$all_courses = [];

while ($row = $result->fetch_assoc()) {
    $has_grade = !empty($row['final_grade']);
    if ($has_grade) {
        $courses_with_grades++;
        if ($row['year'] === '1st Yr' && $row['semester'] === '1st Sem') {
            $first_sem_with_grades++;
        }
    } else {
        $courses_without_grades++;
    }
    $all_courses[] = $row;
}

$query->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Test Data - Student 220100064</title>
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
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            color: white;
        }
        .stat-card.green { background: linear-gradient(135deg, #4CAF50, #45a049); }
        .stat-card.blue { background: linear-gradient(135deg, #2196F3, #1976D2); }
        .stat-card.orange { background: linear-gradient(135deg, #FF9800, #F57C00); }
        .stat-card.purple { background: linear-gradient(135deg, #9C27B0, #7B1FA2); }
        .stat-card .number { font-size: 32px; font-weight: 700; }
        .stat-card .label { font-size: 14px; opacity: 0.9; margin-top: 5px; }
        
        .actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .action-card {
            background: linear-gradient(135deg, #ffffff, #f8f9fa);
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .action-card h3 {
            color: #206018;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .action-card p {
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
        }
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(220,53,69,0.4); }
        .btn-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #333;
        }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(255,193,7,0.4); }
        .btn-success {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
        }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(40,167,69,0.4); }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
        }
        th {
            background: #206018;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }
        tr:hover { background: #f9f9f9; }
        .has-grade { background: #fff3cd; }
        .first-sem { background: #d4edda; }
        
        .warning-box {
            background: #fff3cd;
            padding: 20px;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <h1>🗑️ Clear Test Data - Student 220100064</h1>
    
    <?php if ($action_taken): ?>
    <div class="message success">
        <?= $message ?>
    </div>
    <div style="text-align: center; margin-bottom: 20px;">
        <a href="check_current_student_data.php" style="padding: 10px 20px; background: #206018; color: white; text-decoration: none; border-radius: 6px; display: inline-block;">
            🔍 View Study Plan (Login as 220100064)
        </a>
    </div>
    <?php endif; ?>
    
    <div class="container">
        <h2>📊 Current Status</h2>
        <div class="stats">
            <div class="stat-card green">
                <div class="number"><?= $courses_with_grades ?></div>
                <div class="label">Courses with Grades</div>
            </div>
            <div class="stat-card blue">
                <div class="number"><?= $first_sem_with_grades ?></div>
                <div class="label">1st Yr 1st Sem with Grades</div>
            </div>
            <div class="stat-card orange">
                <div class="number"><?= $courses_without_grades ?></div>
                <div class="label">Empty Courses</div>
            </div>
            <div class="stat-card purple">
                <div class="number"><?= count($all_courses) ?></div>
                <div class="label">Total Courses</div>
            </div>
        </div>
        
        <?php if ($courses_with_grades > 10): ?>
        <div class="warning-box">
            <strong>⚠️ Pre-populated Test Data Detected!</strong><br>
            This student has <?= $courses_with_grades ?> courses with grades. If they only completed 1st year 1st semester, 
            this indicates pre-populated test data that should be cleared for accurate Academic Progress Overview.
        </div>
        <?php endif; ?>
    </div>
    
    <div class="container">
        <h2>🛠️ Actions</h2>
        <div class="actions">
            <div class="action-card">
                <h3>🗑️ Clear ALL Grades</h3>
                <p>Remove all final grades, remarks, and instructors. Student will have 0% completion.</p>
                <form method="POST" onsubmit="return confirm('Are you sure you want to clear ALL grades for student 220100064?');">
                    <input type="hidden" name="action" value="clear_all">
                    <button type="submit" class="btn btn-danger">Clear All Data</button>
                </form>
            </div>
            
            <div class="action-card">
                <h3>📚 Keep 1st Semester Only</h3>
                <p>Keep only 1st Year 1st Semester grades. Clear all other courses.</p>
                <form method="POST" onsubmit="return confirm('Keep only 1st year 1st semester grades?');">
                    <input type="hidden" name="action" value="keep_1st_sem_only">
                    <button type="submit" class="btn btn-warning">Keep 1st Sem Only</button>
                </form>
            </div>
            
            <div class="action-card">
                <h3>✨ Set Sample Grades</h3>
                <p>Clear all, then add 7 sample passing grades (1.50-3.00) for 1st year 1st semester.</p>
                <form method="POST" onsubmit="return confirm('Set sample grades for 1st semester courses?');">
                    <input type="hidden" name="action" value="set_sample_grades">
                    <button type="submit" class="btn btn-success">Set Sample Data</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="container">
        <h2>📋 Course Details</h2>
        <p><strong>Legend:</strong> 
            <span style="background: #d4edda; padding: 3px 8px; border-radius: 3px; margin-right: 10px;">1st Year 1st Semester</span>
            <span style="background: #fff3cd; padding: 3px 8px; border-radius: 3px;">Has Grade</span>
        </p>
        
        <table>
            <thead>
                <tr>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th>Year/Sem</th>
                    <th>Units</th>
                    <th>Final Grade</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_courses as $course): 
                    $has_grade = !empty($course['final_grade']);
                    $is_first_sem = ($course['year'] === '1st Yr' && $course['semester'] === '1st Sem');
                    $row_class = $is_first_sem ? 'first-sem' : ($has_grade ? 'has-grade' : '');
                ?>
                <tr class="<?= $row_class ?>">
                    <td><strong><?= htmlspecialchars($course['course_code']) ?></strong></td>
                    <td><?= htmlspecialchars($course['course_title'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($course['year'] ?? 'N/A') ?> / <?= htmlspecialchars($course['semester'] ?? 'N/A') ?></td>
                    <td><?= ($course['credit_unit_lec'] ?? 0) + ($course['credit_unit_lab'] ?? 0) ?></td>
                    <td><strong><?= $has_grade ? htmlspecialchars($course['final_grade']) : '<em>(empty)</em>' ?></strong></td>
                    <td><?= $has_grade ? htmlspecialchars($course['evaluator_remarks']) : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div style="text-align: center; margin-top: 30px; padding: 20px; background: white; border-radius: 8px;">
        <p style="color: #666; font-size: 14px;">
            After making changes, login as student 220100064 and visit the Study Plan page to see updated Academic Progress Overview.
        </p>
    </div>
</body>
</html>

<?php
closeDBConnection($conn);
?>
