<?php
session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

$adviser_id = (int)$_SESSION['id'];
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}

$adviser_program = '';
$batches = [];
$displayRows = [];
$total_records = 0;
$total_pages = 1;
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser/study-plan/list/overview',
        [
            'bridge_authorized' => true,
            'adviser_id' => $adviser_id,
            'search' => $search,
            'page' => $current_page,
            'records_per_page' => $records_per_page,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $adviser_program = (string) ($bridgeData['adviser_program'] ?? '');
        $batches = isset($bridgeData['batches']) && is_array($bridgeData['batches'])
            ? array_values(array_map('trim', $bridgeData['batches']))
            : [];
        $displayRows = isset($bridgeData['students']) && is_array($bridgeData['students'])
            ? $bridgeData['students']
            : [];
        $total_records = (int) ($bridgeData['total_records'] ?? 0);
        $total_pages = max(1, (int) ($bridgeData['total_pages'] ?? 1));
        $current_page = max(1, (int) ($bridgeData['current_page'] ?? $current_page));
        $search = (string) ($bridgeData['search'] ?? $search);
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $conn = getDBConnection();

    $adviser_stmt = $conn->prepare("SELECT program FROM adviser WHERE id = ? LIMIT 1");
    $adviser_stmt->bind_param("i", $adviser_id);
    $adviser_stmt->execute();
    $adviser_result = $adviser_stmt->get_result();
    $adviser_row = $adviser_result->fetch_assoc();
    $adviser_program = $adviser_row['program'] ?? '';

    $batch_stmt = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ? ORDER BY batch ASC");
    $batch_stmt->bind_param("i", $adviser_id);
    $batch_stmt->execute();
    $batch_result = $batch_stmt->get_result();

    $batches = [];
    while ($row = $batch_result->fetch_assoc()) {
        $batchValue = trim((string) ($row['batch'] ?? ''));
        if ($batchValue !== '') {
            $batches[] = $batchValue;
        }
    }

    if (!empty($batches) && !empty($adviser_program)) {
        $batch_conditions = implode(' OR ', array_map(function($batch) use ($conn) {
            $batch_safe = $conn->real_escape_string($batch);
            return "student_number LIKE '{$batch_safe}%'";
        }, $batches));

        $program_safe = $conn->real_escape_string($adviser_program);
        $where_clause = "(" . $batch_conditions . ") AND program = '" . $program_safe . "'";

        if (!empty($search)) {
            $search_term = "%" . $conn->real_escape_string($search) . "%";
            $where_clause .= " AND (student_number LIKE '$search_term' OR last_name LIKE '$search_term' OR first_name LIKE '$search_term' OR middle_name LIKE '$search_term')";
        }

        $count_query = "SELECT COUNT(*) AS total FROM student_info WHERE " . $where_clause;
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute();
        $total_records = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $total_pages = $total_records > 0 ? (int) ceil($total_records / $records_per_page) : 1;
        $current_page = min($current_page, $total_pages);
        $offset = ($current_page - 1) * $records_per_page;

        $query = "SELECT student_number, last_name, first_name, middle_name, program FROM student_info WHERE " . $where_clause . " ORDER BY last_name ASC, first_name ASC LIMIT $records_per_page OFFSET $offset";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();

        $displayRows = [];
        while ($row = $result->fetch_assoc()) {
            $displayRows[] = $row;
        }
    }
}

