<?php
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

$conn = getDBConnection();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['student_id']) || trim($_GET['student_id']) === '') {
    die("Invalid student ID.");
}

$adviser_id = (int)$_SESSION['id'];
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';
$student_id = trim($_GET['student_id']);
$adviser_program = '';
$batches = [];
$student = null;
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/study-plan/adviser/bootstrap',
        [
            'bridge_authorized' => true,
            'adviser_id' => $adviser_id,
            'student_id' => $student_id,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $adviser_program = (string) ($bridgeData['adviser_program'] ?? '');
        $batches = isset($bridgeData['batches']) && is_array($bridgeData['batches']) ? $bridgeData['batches'] : [];
        $student = isset($bridgeData['student']) && is_array($bridgeData['student']) ? $bridgeData['student'] : null;

        if (!empty($bridgeData['access_granted']) && $student) {
            $bridgeLoaded = true;
        } elseif (!empty($bridgeData['message'])) {
            die((string) $bridgeData['message']);
        }
    }
}

if (!$bridgeLoaded) {
    $adviser_stmt = $conn->prepare("SELECT program FROM adviser WHERE id = ? LIMIT 1");
    $adviser_stmt->bind_param("i", $adviser_id);
    $adviser_stmt->execute();
    $adviser_data = $adviser_stmt->get_result()->fetch_assoc();
    $adviser_program = $adviser_data['program'] ?? '';

    $batch_stmt = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ?");
    $batch_stmt->bind_param("i", $adviser_id);
    $batch_stmt->execute();
    $batch_result = $batch_stmt->get_result();
    $batches = [];
    while ($row = $batch_result->fetch_assoc()) {
        $batches[] = trim($row['batch']);
    }

    if (empty($batches) || empty($adviser_program)) {
        die("Access denied. Adviser batch/program assignment is missing.");
    }

    $batch_conditions = implode(' OR ', array_map(function($batch) use ($conn) {
        $batch_safe = $conn->real_escape_string($batch);
        return "student_number LIKE '{$batch_safe}%'";
    }, $batches));

    $student_query = "
        SELECT student_number, last_name, first_name, middle_name, program, date_of_admission
        FROM student_info
        WHERE student_number = ?
          AND program = ?
          AND (" . $batch_conditions . ")
        LIMIT 1
    ";

    $student_stmt = $conn->prepare($student_query);
    $student_stmt->bind_param("ss", $student_id, $adviser_program);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();
    $student = $student_result->fetch_assoc();

    if (!$student) {
        die("Access denied. Student is not in your assigned batch/program.");
    }
}

$generator = new StudyPlanGenerator($student_id, $student['program']);
$study_plan = $generator->generateOptimizedPlan();
$stats = $generator->getCompletionStats();
$ay_courses_by_term = $generator->getAllCoursesGroupedByTerm();

$admission_year = null;
$admission_date = (string)($student['date_of_admission'] ?? '');
if ($admission_date !== '' && strtotime($admission_date) !== false) {
    $admission_year = (int)date('Y', strtotime($admission_date));
}
if ($admission_year === null && strlen($student_id) >= 4) {
    $candidate_year = (int)substr($student_id, 0, 4);
    $current_year = (int)date('Y');
    if ($candidate_year >= 2000 && $candidate_year <= $current_year) {
        $admission_year = $candidate_year;
    }
}
if ($admission_year === null) {
    $admission_year = (int)date('Y') - 4;
}

