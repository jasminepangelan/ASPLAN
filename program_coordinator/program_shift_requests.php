<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$conn = getDBConnection();
psEnsureProgramShiftTables($conn);
$username = (string)($_SESSION['username'] ?? '');
$fullName = (string)($_SESSION['full_name'] ?? $username);
$coordinatorName = htmlspecialchars($fullName !== '' ? $fullName : $username);
$programKeys = psResolveCoordinatorProgramKeys($conn, $username);
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
            'http://localhost/ASPLAN_v10/laravel-app/public/api/program-shift/coordinator/decision',
            [
                'bridge_authorized' => true,
                'request_id' => $requestId,
                'action' => $action,
                'comment' => $comment,
                'username' => $username,
                'full_name' => $fullName,
            ]
        );

        if (is_array($bridgeData)) {
            if (!empty($bridgeData['success'])) {
                $message = (string) ($bridgeData['message'] ?? 'Request processed successfully.');
            } else {
                $error = (string) ($bridgeData['message'] ?? 'Unable to process request.');
            }
        } else {
            $result = psHandleCoordinatorDecision($conn, $requestId, $action, $username, $fullName, $programKeys, $comment);

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
        $result = psHandleCoordinatorDecision($conn, $requestId, $action, $username, $fullName, $programKeys, $comment);

        if (!empty($result['ok'])) {
            $message = (string)$result['message'];
        } else {
            $error = (string)($result['message'] ?? 'Unable to process request.');
        }
    }
}

