<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/accounts_view_service.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$csrfToken = getCSRFToken();

$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'students';
$allowedTypes = ['students', 'advisers', 'program_coordinators', 'admins'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'students';
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_action'])) {
    $postedType = isset($_POST['type']) ? strtolower(trim((string)$_POST['type'])) : 'students';
    $postedSearch = isset($_POST['search']) ? trim((string)$_POST['search']) : '';
    $postedPage = isset($_POST['page']) && is_numeric($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
    $redirectParams = 'type=' . urlencode($postedType) . '&page=' . $postedPage;
    if ($postedSearch !== '') {
        $redirectParams .= '&search=' . urlencode($postedSearch);
    }

    if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Invalid security token. Please refresh and try again.'));
        exit();
    }

    if ($postedType !== 'students') {
        header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Actions are only available for student accounts.'));
        exit();
    }

    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $statusAction = trim((string)($_POST['status_action'] ?? ''));
    $statusMap = [
        'archive' => ['status' => 'rejected', 'message' => 'Account archived successfully.'],
        'pending' => ['status' => 'pending', 'message' => 'Account moved to pending successfully.'],
        'approve' => ['status' => 'approved', 'message' => 'Account approved successfully.'],
    ];

    if ($studentId === '' || !isset($statusMap[$statusAction])) {
        header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Invalid account action request.'));
        exit();
    }

    if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $studentId)) {
        header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Invalid student ID format.'));
        exit();
    }

    $actionConn = getDBConnection();
    $freezeApprovalsEnabled = false;
    $freezeResult = $actionConn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'freeze_approvals' ORDER BY id DESC LIMIT 1");
    if ($freezeResult && $freezeRow = $freezeResult->fetch_assoc()) {
        $freezeApprovalsEnabled = ((int)($freezeRow['setting_value'] ?? 0) === 1);
    }

    if ($freezeApprovalsEnabled) {
        closeDBConnection($actionConn);
        header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Approval actions are currently frozen by admin settings.'));
        exit();
    }

    $targetStatus = $statusMap[$statusAction]['status'];
    $approvedBy = trim((string)($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? ''));

    if ($targetStatus === 'pending') {
        $stmt = $actionConn->prepare("UPDATE student_info SET status = ?, approved_by = NULL WHERE student_number = ?");
        if (!$stmt) {
            closeDBConnection($actionConn);
            header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Failed to prepare the account update.'));
            exit();
        }

        $stmt->bind_param('ss', $targetStatus, $studentId);
    } else {
        $stmt = $actionConn->prepare("UPDATE student_info SET status = ?, approved_by = ? WHERE student_number = ?");
        if (!$stmt) {
            closeDBConnection($actionConn);
            header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('Failed to prepare the account update.'));
            exit();
        }

        $stmt->bind_param('sss', $targetStatus, $approvedBy, $studentId);
    }

    $executed = $stmt->execute();
    $updated = $executed && $stmt->affected_rows > 0;
    $stmt->close();
    closeDBConnection($actionConn);

    if ($updated) {
        header('Location: accounts_view.php?' . $redirectParams . '&message=' . urlencode($statusMap[$statusAction]['message']));
    } else {
        header('Location: accounts_view.php?' . $redirectParams . '&error=' . urlencode('No account was updated. Please verify the selected student.'));
    }
    exit();
}

$offset = ($current_page - 1) * $records_per_page;

$title = 'Student Accounts';
$columns = [];
$rows = [];
$total_records = 0;
$total_pages = 1;
$feedbackMessage = isset($_GET['message']) ? trim((string)$_GET['message']) : '';
$feedbackError = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$freezeApprovalsEnabled = false;

$settingsConn = getDBConnection();
$freezeResult = $settingsConn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'freeze_approvals' ORDER BY id DESC LIMIT 1");
if ($freezeResult && $freezeRow = $freezeResult->fetch_assoc()) {
    $freezeApprovalsEnabled = ((int)($freezeRow['setting_value'] ?? 0) === 1);
}
closeDBConnection($settingsConn);

