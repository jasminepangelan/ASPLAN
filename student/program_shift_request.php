<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/vite_legacy.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit();
}

$conn = getDBConnection();
psEnsureProgramShiftTables($conn);
$studentNumber = (string)$_SESSION['student_id'];
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$studentRow = null;
$programOptions = [];
$history = [];

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/program-shift/student/overview',
        [
            'bridge_authorized' => true,
            'student_id' => $studentNumber,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        if (isset($bridgeData['student']) && is_array($bridgeData['student'])) {
            $studentRow = $bridgeData['student'];
        }
        if (isset($bridgeData['program_options']) && is_array($bridgeData['program_options'])) {
            $programOptions = $bridgeData['program_options'];
        }
        if (isset($bridgeData['history']) && is_array($bridgeData['history'])) {
            $history = $bridgeData['history'];
        }
    }
}

if (!$studentRow) {
    $studentRow = psGetCurrentStudentInfo($conn, $studentNumber);
}

if (!$studentRow) {
    closeDBConnection($conn);
    header('Location: home_page_student.php?message=' . urlencode('Student profile not found.'));
    exit();
}

$currentProgram = trim((string)($studentRow['program'] ?? ''));
if (empty($programOptions)) {
    $programOptions = psGetProgramOptions($conn);
}
if (empty($history)) {
    $history = psFetchStudentRequestHistory($conn, $studentNumber);
}
$historyAll = $history;
$historyFilter = trim((string)($_GET['status'] ?? ''));
$allowedHistoryStatuses = ['all', 'pending_adviser', 'pending_coordinator', 'approved', 'rejected', 'cancelled'];
if ($historyFilter === '' || !in_array($historyFilter, $allowedHistoryStatuses, true)) {
    $historyFilter = 'all';
}
if ($historyFilter !== 'all') {
    $history = array_values(array_filter($history, static function ($row) use ($historyFilter) {
        return (string)($row['status'] ?? '') === $historyFilter;
    }));
}
$csrfToken = getCSRFToken();

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$lastName = htmlspecialchars((string)($studentRow['last_name'] ?? ''));
$firstName = htmlspecialchars((string)($studentRow['first_name'] ?? ''));
$middleName = htmlspecialchars((string)($studentRow['middle_name'] ?? ''));
$picture = resolveScopedPictureSrc($_SESSION['picture'] ?? '', '../', 'pix/anonymous.jpg');

$historyStats = [
    'all' => count($historyAll),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
];
foreach ($historyAll as $item) {
    $status = (string)($item['status'] ?? '');
    if ($status === 'approved') {
        $historyStats['approved']++;
    } elseif ($status === 'rejected') {
        $historyStats['rejected']++;
    }

    if ($status === 'pending_adviser' || $status === 'pending_coordinator') {
        $historyStats['pending']++;
    }
}