if (empty($batches) || empty($adviser_program)) {
    echo '<style>
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.4s ease-out;
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .modal-container {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(226, 232, 240, 1);
        padding: 40px 32px;
        min-width: 400px;
        max-width: 90vw;
        text-align: center;
        position: relative;
        animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }
    @keyframes scaleIn {
        from { 
            transform: scale(0.95); 
            opacity: 0; 
        }
        to { 
            transform: scale(1); 
            opacity: 1; 
        }
    }
    .modal-icon {
        margin-bottom: 24px;
        position: relative;
    }
    .modal-icon i {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fef3c7;
        color: #d97706;
        font-size: 32px;
        width: 72px;
        height: 72px;
        border-radius: 20px;
        box-shadow: 0 8px 16px -4px rgba(217, 119, 6, 0.3);
        transform: rotate(-10deg);
        transition: transform 0.3s ease;
    }
    .modal-container:hover .modal-icon i {
        transform: rotate(0deg);
    }
    .modal-title {
        color: #0f172a;     
        font-size: 24px;
        font-weight: 700;
        margin-bottom: 12px;
        letter-spacing: -0.02em;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    .modal-subtitle {
        color: #64748b;
        font-size: 15px;
        font-weight: 500;
        margin-bottom: 24px;
        letter-spacing: 0.5px;
    }
    .modal-desc {
        color: #475569;
        font-size: 15px;
        line-height: 1.6;
        margin-bottom: 32px;
        padding: 0 16px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    .modal-desc strong {
        color: #0f172a;
        font-weight: 600;
        display: block;
        margin-bottom: 8px;
    }
    .modal-actions {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .modal-action {
        background: #0f172a;
        color: white;
        border: none;
        border-radius: 12px;
        padding: 14px 24px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        letter-spacing: 0.3px;
        width: 100%;
    }
    .modal-action:hover {
        background: #1e293b;
        transform: translateY(-1px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    .modal-action:active {
        transform: translateY(0);
    }
    .countdown-wrapper {
        margin-top: 20px;
        height: 4px;
        background: #f1f5f9;
        border-radius: 2px;
        overflow: hidden;
    }
    .countdown-bar {
        height: 100%;
        background: #94a3b8;
        border-radius: 2px;
        animation: countdown 5s linear;
        transform-origin: left;
    }
    @keyframes countdown {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
    }
    </style>';
    echo '<script>
    window.onload = function() {
        var modal = document.createElement("div");
        modal.className = "modal-overlay";
        modal.style.display = "flex";
        modal.innerHTML = `
            <div class="modal-container active">
                <div class="modal-icon"><i>⚠️</i></div>
                <div class="modal-title">Access Restricted</div>
                <div class="modal-subtitle">No Assignment Found</div>
                <div class="modal-desc">
                    <strong>No batch/program assignment found for this adviser.</strong>
                    To access study plans, you need to be assigned to at least one batch and program by the system administrator.
                </div>
                <div class="modal-actions">
                    <button class="modal-action" onclick="window.location.href=\'index.php\'">
                        Return to Dashboard
                    </button>
                    <div class="countdown-wrapper">
                        <div class="countdown-bar"></div>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add click outside to close
        modal.onclick = function(e) {
            if (e.target === modal) {
                modal.remove();
                window.location.href = "index.php";
            }
        };
        
        setTimeout(function() {
            modal.remove();
            window.location.href = "index.php";
        }, 5000);
    };  
    </script>'; 
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}
$offset = ($current_page - 1) * $records_per_page;

$batch_conditions = implode(' OR ', array_map(function($batch) use ($conn) {
    $batch_safe = $conn->real_escape_string($batch);
    return "student_number LIKE '{$batch_safe}%'";
}, $batches));

$program_safe = $conn->real_escape_string($adviser_program);
$where_clause = "(" . $batch_conditions . ") AND program = '" . $program_safe . "'";

if (!empty($search)) {
    $search_term = "%" . $conn->real_escape_string($search) . "%";
    $where_clause .= " AND (student_number LIKE '$search_term' OR last_name LIKE '$search_term' OR first_name LIKE '$search_term' OR middle_name LIKE '$search_term')";
}

$count_query = "SELECT COUNT(*) AS total FROM student_info WHERE " . $where_clause;
$count_stmt = $conn->prepare($count_query);
$count_stmt->execute();
$total_records = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;

$query = "SELECT student_number, last_name, first_name, middle_name, program FROM student_info WHERE " . $where_clause . " ORDER BY last_name ASC, first_name ASC LIMIT $records_per_page OFFSET $offset";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Study Plan List</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color: #333; }
        .header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 8px 15px;
            font-size: 18px;
            font-weight: 600;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 46px;
        }
        .title-content { display: flex; align-items: center; }
        .header img { width: 32px; height: 32px; margin-right: 10px; cursor: pointer; }
        .adviser-name {
            font-size: 17px;
            font-weight: 800;
            color: #facc41;
            background: rgba(250, 204, 65, 0.15);
            padding: 6px 10px;
            border-radius: 7px;
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
            height: calc(100vh - 46px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 46px;
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
            padding: 13px 20px;
            color: #fff;
            text-decoration: none;
            border-left: 3px solid transparent;
        }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); border-left-color: #4CAF50; }
        .sidebar-menu a.active { background: rgba(255,255,255,0.15); border-left-color: #4CAF50; }
        .sidebar-menu img { width: 20px; height: 20px; margin-right: 10px; filter: invert(1); }
        .menu-group-title { padding: 10px 20px 6px; font-size: 12px; text-transform: uppercase; color: rgba(255,255,255,0.7); font-weight: 600; }
        .main-content { margin-left: 250px; padding-top: 62px; transition: margin-left 0.3s ease; }
        .main-content.expanded { margin-left: 0; }
        .container { width: 92%; max-width: 1400px; margin: 0 auto; }
        .table-header {
            background: linear-gradient(135deg, #206018 0%, #2d8023 100%);
            color: #fff;
            text-align: center;
            padding: 14px;
            font-size: 18px;
            font-weight: 800;
            border-radius: 8px;
            margin-bottom: 12px;
        }
        .search-container {
            background: #fff;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .search-form { display: flex; gap: 8px; flex: 1; min-width: 260px; }
        .search-input { flex: 1; padding: 8px 10px; border: 1px solid #ddd; border-radius: 5px; }
        .search-btn, .clear-btn {
            border: none;
            padding: 8px 14px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        .search-btn { background: #206018; color: #fff; }
        .clear-btn { background: #6c757d; color: #fff; }
        .program-badge {
            background: #eef7ee;
            border: 1px solid #cfe6cf;
            color: #1f5e18;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        .table-wrap {
            background: #fff;
            border-radius: 8px;
            overflow: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        th { background: #333; color: #fff; text-transform: uppercase; font-size: 12px; }
        tr:nth-child(even) { background: #fafafa; }
        .btn-view {
            display: inline-block;
            background: linear-gradient(135deg, #206018 0%, #2d8023 100%);
            color: #fff;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
        }
        .empty-state { text-align: center; padding: 30px 14px; color: #666; }
        .pagination {
            margin-top: 10px;
            background: #fff;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .pagination-controls { display: flex; gap: 6px; flex-wrap: wrap; }
        .page-btn {
            text-decoration: none;
            color: #333;
            background: #f1f3f5;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 12px;
        }
        .page-btn.active { background: #206018; color: #fff; border-color: #206018; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
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
                <li><a href="#" class="active"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
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
            <div class="table-header">Study Plan - Student List</div>

            <div class="search-container">
                <form method="GET" action="" class="search-form">
                    <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Search by student number or name">
                    <button type="submit" class="search-btn">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="study_plan_list.php" class="clear-btn">Clear</a>
                    <?php endif; ?>
                </form>
                <div class="program-badge">Program: <?= htmlspecialchars($adviser_program) ?> | Students: <?= $total_records ?></div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student Number</th>
                            <th>Last Name</th>
                            <th>First Name</th>
                            <th>Middle Name</th>
                            <th>Program</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($displayRows)): ?>
                        <?php foreach ($displayRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['student_number'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['last_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['first_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($row['middle_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars((string) ($row['program'] ?? '')) ?></td>
                                <td style="text-align:center;">
                                    <a class="btn-view" href="study_plan_view.php?student_id=<?= urlencode((string) ($row['student_number'] ?? '')) ?>">View Study Plan</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">No students found for your assigned batch/program.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <div>Page <?= $current_page ?> of <?= $total_pages ?></div>
                    <div class="pagination-controls">
                        <?php $search_param = !empty($search) ? '&search=' . urlencode($search) : ''; ?>
                        <?php if ($current_page > 1): ?>
                            <a class="page-btn" href="?page=1<?= $search_param ?>">First</a>
                            <a class="page-btn" href="?page=<?= $current_page - 1 ?><?= $search_param ?>">Previous</a>
                        <?php endif; ?>
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a class="page-btn <?= $i === $current_page ? 'active' : '' ?>" href="?page=<?= $i ?><?= $search_param ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <a class="page-btn" href="?page=<?= $current_page + 1 ?><?= $search_param ?>">Next</a>
                            <a class="page-btn" href="?page=<?= $total_pages ?><?= $search_param ?>">Last</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }
    </script>
</body>
</html>
