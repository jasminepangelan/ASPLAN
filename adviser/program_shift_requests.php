<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['id'])) {
    header('Location: login.php');
    exit();
}

$conn = getDBConnection();
psEnsureProgramShiftTables($conn);
$adviserId = $_SESSION['id'] ?? null;
$adviserUsername = (string)($_SESSION['username'] ?? '');
$adviserName = (string)($_SESSION['full_name'] ?? $adviserUsername);
$adviserDisplayName = htmlspecialchars($adviserName !== '' ? $adviserName : $adviserUsername);
$programKeys = psResolveAdviserProgramKeys($conn, $adviserId, $adviserUsername);
$adviserBatches = psResolveAdviserBatches($conn, $adviserId, $adviserUsername);
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } elseif ($useLaravelBridge) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/program-shift/adviser/decision',
            [
                'bridge_authorized' => true,
                'request_id' => $requestId,
                'action' => $action,
                'comment' => $comment,
                'adviser_id' => (int) $adviserId,
                'adviser_username' => $adviserUsername,
                'adviser_name' => $adviserName,
            ]
        );

        if (is_array($bridgeData)) {
            if (!empty($bridgeData['success'])) {
                $message = (string) ($bridgeData['message'] ?? 'Request processed successfully.');
            } else {
                $error = (string) ($bridgeData['message'] ?? 'Unable to process request.');
            }
        } else {
            $result = psHandleAdviserDecision($conn, $requestId, $action, $adviserUsername, $adviserName, $programKeys, $adviserBatches, $comment);
            if (!empty($result['ok'])) {
                $message = (string)$result['message'];
            } else {
                $error = (string)($result['message'] ?? 'Unable to process request.');
            }
        }
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));

        $result = psHandleAdviserDecision($conn, $requestId, $action, $adviserUsername, $adviserName, $programKeys, $adviserBatches, $comment);
        if (!empty($result['ok'])) {
            $message = (string)$result['message'];
        } else {
            $error = (string)($result['message'] ?? 'Unable to process request.');
        }
    }
}

$queue = psFetchAdviserQueue($conn, $programKeys, $adviserBatches);
$recentLogs = psFetchAdviserActionLog($conn, $adviserUsername, $programKeys, $adviserBatches, 10);
$bridgeData = null;
if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/program-shift/adviser/queue',
        [
            'bridge_authorized' => true,
            'adviser_id' => (int) $adviserId,
            'adviser_username' => $adviserUsername,
            'adviser_name' => $adviserName,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        if (isset($bridgeData['queue']) && is_array($bridgeData['queue'])) {
            $queue = $bridgeData['queue'];
        }
        if (isset($bridgeData['recent_logs']) && is_array($bridgeData['recent_logs'])) {
            $recentLogs = $bridgeData['recent_logs'];
        }
    }
}
$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
    $needle = strtolower($search);
    $queue = array_values(array_filter($queue, static function ($row) use ($needle) {
        $haystack = strtolower(implode(' ', [
            (string)($row['request_code'] ?? ''),
            (string)($row['student_number'] ?? ''),
            (string)($row['student_name'] ?? ''),
            (string)($row['current_program'] ?? ''),
            (string)($row['requested_program'] ?? ''),
        ]));
        return strpos($haystack, $needle) !== false;
    }));
}
$sort = (string)($_GET['sort'] ?? 'requested_at');
$dir = strtolower((string)($_GET['dir'] ?? 'desc'));
$allowedSorts = ['request_code', 'student_name', 'current_program', 'requested_program', 'requested_at'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'requested_at';
}
if ($dir !== 'asc' && $dir !== 'desc') {
    $dir = 'desc';
}
usort($queue, static function ($a, $b) use ($sort, $dir) {
    if ($sort === 'requested_at') {
        $left = strtotime((string)($a[$sort] ?? '')) ?: 0;
        $right = strtotime((string)($b[$sort] ?? '')) ?: 0;
    } else {
        $left = strtolower((string)($a[$sort] ?? ''));
        $right = strtolower((string)($b[$sort] ?? ''));
    }
    if ($left === $right) {
        return 0;
    }
    if ($dir === 'asc') {
        return ($left < $right) ? -1 : 1;
    }
    return ($left > $right) ? -1 : 1;
});