$bridgeLoaded = false;
if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        '/api/accounts-view/overview',
        [
            'bridge_authorized' => true,
            'type' => $type,
            'search' => $search,
            'page' => $current_page,
            'records_per_page' => $records_per_page,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $type = (string) ($bridgeData['type'] ?? $type);
        $title = (string) ($bridgeData['title'] ?? $title);
        $columns = isset($bridgeData['columns']) && is_array($bridgeData['columns']) ? $bridgeData['columns'] : [];
        $rows = isset($bridgeData['rows']) && is_array($bridgeData['rows']) ? $bridgeData['rows'] : [];
        $total_records = (int) ($bridgeData['total_records'] ?? 0);
        $total_pages = max(1, (int) ($bridgeData['total_pages'] ?? 1));
        $current_page = max(1, (int) ($bridgeData['current_page'] ?? $current_page));
        $search = (string) ($bridgeData['search'] ?? $search);
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    if ($type === 'students') {
        $title = 'Student Accounts';
        [$columns, $rows, $total_records, $total_pages] = avsLoadStudentAccounts($search, $offset, $records_per_page);
    } elseif ($type === 'advisers') {
        $title = 'Adviser Accounts';
        [$columns, $rows, $total_records, $total_pages] = avsLoadAdviserAccounts($search, $offset, $records_per_page);
    } elseif ($type === 'program_coordinators') {
        $title = 'Program Coordinator Accounts';
        [$columns, $rows, $total_records, $total_pages] = avsLoadProgramCoordinatorAccounts($search, $offset, $records_per_page);
    } else {
        $title = 'Admin Accounts';
        [$columns, $rows, $total_records, $total_pages] = avsLoadAdminAccounts($search, $offset, $records_per_page);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Accounts</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: url('pix/school.jpg') no-repeat center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            overflow-x: hidden;
            padding-top: 45px;
        }

        .main-header {
            width: 100%;
            background: linear-gradient(135deg, #206018 0%, #2d7a2d 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 15px;
            height: 45px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .main-header > div:first-child { display: flex; align-items: center; }
        .main-header img { height: 32px; margin-right: 10px; cursor: pointer; }
        .main-header span { font-size: 1.2rem; font-weight: 800; letter-spacing: 0.6px; }

        .admin-info {
            font-size: 16px;
            font-weight: 600;
            color: white;
            background: rgba(255, 255, 255, 0.15);
            padding: 5px 15px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
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
            margin-right: 10px;
            transition: all 0.2s ease;
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
        .sidebar-header { padding: 15px 20px; text-align: center; color: white; font-size: 20px; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,.2); margin-bottom: 5px; }
        .sidebar-menu { list-style: none; padding: 6px 0; margin: 0; }
        .sidebar-menu li { margin: 0; }
        .sidebar-menu a { display: flex; align-items: center; gap: 12px; padding: 10px 20px; color: #fff; text-decoration: none; font-size: 15px; }
        .sidebar-menu a:hover { background: rgba(255,255,255,.1); padding-left: 25px; }
        .sidebar-menu a.active { background: rgba(255,255,255,.15); border-left: 4px solid #4CAF50; }
        .sidebar-menu img { width: 20px; height: 20px; filter: brightness(0) invert(1); }
        .menu-group { margin: 8px 0; }
        .menu-group-title { padding: 6px 20px 2px 20px; color: rgba(255,255,255,.7); font-size: 15px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }

        .container {
            width: calc(100% - 315px);
            max-width: 1480px;
            margin: 25px 25px 30px 270px;
            background: rgba(255,255,255,.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            padding: 22px;
            transition: all .3s ease;
        }
        .container.expanded {
            width: calc(100% - 50px);
            margin-left: 25px;
            margin-right: 25px;
        }

        .title { color: #206018; font-size: 1.8rem; font-weight: 700; margin-bottom: 8px; }
        .subtitle { color: #666; margin-bottom: 16px; }

        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .tab {
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #dfe3e8;
            background: #f8fafc;
            color: #344054;
            font-weight: 600;
            font-size: 13px;
        }
        .tab.active { background: #206018; color: #fff; border-color: #206018; }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .toolbar form { display: flex; gap: 8px; }
        .search-input { padding: 8px 12px; border: 1px solid #d0d5dd; border-radius: 8px; min-width: 280px; }
        .btn {
            text-decoration: none;
            border: none;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }
        .btn-search { background: #206018; color: #fff; }
        .btn-clear { background: #6b7280; color: #fff; }

        .stats { color: #475467; font-size: 13px; }

        .feedback {
            margin-bottom: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
        }
        .feedback.success {
            background: #ecfdf3;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .feedback.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .table-wrap { overflow: auto; border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; }
        table { width: 100%; min-width: 1380px; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #edf0f2; text-align: left; }
        th { background: linear-gradient(135deg, #206018 0%, #2d7a2d 100%); color: #fff; position: sticky; top: 0; z-index: 1; font-size: 12px; text-transform: uppercase; }
        tr:nth-child(even) { background: #fafafa; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }
        .status-pill.pending {
            background: #fff7ed;
            color: #b45309;
            border: 1px solid #fdba74;
        }
        .status-pill.approved {
            background: #ecfdf3;
            color: #166534;
            border: 1px solid #86efac;
        }
        .status-pill.rejected {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .action-form {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            align-items: center;
            white-space: nowrap;
        }

        .btn-action {
            border: 1px solid #cfdacb;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            color: #264b22;
            white-space: nowrap;
            background: #f6faf5;
            box-shadow: 0 1px 2px rgba(32, 96, 24, 0.08);
        }
        .btn-action.approve {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            border-color: #206018;
            color: #fff;
        }
        .btn-action.pending {
            background: #eef5ec;
            border-color: #c7d8c1;
            color: #2a5825;
        }
        .btn-action.archive {
            background: #e7efe5;
            border-color: #bccdb7;
            color: #355332;
        }
        .btn-action:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .actions-col,
        .actions-cell {
            min-width: 260px;
        }

        .empty-state { text-align: center; padding: 30px; color: #667085; }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .pagination-controls { display: flex; gap: 6px; flex-wrap: wrap; }
        .page-btn {
            text-decoration: none;
            padding: 7px 10px;
            border: 1px solid #d0d5dd;
            border-radius: 6px;
            font-size: 12px;
            color: #344054;
            background: #fff;
        }
        .page-btn.active { background: #206018; color: #fff; border-color: #206018; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-250px); }
            .sidebar:not(.collapsed) { transform: translateX(0); }
            .container { width: auto; margin: 15px; padding: 16px; }
            .container.expanded { width: auto; margin: 15px; }
            .search-input { min-width: 200px; width: 100%; }
            .toolbar form { width: 100%; }
        }
    
        /* Sidebar normalization: consistent spacing and interaction across admin pages */
        .sidebar-menu {
            list-style: none;
            padding: 6px 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #ffffff;
            text-decoration: none;
            line-height: 1.2;
            font-size: 15px;
            border-left: 4px solid transparent;
            transition: all 0.25s ease;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.10);
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
            margin-right: 0;
            flex: 0 0 20px;
            filter: brightness(0) invert(1);
        }

        .menu-group {
            margin: 8px 0;
        }

        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            line-height: 1.2;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="main-header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info">Admin Panel</div>
    </div>

    <?php
    $activeAdminPage = 'accounts_view';
    $adminSidebarCollapsed = true;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <div class="container" id="mainContent">
        <h1 class="title">View All Accounts</h1>
        <p class="subtitle">Browse and search student, adviser, program coordinator, and admin accounts with pagination.</p>

        <?php if ($feedbackMessage !== ''): ?>
            <div class="feedback success"><?= htmlspecialchars($feedbackMessage) ?></div>
        <?php endif; ?>
        <?php if ($feedbackError !== ''): ?>
            <div class="feedback error"><?= htmlspecialchars($feedbackError) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <a class="tab <?= $type === 'students' ? 'active' : '' ?>" href="accounts_view.php?type=students">Students</a>
            <a class="tab <?= $type === 'advisers' ? 'active' : '' ?>" href="accounts_view.php?type=advisers">Advisers</a>
            <a class="tab <?= $type === 'program_coordinators' ? 'active' : '' ?>" href="accounts_view.php?type=program_coordinators">Program Coordinators</a>
            <a class="tab <?= $type === 'admins' ? 'active' : '' ?>" href="accounts_view.php?type=admins">Admins</a>
        </div>

        <div class="toolbar">
            <form method="GET" action="accounts_view.php">
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                <input class="search-input" type="text" name="search" placeholder="Search in <?= htmlspecialchars($title) ?>" value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-search" type="submit">Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-clear" href="accounts_view.php?type=<?= htmlspecialchars($type) ?>">Clear</a>
                <?php endif; ?>
            </form>
            <div class="stats">Total: <strong><?= $total_records ?></strong></div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                        <?php if ($type === 'students'): ?>
                            <th class="actions-col">Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <?php foreach ($r as $index => $cell): ?>
                                    <?php if ($type === 'students' && $index === 6): ?>
                                        <td>
                                            <span class="status-pill <?= htmlspecialchars(strtolower(trim((string)$cell))) ?>">
                                                <?= htmlspecialchars((string)$cell) ?>
                                            </span>
                                        </td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars((string)$cell) ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ($type === 'students'): ?>
                                    <td class="actions-cell">
                                        <form method="POST" class="action-form">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="account_action" value="1">
                                            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$current_page ?>">
                                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                                            <input type="hidden" name="student_id" value="<?= htmlspecialchars((string)($r[0] ?? '')) ?>">
                                            <button type="submit" name="status_action" value="archive" class="btn-action archive" <?= $freezeApprovalsEnabled ? 'disabled' : '' ?>>Archive</button>
                                            <button type="submit" name="status_action" value="pending" class="btn-action pending" <?= $freezeApprovalsEnabled ? 'disabled' : '' ?>>Pending</button>
                                            <button type="submit" name="status_action" value="approve" class="btn-action approve" <?= $freezeApprovalsEnabled ? 'disabled' : '' ?>>Approve</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= max(1, count($columns) + ($type === 'students' ? 1 : 0)) ?>" class="empty-state">No records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <div>Page <?= $current_page ?> of <?= $total_pages ?></div>
                <div class="pagination-controls">
                    <?php $searchParam = $search !== '' ? '&search=' . urlencode($search) : ''; ?>
                    <?php if ($current_page > 1): ?>
                        <a class="page-btn" href="?type=<?= urlencode($type) ?>&page=1<?= $searchParam ?>">First</a>
                        <a class="page-btn" href="?type=<?= urlencode($type) ?>&page=<?= $current_page - 1 ?><?= $searchParam ?>">Previous</a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $current_page - 2);
                    $endPage = min($total_pages, $current_page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a class="page-btn <?= $i === $current_page ? 'active' : '' ?>" href="?type=<?= urlencode($type) ?>&page=<?= $i ?><?= $searchParam ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a class="page-btn" href="?type=<?= urlencode($type) ?>&page=<?= $current_page + 1 ?><?= $searchParam ?>">Next</a>
                        <a class="page-btn" href="?type=<?= urlencode($type) ?>&page=<?= $total_pages ?><?= $searchParam ?>">Last</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.main-header img');
            if (window.innerWidth <= 768 &&
                sidebar && !sidebar.contains(event.target) &&
                (!logo || !logo.contains(event.target))) {
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