$studentShellPayload = htmlspecialchars(json_encode([
    'title' => 'Program Shift Center',
    'description' => 'Track your requests, review your current academic path, and submit a shift request through the existing adviser and coordinator approval flow.',
    'accent' => 'slate',
    'pageKey' => 'program-shift',
    'stats' => [
        ['label' => 'Current Program', 'value' => $currentProgram !== '' ? (string)$currentProgram : 'Not set'],
        ['label' => 'Pending', 'value' => (string)$historyStats['pending']],
        ['label' => 'Approved', 'value' => (string)$historyStats['approved']],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

$studentProgramShiftWorkspacePayload = htmlspecialchars(json_encode([
    'title' => 'Program Shift Command Deck',
    'note' => 'Use quick actions to jump into the request form, browse your history, or focus the destination program selector while the current PHP request workflow stays intact.',
    'stats' => [
        ['label' => 'Current Program', 'value' => $currentProgram !== '' ? (string)$currentProgram : 'Not set'],
        ['label' => 'Pending', 'value' => (string)$historyStats['pending']],
        ['label' => 'Approved', 'value' => (string)$historyStats['approved']],
    ],
    'reminders' => [
        'Only one active request can stay pending at a time.',
        'Be specific in your reason so adviser and coordinator review can move faster.',
        'Strict course equivalency still decides which subjects can be credited after approval.',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Shift Request</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <?= renderLegacyViteTags(['resources/js/student-shell.jsx', 'resources/js/student-program-shift-workspace.jsx']) ?>
    <style>
        :root {
            --brand-900: #164f14;
            --brand-700: #1f7a2f;
            --brand-500: #35a44a;
            --ink-900: #122034;
            --ink-700: #334155;
            --ink-500: #64748b;
            --line: #dbe2ea;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --page-a: #edf6ef;
            --page-b: #eff4ff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background:
                radial-gradient(circle at 8% 0%, #d3f3d6 0, transparent 36%),
                radial-gradient(circle at 94% 6%, #dae8ff 0, transparent 30%),
                linear-gradient(160deg, var(--page-a) 0%, var(--page-b) 100%);
            color: var(--ink-900);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .title-bar {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #ffffffff;
            padding: 5px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .title-content {
            display: flex;
            align-items: center;
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
        }

        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .student-info {
            font-size: 16px;
            font-weight: 600;
            color: #facc41;
            font-family: 'Segoe UI', Arial, sans-serif;
            letter-spacing: 0.5px;
            background: rgba(250, 204, 65, 0.15);
            padding: 5px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid rgba(250, 204, 65, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-info img {
            width: 27px !important;
            height: 27px !important;
            border-radius: 50%;
            object-fit: cover;
        }

        .title-bar img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
        }

        .sidebar {
            width: 250px;
            height: calc(100vh - 38px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: #fff;
            position: fixed;
            left: 0;
            top: 38px;
            padding: 20px 0;
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.18);
            overflow-y: auto;
            transition: transform 0.28s ease;
            z-index: 9;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 0 20px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 12px;
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .menu-group {
            margin-bottom: 16px;
        }

        .menu-group-title {
            padding: 10px 20px 6px;
            font-size: 12px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.72);
            font-weight: 700;
            letter-spacing: 1px;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #fff;
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: background-color 0.2s ease;
        }

        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.12);
            border-left-color: #4caf50;
        }

        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.18);
            border-left-color: #facc15;
        }

        .sidebar-menu img {
            width: 19px;
            height: 19px;
            filter: brightness(0) invert(1);
        }

        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 38px);
            transition: margin-left 0.28s ease;
        }

        .main-content.expanded {
            margin-left: 0;
        }

        .container {
            max-width: 1120px;
            margin: 28px auto;
            padding: 0 16px 26px;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 270px;
            gap: 14px;
            margin-bottom: 16px;
            align-items: stretch;
        }

        .hero-card {
            background: linear-gradient(125deg, #14384f 0%, #1e4f37 54%, #235f34 100%);
            color: #fff;
            border-radius: 16px;
            padding: 18px 20px;
            box-shadow: 0 12px 28px rgba(17, 51, 70, 0.24);
            height: 100%;
        }

        .hero-card h1 {
            margin: 0 0 8px;
            font-size: 27px;
            line-height: 1.2;
            letter-spacing: 0.2px;
        }

        .hero-card p {
            margin: 0;
            color: rgba(255, 255, 255, 0.92);
            font-size: 14px;
            line-height: 1.6;
        }

        .program-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            max-width: 100%;
        }

        .program-chip span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .hero-stats {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 12px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            align-content: start;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.09);
            height: 100%;
        }

        .mini {
            border: 1px solid var(--line);
            background: linear-gradient(180deg, #fbfdff 0%, #f4f8fc 100%);
            border-radius: 10px;
            padding: 10px;
        }

        .mini .k { font-size: 11px; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.4px; }
        .mini .v { font-size: 20px; font-weight: 800; margin-top: 3px; }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 270px;
            gap: 16px;
            margin-bottom: 16px;
            align-items: stretch;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
            padding: 22px;
            height: 100%;
        }

        .card h2 {
            margin: 0 0 10px;
            font-size: 29px;
            letter-spacing: 0.1px;
        }

        .muted {
            color: var(--ink-500);
            font-size: 14px;
            line-height: 1.5;
        }

        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-weight: 600;
        }

        .alert.success {
            background: #ecfdf3;
            color: #166534;
            border: 1px solid #86efac;
        }

        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .request-form {
            display: grid;
            gap: 14px;
        }

        label {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .field {
            display: grid;
            gap: 6px;
        }

        select, textarea, button:not(.menu-toggle) {
            font-family: inherit;
            font-size: 14px;
            border-radius: 10px;
            border: 1px solid #cfd8e3;
            padding: 11px 12px;
            background: #fff;
        }

        select:focus, textarea:focus {
            outline: none;
            border-color: #2f8f41;
            box-shadow: 0 0 0 3px rgba(47, 143, 65, 0.15);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }

        .submit-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        button:not(.menu-toggle) {
            border: 0;
            background: linear-gradient(120deg, var(--brand-700) 0%, var(--brand-500) 100%);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            min-width: 230px;
            padding: 12px 14px;
            box-shadow: 0 8px 18px rgba(31, 122, 47, 0.27);
        }

        button:not(.menu-toggle):hover {
            filter: brightness(0.95);
        }

        .tips {
            display: grid;
            gap: 10px;
            margin-top: 8px;
        }

        .tip {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            font-size: 13px;
            color: var(--ink-700);
            line-height: 1.5;
        }

        .tip strong { color: #0f172a; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 840px;
        }

        .table-wrap {
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: auto;
            background: #fff;
            margin-top: 10px;
        }

        .history-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .status-inline {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 8px 0 12px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid var(--line);
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            background: #fff;
            color: #334155;
        }

        .chip strong { margin-left: 6px; }

        .history-toolbar select {
            max-width: 280px;
        }

        th, td {
            border-bottom: 1px solid #e8edf4;
            text-align: left;
            padding: 11px 10px;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: linear-gradient(180deg, #f8fbff 0%, #f1f6fc 100%);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #334155;
            position: sticky;
            top: 0;
        }

        .status {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
        }

        .status.pending_adviser, .status.pending_coordinator { background: #fef3c7; color: #92400e; }
        .status.approved { background: #dcfce7; color: #166534; }
        .status.rejected { background: #fee2e2; color: #b91c1c; }
        .status.cancelled { background: #e5e7eb; color: #374151; }

        .row-muted { color: #64748b; font-size: 12px; }

        .history-empty {
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            padding: 16px;
            background: #f8fafc;
        }

        .history-card h2 {
            margin-bottom: 4px;
        }

        @media (max-width: 960px) {
            .hero,
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .title-bar {
                padding: 3px 10px;
            }

            .title-bar img {
                height: 24px;
                margin-right: 8px;
            }

            .student-info {
                font-size: 9px;
                padding: 2px 5px;
            }

            .student-info img {
                width: 16px !important;
                height: 16px !important;
            }

            .card h2 { font-size: 24px; }
            button:not(.menu-toggle) { width: 100%; min-width: 0; }
            th, td { font-size: 12px; }

            .sidebar {
                transform: translateX(-100%);
                top: 32px;
                height: calc(100vh - 32px);
                width: min(78vw, 230px);
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="title-bar">
        <div class="title-content">
            <button type="button" class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle sidebar" title="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" style="height: 32px; width: auto; margin-right: 12px; cursor: pointer;" onclick="toggleSidebar()">
            <span style="color: #d9e441; font-weight: 800;">ASPLAN</span>
        </div>
        <div class="student-info">
            <img src="<?= $picture ?>" alt="Profile Picture">
            <span><?= $lastName . ', ' . $firstName . ($middleName !== '' ? ' ' . $middleName : '') ?> | Student</span>
        </div>
    </div>

    <div class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header">
            <h3>Student Panel</h3>
        </div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="home_page_student.php"><img src="../pix/home1.png" alt="Home"> Home</a></li>
            </div>
            <div class="menu-group">
                <div class="menu-group-title">Academic</div>
                <li><a href="checklist_stud.php"><img src="../pix/update.png" alt="Checklist"> Update Checklist</a></li>
                <li><a href="study_plan.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan</a></li>
                <li><a href="program_shift_request.php" class="active"><img src="../pix/checklist.png" alt="Program Shift"> Program Shift</a></li>
            </div>
            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="acc_mng.php"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
                <li><a href="../auth/signout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="main-content" id="mainContent">
    <div data-student-shell="<?= $studentShellPayload ?>"></div>
    <div data-student-program-shift-workspace="<?= $studentProgramShiftWorkspacePayload ?>"></div>
    <div class="container">
        <section class="hero">
            <div class="hero-card">
                <h1>Shift With Clarity</h1>
                <p>Submit your destination program and reason. Requests move through Adviser review and Program Coordinator approval. Auto-credit is applied only for strict equivalent courses.</p>
                <div class="program-chip"><strong>Current Program</strong><span><?= htmlspecialchars($currentProgram !== '' ? $currentProgram : 'Not Set') ?></span></div>
            </div>
            <div class="hero-stats">
                <div class="mini">
                    <div class="k">Total Requests</div>
                    <div class="v"><?= (int)$historyStats['all'] ?></div>
                </div>
                <div class="mini">
                    <div class="k">Pending</div>
                    <div class="v"><?= (int)$historyStats['pending'] ?></div>
                </div>
                <div class="mini">
                    <div class="k">Approved</div>
                    <div class="v"><?= (int)$historyStats['approved'] ?></div>
                </div>
                <div class="mini">
                    <div class="k">Rejected</div>
                    <div class="v"><?= (int)$historyStats['rejected'] ?></div>
                </div>
            </div>
        </section>

        <section class="layout">
        <div class="card" id="programShiftRequestCard">
            <h2>Request Program Shift</h2>
            <p class="muted">Fill in the required fields below to submit a new request.</p>

            <?php if ($success !== ''): ?>
                <div class="alert success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="submit_program_shift_request.php" class="request-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="field">
                    <label for="requested_program">Destination Program</label>
                    <select id="requested_program" name="requested_program" required>
                        <option value="">Select destination program...</option>
                        <?php foreach ($programOptions as $program): ?>
                            <?php if (strcasecmp(psNormalizeProgramLabel($program), psNormalizeProgramLabel($currentProgram)) === 0) { continue; } ?>
                            <option value="<?= htmlspecialchars($program) ?>"><?= htmlspecialchars($program) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="reason">Reason for Shift</label>
                    <textarea id="reason" name="reason" placeholder="Explain why you are requesting to shift programs..." required></textarea>
                </div>

                <div class="submit-row">
                    <button type="submit">Submit Shift Request</button>
                    <span class="muted">Ensure your reason is specific and complete.</span>
                </div>
            </form>
        </div>

        <div class="card">
            <h2 style="font-size:24px; margin-bottom:6px;">Before You Submit</h2>
            <p class="muted" style="margin-top:0;">A quick guide to avoid delays in approval.</p>
            <div class="tips">
                <div class="tip"><strong>Be explicit:</strong> mention academic, financial, or career reasons so reviewers can evaluate quickly.</div>
                <div class="tip"><strong>One active request:</strong> you cannot submit another while one is pending.</div>
                <div class="tip"><strong>Course crediting:</strong> auto-credit happens only when course code, title, and unit/hour details are strict matches.</div>
            </div>
        </div>
        </section>

        <div class="card history-card" id="programShiftHistoryCard">
            <h2>Your Request History</h2>
            <p class="muted">Track status changes and reviewer remarks for each submitted request.</p>
            <div class="status-inline">
                <span class="chip">Filtered Status<strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $historyFilter === 'all' ? 'all' : $historyFilter))) ?></strong></span>
                <span class="chip">Visible Records<strong><?= (int)count($history) ?></strong></span>
            </div>
            <form method="get" class="history-toolbar">
                <label for="status" style="font-size:13px;">Status Filter</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="all" <?= $historyFilter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="pending_adviser" <?= $historyFilter === 'pending_adviser' ? 'selected' : '' ?>>Pending Adviser</option>
                    <option value="pending_coordinator" <?= $historyFilter === 'pending_coordinator' ? 'selected' : '' ?>>Pending Coordinator</option>
                    <option value="approved" <?= $historyFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $historyFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="cancelled" <?= $historyFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </form>
            <?php if (empty($history)): ?>
                <div class="history-empty muted">No program shift requests yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Request Code</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$row['request_code']) ?></strong>
                                        <div class="row-muted">Student Request</div>
                                    </td>
                                    <td><?= htmlspecialchars((string)$row['current_program']) ?></td>
                                    <td><?= htmlspecialchars((string)$row['requested_program']) ?></td>
                                    <td><span class="status <?= htmlspecialchars((string)$row['status']) ?>\"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$row['status']))) ?></span></td>
                                    <td><?= htmlspecialchars((string)$row['requested_at']) ?></td>
                                    <td>
                                        <?php
                                            $parts = [];
                                            if (!empty($row['adviser_comment'])) { $parts[] = 'Adviser: ' . $row['adviser_comment']; }
                                            if (!empty($row['coordinator_comment'])) { $parts[] = 'Coordinator: ' . $row['coordinator_comment']; }
                                            if (!empty($row['execution_note'])) { $parts[] = $row['execution_note']; }
                                            echo htmlspecialchars(implode(' | ', $parts));
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('student-program-shift:action', function (event) {
            const action = event && event.detail ? event.detail.action : '';

            if (action === 'request') {
                const requestCard = document.getElementById('programShiftRequestCard');
                if (requestCard) {
                    requestCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } else if (action === 'history') {
                const historyCard = document.getElementById('programShiftHistoryCard');
                if (historyCard) {
                    historyCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } else if (action === 'destination') {
                const destinationField = document.getElementById('requested_program');
                if (destinationField) {
                    destinationField.focus();
                }
            }
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (!sidebar || !mainContent) {
                return;
            }
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        document.addEventListener('click', function (event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.title-content img');
            const menuBtn = document.getElementById('menuToggleBtn');
            if (!sidebar || !logo || !menuBtn) {
                return;
            }

            if (window.innerWidth <= 768 && !sidebar.contains(event.target) && !logo.contains(event.target) && !menuBtn.contains(event.target)) {
                sidebar.classList.add('collapsed');
                const mainContent = document.getElementById('mainContent');
                if (mainContent) {
                    mainContent.classList.add('expanded');
                }
            }
        });

        window.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (!sidebar || !mainContent) {
                return;
            }

            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        });

        window.addEventListener('resize', function () {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (!sidebar || !mainContent) {
                return;
            }

            if (window.innerWidth > 768) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            } else {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
        });
    </script>
</body>
</html>