$perPage = (int)($_GET['per_page'] ?? 10);
$allowedPerPage = [10, 25, 50];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}
$totalRows = count($queue);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) {
    $page = 1;
} elseif ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$queue = array_slice($queue, $offset, $perPage);
$queueCount = $totalRows;
$csrfToken = getCSRFToken();
closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Shift Requests - Adviser</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: linear-gradient(135deg, #eef4ef 0%, #dfe9f1 100%); color: #1f2937; overflow-x: hidden; }

        .title-bar {
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: #fff;
            padding: 5px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(32, 96, 24, 0.3);
        }

        .title-content {
            display: flex;
            align-items: center;
        }

        .title-bar img {
            height: 30px;
            width: auto;
            margin-right: 15px;
            vertical-align: middle;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
        }

        .title-bar span {
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .adviser-name {
            font-size: 16px;
            font-weight: 600;
            color: #facc41;
            font-family: 'Segoe UI', Arial, sans-serif;
            letter-spacing: 0.5px;
            background: rgba(250, 204, 65, 0.15);
            padding: 8px 16px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid rgba(250, 204, 65, 0.3);
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

        .sidebar {
            width: 250px;
            height: calc(100vh - 32px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 32px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 999;
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 10px;
        }

        .sidebar-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li { margin: 0; }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover {
            background-color: rgba(255,255,255,0.1);
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background-color: rgba(255,255,255,0.15);
            border-left-color: #4CAF50;
        }

        .sidebar-menu img {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            filter: invert(1);
        }

        .menu-group { margin-bottom: 20px; }

        .menu-group-title {
            padding: 10px 20px 5px;
            font-size: 12px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            font-weight: 600;
            letter-spacing: 1px;
        }

        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 46px);
            width: calc(100vw - 250px);
            overflow-x: hidden;
            transition: margin-left 0.3s ease, width 0.3s ease;
            padding-top: 45px;
            background:
                radial-gradient(circle at top left, rgba(26, 79, 22, 0.08), transparent 26%),
                radial-gradient(circle at top right, rgba(46, 125, 50, 0.07), transparent 30%),
                linear-gradient(180deg, #f8fbf8 0%, #eef3ef 100%);
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100vw;
        }

        .content {
            padding: 24px 26px 30px;
            max-width: 100%;
        }

        .page-header {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(255,255,255,0.94) 0%, rgba(248,252,248,0.98) 100%);
            padding: 20px 26px 20px 32px;
            margin: 0 0 16px 0;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 20px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.08);
        }

        .page-header::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 6px;
            background: linear-gradient(180deg, #1f7a2f 0%, #2e7d32 55%, #4CAF50 100%);
        }

        .page-header h1 {
            position: relative;
            margin: 0;
            color: #18351f;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 0.2px;
        }

        .page-header h1::after {
            content: 'Program shift queue and actions';
            display: block;
            margin-top: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #60707a;
            letter-spacing: 0;
        }

        .wrap { max-width: 1320px; margin: 0 auto; padding: 0; }
        .panel {
            position: relative;
            background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, rgba(249,252,249,0.98) 100%);
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: 0 18px 46px rgba(15, 23, 42, 0.10);
            padding: 22px;
            overflow: hidden;
        }
        .panel::after {
            content: '';
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, #1f7a2f 0%, #4CAF50 55%, #d9e441 100%);
        }
        .alert { margin: 0 0 12px; border-radius: 12px; padding: 12px 14px; font-weight: 700; border: 1px solid transparent; }
        .ok { background: linear-gradient(180deg, #f0fdf4 0%, #ecfdf3 100%); color: #166534; border-color: #86efac; }
        .err { background: linear-gradient(180deg, #fff1f2 0%, #fef2f2 100%); color: #b91c1c; border-color: #fca5a5; }
        .stats { display: flex; gap: 10px; margin: 0 0 14px; flex-wrap: wrap; }
        .pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 7px 12px; font-size: 12px; font-weight: 800; letter-spacing: 0.02em; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05); }
        .pill.pending { background: linear-gradient(180deg, #fff8e8 0%, #fef3c7 100%); color: #9a3412; border: 1px solid #f6d69b; }
        .pill.filtered { background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%); color: #334155; border: 1px solid #dbe4ee; }
        .toolbar { display: flex; gap: 10px; margin: 0 0 12px; flex-wrap: wrap; align-items: center; background: rgba(255,255,255,0.72); border: 1px solid rgba(148, 163, 184, 0.18); border-radius: 14px; padding: 12px; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04); }
        .toolbar input { flex: 1; min-width: 220px; border: 1px solid #cfd7e3; border-radius: 12px; padding: 11px 14px; font-size: 13px; background: #fff; }
        .toolbar input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .toolbar button, .toolbar a { border: 0; border-radius: 10px; padding: 10px 14px; font-size: 12px; font-weight: 800; text-decoration: none; transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease; }
        .toolbar button { background: linear-gradient(135deg, #1f7a2f 0%, #2e7d32 100%); color: #fff; cursor: pointer; box-shadow: 0 8px 18px rgba(31, 122, 47, 0.18); }
        .toolbar a { background: #e5e7eb; color: #1f2937; }
        .toolbar button:hover, .toolbar a:hover, .btn:hover { transform: translateY(-1px); }
        .th-sort { color: #111827; text-decoration: none; }
        .pager { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; gap: 8px; flex-wrap: wrap; color: #475569; font-size: 13px; }
        .pager.top { margin-top: 0; margin-bottom: 12px; }
        .pager .links { display: flex; gap: 8px; }
        .pager a, .pager span { border-radius: 10px; padding: 7px 11px; font-size: 12px; font-weight: 800; text-decoration: none; }
        .pager a { background: #f1f5f9; color: #334155; border: 1px solid #d7e0ea; }
        .pager a:hover { background: #e7edf4; }
        .pager .disabled { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
        .pager .current { background: linear-gradient(135deg, #14532d 0%, #1f7a2f 100%); color: #fff; box-shadow: 0 6px 14px rgba(20, 83, 45, 0.18); }
        .per-page { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #374151; }
        .per-page select { border: 1px solid #cfd7e3; border-radius: 10px; padding: 6px 9px; font-size: 12px; background: #fff; }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: #fff;
            border: 1px solid #dfe7ef;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        th, td { border-bottom: 1px solid #eef2f7; padding: 14px 12px; vertical-align: top; font-size: 13px; }
        th { background: linear-gradient(180deg, #f8fafc 0%, #edf3f8 100%); font-size: 12px; text-transform: uppercase; color: #0f172a; letter-spacing: 0.04em; font-weight: 800; }
        tbody tr:hover { background: #f9fbff; }
        tbody tr:last-child td { border-bottom: 0; }
        textarea { width: 100%; min-height: 78px; border: 1px solid #cfd7e3; border-radius: 12px; padding: 10px; font-family: inherit; font-size: 13px; background: #fff; resize: vertical; }
        textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }
        .actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .btn { border: 0; border-radius: 10px; padding: 9px 14px; font-size: 12px; font-weight: 800; cursor: pointer; box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08); }
        .btn.approve { background: linear-gradient(135deg, #15803d 0%, #22c55e 100%); color: #fff; }
        .btn.reject { background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%); color: #fff; }

        .history-panel { margin: 0 0 16px; border: 1px solid #dbe4ee; border-radius: 14px; background: linear-gradient(180deg, #f8fbff 0%, #f4f8fc 100%); overflow: hidden; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05); }

        .history-head { padding: 12px 14px; font-size: 13px; font-weight: 800; color: #0f172a; background: linear-gradient(180deg, #eef5ff 0%, #e8f1ff 100%); border-bottom: 1px solid #dbe4ee; }

        .history-table {
            width: 100%;
            border-collapse: collapse;
        }

        .history-table th,
        .history-table td {
            border-bottom: 1px solid #e6edf5;
            padding: 9px 10px;
            font-size: 12px;
            vertical-align: top;
            text-align: left;
        }

        .history-table th { background: #f8fbff; color: #334155; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; }

        .history-table tr:last-child td {
            border-bottom: 0;
        }

        .badge { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }

        .badge.approve {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .badge.reject {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .status-chip { display: inline-block; border-radius: 999px; padding: 3px 8px; font-size: 11px; font-weight: 700; background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }

        .empty-state {
            padding: 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                width: 100vw;
                padding-top: 30px;
            }

            .content {
                padding: 12px;
            }

            .panel {
                padding: 14px;
                border-radius: 16px;
            }

            .page-header {
                padding: 15px 18px 15px 26px;
                margin: 0 0 14px 0;
                border-radius: 16px;
            }

            .page-header h1 {
                font-size: 20px;
                left: 0;
                top: 0;
            }

            .adviser-name {
                font-size: 10px;
                padding: 3px 6px;
            }

            .title-bar {
                font-size: 12px;
                padding: 5px 8px;
            }

            .title-bar img {
                height: 22px;
                margin-right: 6px;
            }
        }
    </style>
</head>
<body>
    <div class="title-bar">
        <div class="title-content">
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" style="cursor: pointer;" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="adviser-name"><?= $adviserDisplayName ?> | Adviser</div>
    </div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Adviser Panel</h3>
        </div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Student Management</div>
                <li><a href="pending_accounts.php"><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
                <li><a href="checklist_eval.php"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
                <li><a href="study_plan_list.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
                <li><a href="program_shift_requests.php" class="active"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="main-content" id="mainContent">
        <div class="page-header">
            <h1>Adviser Program Shift Queue</h1>
        </div>
        <div class="content">
            <div class="wrap">
                <div class="panel">
            <?php if ($message !== ''): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="stats">
                <span class="pill pending">Pending Adviser Queue: <?= (int)$queueCount ?></span>
                <?php if ($search !== ''): ?><span class="pill filtered">Filtered by: <?= htmlspecialchars($search) ?></span><?php endif; ?>
                <span class="pill filtered">Sort: <?= htmlspecialchars($sort) ?> (<?= htmlspecialchars(strtoupper($dir)) ?>)</span>
            </div>

            <div class="history-panel">
                <div class="history-head">Recent Adviser Actions (Saved Log)</div>
                <?php if (empty($recentLogs)): ?>
                    <div class="empty-state">No adviser action logs yet.</div>
                <?php else: ?>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Request</th>
                                <th>Student</th>
                                <th>Action</th>
                                <th>Current Status</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)($log['request_code'] ?? '')) ?></strong><br>
                                    <small><?= htmlspecialchars((string)($log['current_program'] ?? '')) ?> -> <?= htmlspecialchars((string)($log['requested_program'] ?? '')) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($log['student_name'] ?? '')) ?><br>
                                    <small><?= htmlspecialchars((string)($log['student_number'] ?? '')) ?></small>
                                </td>
                                <td>
                                    <span class="badge <?= htmlspecialchars((string)($log['action'] ?? '')) ?>">
                                        <?= htmlspecialchars(strtoupper((string)($log['action'] ?? ''))) ?>
                                    </span>
                                    <?php if (trim((string)($log['comments'] ?? '')) !== ''): ?>
                                        <br><small><?= htmlspecialchars((string)$log['comments']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="status-chip"><?= htmlspecialchars((string)($log['status'] ?? '')) ?></span></td>
                                <td><?= htmlspecialchars((string)($log['action_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <form method="get" class="toolbar">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search request code, student, or program">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                <button type="submit">Filter</button>
                <?php if ($search !== '' || $sort !== 'requested_at' || $dir !== 'desc'): ?><a href="program_shift_requests.php">Reset</a><?php endif; ?>
            </form>

            <form method="get" class="toolbar" style="margin-top: -4px;">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                <input type="hidden" name="page" value="1">
                <label class="per-page">Rows per page
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach ([10, 25, 50] as $size): ?>
                            <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <?php if (empty($queue)): ?>
                <div class="empty-state">No pending shift requests for your assigned program.</div>
            <?php else: ?>
                <?php
                    $baseParams = ['q' => $search, 'per_page' => $perPage];
                    $sortUrl = static function (string $column) use ($sort, $dir, $baseParams) {
                        $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
                        $params = array_merge($baseParams, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
                        return 'program_shift_requests.php?' . http_build_query($params);
                    };
                    $pageUrl = static function (int $targetPage) use ($search, $sort, $dir, $perPage) {
                        return 'program_shift_requests.php?' . http_build_query([
                            'q' => $search,
                            'sort' => $sort,
                            'dir' => $dir,
                            'per_page' => $perPage,
                            'page' => $targetPage,
                        ]);
                    };
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                ?>
                <div class="pager top">
                    <div>Page <?= (int)$page ?> of <?= (int)$totalPages ?> (<?= (int)$queueCount ?> total)</div>
                    <div class="links">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl(1)) ?>">First</a>
                        <?php else: ?>
                            <span class="disabled">First</span>
                        <?php endif; ?>
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>">Previous</a>
                        <?php else: ?>
                            <span class="disabled">Previous</span>
                        <?php endif; ?>
                        <?php if ($startPage > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl(1)) ?>">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="disabled">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= (int)$p ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($pageUrl($p)) ?>"><?= (int)$p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < ($totalPages - 1)): ?>
                                <span class="disabled">...</span>
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars($pageUrl($totalPages)) ?>"><?= (int)$totalPages ?></a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>">Next</a>
                        <?php else: ?>
                            <span class="disabled">Next</span>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($pageUrl($totalPages)) ?>">Last</a>
                        <?php else: ?>
                            <span class="disabled">Last</span>
                        <?php endif; ?>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><a class="th-sort" href="<?= htmlspecialchars($sortUrl('request_code')) ?>">Request</a></th>
                            <th><a class="th-sort" href="<?= htmlspecialchars($sortUrl('student_name')) ?>">Student</a></th>
                            <th><a class="th-sort" href="<?= htmlspecialchars($sortUrl('current_program')) ?>">From</a></th>
                            <th><a class="th-sort" href="<?= htmlspecialchars($sortUrl('requested_program')) ?>">To</a></th>
                            <th>Reason</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queue as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['request_code']) ?></strong><br>
                                    <small><?= htmlspecialchars((string)$row['requested_at']) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)$row['student_name']) ?><br>
                                    <small><?= htmlspecialchars((string)$row['student_number']) ?></small>
                                </td>
                                <td><?= htmlspecialchars((string)$row['current_program']) ?></td>
                                <td><?= htmlspecialchars((string)$row['requested_program']) ?></td>
                                <td><?= nl2br(htmlspecialchars((string)$row['reason'])) ?></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                                        <textarea name="comment" placeholder="Optional adviser remarks"></textarea>
                                        <div class="actions">
                                            <button class="btn approve" type="submit" name="action" value="approve">Approve</button>
                                            <button class="btn reject" type="submit" name="action" value="reject">Reject</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="pager">
                    <div>Page <?= (int)$page ?> of <?= (int)$totalPages ?> (<?= (int)$queueCount ?> total)</div>
                    <div class="links">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl(1)) ?>">First</a>
                        <?php else: ?>
                            <span class="disabled">First</span>
                        <?php endif; ?>
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>">Previous</a>
                        <?php else: ?>
                            <span class="disabled">Previous</span>
                        <?php endif; ?>
                        <?php if ($startPage > 1): ?>
                            <a href="<?= htmlspecialchars($pageUrl(1)) ?>">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="disabled">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= (int)$p ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($pageUrl($p)) ?>"><?= (int)$p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < ($totalPages - 1)): ?>
                                <span class="disabled">...</span>
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars($pageUrl($totalPages)) ?>"><?= (int)$totalPages ?></a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>">Next</a>
                        <?php else: ?>
                            <span class="disabled">Next</span>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= htmlspecialchars($pageUrl($totalPages)) ?>">Last</a>
                        <?php else: ?>
                            <span class="disabled">Last</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            if (!sidebar || !mainContent) {
                return;
            }

            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.title-bar img');

            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target))) {
                sidebar.classList.add('collapsed');
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.classList.add('expanded');
                }
            }
        });

        // Initialize sidebar state on page load
        window.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
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

        // Handle responsive behavior
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
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