$queue = psFetchCoordinatorQueue($conn, $programKeys);
$bridgeData = null;
if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/program-shift/coordinator/queue',
        [
            'bridge_authorized' => true,
            'username' => $username,
            'full_name' => $fullName,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['queue']) && is_array($bridgeData['queue'])) {
        $queue = $bridgeData['queue'];
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
            (string)($row['adviser_comment'] ?? ''),
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
    <title>Program Shift Requests - Program Coordinator</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); color: #1f2937; overflow-x: hidden; }

        .header {
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: #fff;
            padding: 5px 15px;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
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
            border-radius: 6px;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .menu-toggle:hover { background: rgba(255, 255, 255, 0.22); }
        .header img { height: 32px; width: auto; cursor: pointer; }
        .header .brand { color: #d9e441; font-weight: 800; }

        .admin-info {
            font-size: 16px;
            font-weight: 600;
            color: white;
            font-family: 'Segoe UI', Arial, sans-serif;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.15);
            padding: 5px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .sidebar {
            width: 250px;
            height: calc(100vh - 45px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 45px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 999;
        }

        .sidebar.collapsed { transform: translateX(-250px); }

        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            color: white;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
        }

        .sidebar-header h3 { margin: 0; }
        .sidebar-menu { list-style: none; padding: 6px 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #ffffff;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 15px;
            line-height: 1.2;
            border-left: 4px solid transparent;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 25px;
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: #4CAF50;
        }

        .sidebar-menu img {
            width: 20px;
            height: 20px;
            filter: brightness(0) invert(1);
            flex: 0 0 20px;
        }

        .menu-group { margin: 8px 0; }
        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1.2;
        }

        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 45px);
            background-color: #f5f5f5;
            width: calc(100vw - 250px);
            transition: margin-left 0.3s ease, width 0.3s ease;
            margin-top: 45px;
        }

        .main-content.expanded { margin-left: 0; width: 100vw; }

        .content {
            padding: 30px;
        }

        .page-header {
            background: white;
            padding: 20px 30px;
            margin: -30px -30px 30px -30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .page-header h1 {
            position: relative;
            top: 10px;
            left: 10px;
            color: #333;
            font-size: 24px;
            font-weight: 600;
        }

        .wrap { max-width: 1100px; margin: 0 auto; padding: 0 14px 24px; }
        .panel { background: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); padding: 18px; }
        .alert { margin: 0 0 12px; border-radius: 8px; padding: 10px 12px; font-weight: 700; }
        .ok { background: #ecfdf3; color: #166534; border: 1px solid #86efac; }
        .err { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 10px 8px; vertical-align: top; font-size: 13px; }
        th { background: #f9fafb; font-size: 12px; text-transform: uppercase; }
        .stats { display: flex; gap: 8px; margin: 0 0 12px; flex-wrap: wrap; }
        .pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 5px 10px; font-size: 12px; font-weight: 700; }
        .pill.pending { background: #e8f4ff; color: #0b4f85; border: 1px solid #b8d8f2; }
        .pill.filtered { background: #eef2ff; color: #334155; border: 1px solid #c7d2fe; }
        .toolbar { display: flex; gap: 8px; margin: 0 0 12px; flex-wrap: wrap; }
        .toolbar input { flex: 1; min-width: 220px; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px 10px; font-size: 13px; }
        .toolbar button, .toolbar a { border: 0; border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 700; text-decoration: none; }
        .toolbar button { background: #1f7a2f; color: #fff; cursor: pointer; }
        .toolbar a { background: #e5e7eb; color: #1f2937; }
        .th-sort { color: #111827; text-decoration: none; }
        .pager { display: flex; justify-content: center; align-items: center; margin-top: 25px; gap: 8px; flex-wrap: wrap; color: #666; font-size: 13px; }
        .pager.top { margin-top: 0; margin-bottom: 16px; }
        .pager .links { display: flex; gap: 8px; flex-wrap: wrap; }
        .pager a, .pager span { border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; text-decoration: none; min-width: 40px; text-align: center; }
        .pager a { background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%); color: #206018; border: 2px solid #e0e0e0; transition: all 0.3s ease; }
        .pager a:hover { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: #fff; border-color: #206018; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3); }
        .pager .disabled { background: #f0f0f0; color: #ccc; border: 2px solid #e0e0e0; cursor: not-allowed; opacity: 0.6; }
        .pager .current { background: linear-gradient(135deg, #206018 0%, #4CAF50 100%); color: #fff; border: 2px solid #206018; box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3); }
        .per-page { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: #374151; }
        .per-page select { border: 1px solid #d1d5db; border-radius: 8px; padding: 5px 8px; font-size: 12px; }
        textarea { width: 100%; min-height: 70px; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px; font-family: inherit; font-size: 13px; }
        .actions { display: flex; gap: 6px; margin-top: 8px; }
        .btn { border: 0; border-radius: 8px; padding: 8px 12px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn.approve { background: #15803d; color: #fff; }
        .btn.reject { background: #b91c1c; color: #fff; }

        @media (max-width: 768px) {
            .header { padding: 5px 8px; }
            .header img { height: 22px; }
            .admin-info { font-size: 10px; padding: 3px 8px; }
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100vw; }
            .content { padding: 18px 12px; }
            .page-header { margin: -18px -12px 18px -12px; padding: 14px 16px; }
            .page-header h1 { font-size: 20px; top: 0; left: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span class="brand">ASPLAN</span>
        </div>
        <div class="admin-info"><?= $coordinatorName ?> | Program Coordinator</div>
    </div>

    <div class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header">
            <h3>Program Coordinator Panel</h3>
        </div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Modules</div>
                <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum"> Curriculum Management</a></li>
                <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers"> Adviser Management</a></li>
                <li><a href="list_of_students.php"><img src="../pix/checklist.png" alt="Students"> List of Students</a></li>
                <li><a href="program_shift_requests.php" class="active"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>
                <li><a href="profile.php"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="main-content expanded" id="mainContent">
        <div class="content">
            <div class="page-header">
                <h1>Program Coordinator Shift Queue</h1>
            </div>
            <div class="wrap">
                <div class="panel">
            <?php if ($message !== ''): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="stats">
                <span class="pill pending">Pending Coordinator Queue: <?= (int)$queueCount ?></span>
                <?php if ($search !== ''): ?><span class="pill filtered">Filtered by: <?= htmlspecialchars($search) ?></span><?php endif; ?>
                <span class="pill filtered">Sort: <?= htmlspecialchars($sort) ?> (<?= htmlspecialchars(strtoupper($dir)) ?>)</span>
            </div>

            <form method="get" class="toolbar">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search request code, student, program, adviser note">
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
                <p>No pending adviser-approved shift requests.</p>
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
                            <th>Adviser Notes</th>
                            <th>Coordinator Action</th>
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
                                <td>
                                    <?= nl2br(htmlspecialchars((string)$row['adviser_comment'])) ?>
                                </td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int)$row['id'] ?>">
                                        <textarea name="comment" placeholder="Optional coordinator remarks"></textarea>
                                        <div class="actions">
                                            <button class="btn approve" type="submit" name="action" value="approve">Approve + Execute</button>
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
            const mainContent = document.getElementById('mainContent');
            if (!sidebar || !mainContent) {
                return;
            }

            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.header img');
            const toggle = document.querySelector('.menu-toggle');
            if (!sidebar) {
                return;
            }

            if (window.innerWidth <= 768 && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target)) && (!toggle || !toggle.contains(event.target))) {
                sidebar.classList.add('collapsed');
                const mainContent = document.getElementById('mainContent');
                if (mainContent) {
                    mainContent.classList.add('expanded');
                }
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
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

        window.addEventListener('resize', function() {
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