$full_name = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Study Plan</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #f4f6f9; color: #333; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 8px 14px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 46px;
        }
        .title-content { display: flex; align-items: center; font-weight: 600;}
        .title-content img { width: 32px; height: 32px; margin-right: 10px; cursor: pointer; }
        .adviser-name {
            font-size: 15px;
            font-weight: 600;
            color: #facc41;
            background: rgba(250, 204, 65, 0.15);
            border: 1px solid rgba(250, 204, 65, 0.3);
            border-radius: 7px;
            padding: 6px 10px;
        }
        .sidebar {
            width: 250px;
            height: calc(100vh - 46px);
            position: fixed;
            top: 46px;
            left: 0;
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }
        .sidebar.collapsed { transform: translateX(-100%); }
        .sidebar-header { padding: 0 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.2); margin-bottom: 8px; }
        .sidebar-menu { list-style: none; }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            color: #fff;
            text-decoration: none;
            padding: 13px 20px;
            border-left: 3px solid transparent;
        }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); border-left-color: #4CAF50; }
        .sidebar-menu a.active { background: rgba(255,255,255,0.15); border-left-color: #4CAF50; }
        .sidebar-menu img { width: 20px; height: 20px; margin-right: 10px; filter: invert(1); }
        .menu-group-title { padding: 10px 20px 6px; font-size: 12px; text-transform: uppercase; color: rgba(255,255,255,0.7); font-weight: 600; }
        .main-content { margin-left: 250px; padding: 62px 14px 20px; transition: margin-left 0.3s ease; }
        .main-content.expanded { margin-left: 0; }
        .container { max-width: 1280px; margin: 0 auto; }
        .panel {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 14px;
            margin-bottom: 12px;
        }
        .heading {
            background: linear-gradient(135deg, #206018 0%, #2d8023 100%);
            color: #fff;
            border-radius: 8px;
            padding: 14px;
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 12px;
        }
        .student-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 8px;
            font-size: 14px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .stat-card {
            background: #f8fafc;
            border: 1px solid #e5ebf1;
            border-radius: 8px;
            padding: 10px;
        }
        .stat-label { font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .stat-value { font-size: 18px; font-weight: 700; color: #206018; }
        .term-card {
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 8px;
            margin-bottom: 12px;
            overflow: hidden;
        }
        .term-header {
            background: #f1f5f9;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            border-bottom: 1px solid #e6e6e6;
        }
        .term-title { font-weight: 700; color: #1f2937; }
        .term-meta { font-size: 12px; color: #555; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 10px; border-bottom: 1px solid #eee; font-size: 13px; }
        th {
            background: #333;
            color: #fff;
            text-transform: uppercase;
            font-size: 12px;
            text-align: left;
        }
        td { text-align: left; vertical-align: middle; }
        th:nth-child(3), td:nth-child(3),
        th:nth-child(4), td:nth-child(4) {
            text-align: center;
        }
        .tag {
            display: inline-block;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: 11px;
            margin-right: 5px;
            margin-bottom: 3px;
            font-weight: 600;
        }
        .tag-retake { background: #ffe8cc; color: #9a3412; }
        .tag-cross { background: #dbeafe; color: #1d4ed8; }
        .ay-overview-wrap {
            display: flex;
            justify-content: center;
            margin: 14px 0 18px;
        }
        .ay-overview-btn {
            background: linear-gradient(135deg, #1565C0, #1976D2);
            color: #fff;
            border: none;
            padding: 11px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(21, 101, 192, 0.25);
        }
        .ay-overview-btn:hover {
            background: linear-gradient(135deg, #0d47a1, #1565C0);
        }
        #ay-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 9000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 30px 15px;
        }
        #ay-modal-overlay.open {
            display: flex;
        }
        #ay-modal {
            background: #fff;
            border-radius: 12px;
            width: 100%;
            max-width: 900px;
            box-shadow: 0 8px 40px rgba(0,0,0,0.25);
            overflow: hidden;
            margin: auto;
        }
        #ay-modal .modal-header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #ay-modal .modal-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
        }
        .menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            font-size: 18px;
            cursor: pointer;
            margin-right: 10px;
            border-radius: 6px;
            transition: all 0.2s ease;
            line-height: 1;
            padding: 0;
        }
        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
        }
        #ay-modal .modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 24px;
            cursor: pointer;
            line-height: 1;
            padding: 0 4px;
        }
        #ay-modal .modal-body {
            padding: 20px 24px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .ay-term-block {
            margin-bottom: 16px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .ay-term-header {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 10px 16px;
            font-weight: 700;
            font-size: 14px;
            color: #206018;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }
        .ay-term-header:hover {
            background: linear-gradient(135deg, #d0edda, #aad9ae);
        }
        .ay-term-header .ay-term-toggle {
            font-size: 18px;
            transition: transform 0.2s;
        }
        .ay-term-header.collapsed .ay-term-toggle {
            transform: rotate(-90deg);
        }
        .ay-term-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .ay-term-body.hidden {
            display: none;
        }
        .ay-col {
            padding: 12px 16px;
        }
        .ay-col:first-child {
            border-right: 1px solid #eee;
        }
        .ay-col h4 {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 2px solid;
        }
        .ay-col.completed h4 {
            color: #2e7d32;
            border-color: #4CAF50;
        }
        .ay-col.uncomplete h4 {
            color: #c62828;
            border-color: #f44336;
        }
        .ay-course-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12px;
        }
        .ay-course-row:last-child {
            border-bottom: none;
        }
        .ay-course-code {
            font-weight: 600;
            white-space: nowrap;
            min-width: 72px;
        }
        .ay-course-title {
            flex: 1;
            color: #444;
            line-height: 1.4;
        }
        .ay-course-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .ay-badge-passed  { background: #e8f5e9; color: #2e7d32; }
        .ay-badge-failed  { background: #ffebee; color: #c62828; }
        .ay-badge-inc     { background: #fff3e0; color: #e65100; }
        .ay-badge-dropped { background: #f3e5f5; color: #6a1b9a; }
        .ay-badge-pending { background: #e3f2fd; color: #1565c0; }
        .ay-empty {
            font-size: 12px;
            color: #999;
            font-style: italic;
            padding: 6px 0;
        }
        .warning {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .btn-back {
            display: inline-block;
            text-decoration: none;
            padding: 7px 10px;
            background: #e5e7eb;
            border-radius: 5px;
            color: #333;
            font-weight: 600;
            font-size: 12px;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 600px) {
            .ay-term-body {
                grid-template-columns: 1fr;
            }
            .ay-col:first-child {
                border-right: none;
                border-bottom: 1px solid #eee;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title-content">
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color:#d9e441;">ASPLAN</span>
        </div>
        <span class="adviser-name"><?= $adviser_name ?> | Adviser</span>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header"><h3>Adviser Panel</h3></div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>
            <div class="menu-group">
                <div class="menu-group-title">Student Management</div>
                <li><a href="pending_accounts.php"><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
                <li><a href="checklist_eval.php"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
                <li><a href="study_plan_list.php" class="active"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>
            </div>
            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="main-content" id="mainContent">
        <div class="container">
            <div class="heading">Student Study Plan</div>

            <div class="panel">
                <a class="btn-back" href="study_plan_list.php">Back to Study Plan List</a>
                <div class="student-meta" style="margin-top: 10px;">
                    <div><strong>Student ID:</strong> <?= htmlspecialchars($student['student_number']) ?></div>
                    <div><strong>Name:</strong> <?= htmlspecialchars($full_name) ?></div>
                    <div><strong>Program:</strong> <?= htmlspecialchars($student['program']) ?></div>
                    <div><strong>Admission Date:</strong> <?= htmlspecialchars($student['date_of_admission'] ?? '') ?></div>
                </div>
            </div>

            <div class="panel">
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-label">Completion</div>
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['completion_percentage']) ?>%</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Completed Courses</div>
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['completed_courses']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Remaining Courses</div>
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['remaining_courses']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Back Subjects</div>
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['back_subjects']) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Retention Status</div>
                        <div class="stat-value"><?= htmlspecialchars((string)$stats['retention_status']) ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($stats['thrice_failed_count'])): ?>
                <div class="warning">
                    Study plan generation is limited because this student has <?= (int)$stats['thrice_failed_count'] ?> course(s) failed three or more times.
                </div>
            <?php endif; ?>

            <div class="ay-overview-wrap">
                <button class="ay-overview-btn" type="button" onclick="openAYModal()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="9"></rect>
                        <rect x="14" y="3" width="7" height="5"></rect>
                        <rect x="14" y="12" width="7" height="9"></rect>
                        <rect x="3" y="16" width="7" height="5"></rect>
                    </svg>
                    A.Y. Course Overview
                </button>
            </div>

            <?php if (empty($study_plan)): ?>
                <div class="panel">No study plan terms generated for this student yet.</div>
            <?php else: ?>
                <?php foreach ($study_plan as $term): ?>
                    <div class="term-card">
                        <div class="term-header">
                            <div class="term-title"><?= htmlspecialchars($term['year']) ?> - <?= htmlspecialchars($term['semester']) ?></div>
                            <div class="term-meta">
                                Total Units: <?= (int)($term['total_units'] ?? 0) ?>
                                <?php if (!empty($term['max_units'])): ?> | Max Units: <?= (int)$term['max_units'] ?><?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($term['skipped'])): ?>
                            <div class="warning" style="margin:10px;">Skipped Term: <?= htmlspecialchars($term['skip_reason'] ?? 'Retention rule applied') ?></div>
                        <?php else: ?>
                            <table>
                                <colgroup>
                                    <col style="width:20%;">
                                    <col style="width:56%;">
                                    <col style="width:10%;">
                                    <col style="width:14%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Title</th>
                                        <th>Units</th>
                                        <th>Flags</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (($term['courses'] ?? []) as $course): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($course['code'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($course['title'] ?? '') ?></td>
                                        <td><?= (int)($course['units'] ?? 0) ?></td>
                                        <td>
                                            <?php if (!empty($course['needs_retake'])): ?>
                                                <span class="tag tag-retake">Retake</span>
                                            <?php endif; ?>
                                            <?php if (!empty($course['cross_registered'])): ?>
                                                <span class="tag tag-cross">Cross-Registered</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="ay-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ay-modal-title">
        <div id="ay-modal">
            <div class="modal-header">
                <h2 id="ay-modal-title">Academic Year Course Overview</h2>
                <button class="modal-close" onclick="closeAYModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <?php
                $year_num_map = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
                $sem_label_map = [
                    '1st Sem' => 'First Semester',
                    '2nd Sem' => 'Second Semester',
                    'Mid Year' => 'Mid Year',
                ];

                foreach ($ay_courses_by_term as $term_key => $term_data):
                    $yr_num = $year_num_map[$term_data['year']] ?? 1;
                    $sy_start = $admission_year + ($yr_num - 1);
                    $sy_end = $sy_start + 1;
                    $ay_label = 'A.Y. ' . $sy_start . '-' . $sy_end;
                    $sem_full = $sem_label_map[$term_data['semester']] ?? $term_data['semester'];
                    $completed_list = $term_data['completed'] ?? [];
                    $uncomplete_list = $term_data['uncomplete'] ?? [];
                    $block_id = 'ay-block-' . preg_replace('/[^a-z0-9]/i', '-', (string)$term_key);
                ?>
                <div class="ay-term-block">
                    <div class="ay-term-header" onclick="toggleAYBlock('<?= htmlspecialchars($block_id); ?>', this)">
                        <span><?= htmlspecialchars($term_data['year'] . ' - ' . $term_data['semester']); ?> | <?= htmlspecialchars($ay_label); ?> (<?= htmlspecialchars($sem_full); ?>)</span>
                        <span class="ay-term-toggle">&#9662;</span>
                    </div>
                    <div class="ay-term-body" id="<?= htmlspecialchars($block_id); ?>">
                        <div class="ay-col completed">
                            <h4>Completed Courses (<?= count($completed_list); ?>)</h4>
                            <?php if (empty($completed_list)): ?>
                                <div class="ay-empty">No completed courses in this term.</div>
                            <?php else: ?>
                                <?php foreach ($completed_list as $c): ?>
                                    <div class="ay-course-row">
                                        <div class="ay-course-code"><?= htmlspecialchars((string)($c['code'] ?? '')); ?></div>
                                        <div class="ay-course-title"><?= htmlspecialchars((string)($c['title'] ?? '')); ?></div>
                                        <span class="ay-course-badge ay-badge-passed"><?= htmlspecialchars((string)($c['grade'] ?? 'Passed')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="ay-col uncomplete">
                            <h4>Uncompleted Courses (<?= count($uncomplete_list); ?>)</h4>
                            <?php if (empty($uncomplete_list)): ?>
                                <div class="ay-empty">All courses completed for this term.</div>
                            <?php else: ?>
                                <?php foreach ($uncomplete_list as $c): ?>
                                    <?php
                                        $reason = strtoupper((string)($c['reason'] ?? 'Not Yet Taken'));
                                        $badge_class = 'ay-badge-pending';
                                        if ($reason === 'FAILED') {
                                            $badge_class = 'ay-badge-failed';
                                        } elseif ($reason === 'INC') {
                                            $badge_class = 'ay-badge-inc';
                                        } elseif ($reason === 'DROPPED') {
                                            $badge_class = 'ay-badge-dropped';
                                        }
                                    ?>
                                    <div class="ay-course-row">
                                        <div class="ay-course-code"><?= htmlspecialchars((string)($c['code'] ?? '')); ?></div>
                                        <div class="ay-course-title"><?= htmlspecialchars((string)($c['title'] ?? '')); ?></div>
                                        <span class="ay-course-badge <?= htmlspecialchars($badge_class); ?>"><?= htmlspecialchars((string)($c['reason'] ?? 'Not Yet Taken')); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        function openAYModal() {
            const overlay = document.getElementById('ay-modal-overlay');
            if (!overlay) {
                return;
            }
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeAYModal() {
            const overlay = document.getElementById('ay-modal-overlay');
            if (!overlay) {
                return;
            }
            overlay.classList.remove('open');
            document.body.style.overflow = '';
        }

        const ayOverlay = document.getElementById('ay-modal-overlay');
        if (ayOverlay) {
            ayOverlay.addEventListener('click', function(e) {
                if (e.target === ayOverlay) {
                    closeAYModal();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAYModal();
            }
        });

        function toggleAYBlock(blockId, headerEl) {
            const body = document.getElementById(blockId);
            if (!body) {
                return;
            }
            const isHidden = body.classList.toggle('hidden');
            headerEl.classList.toggle('collapsed', isHidden);
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }
    </script>
</body>
</html>
