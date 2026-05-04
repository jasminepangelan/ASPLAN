<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pending_accounts_service.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Check if admin is logged in
if (
    (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) &&
    (!isset($_SESSION['admin_username']) || empty($_SESSION['admin_username']))
) {
    header("Location: login.php");
    exit();
}

$csrfToken = getCSRFToken();
$adminSessionId = trim((string) ($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? ''));
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$pendingAccounts = [];
$auto_approve_enabled = false;
$loadedFromLaravel = false;
$loadError = '';

try {
    $auto_approve_enabled = paIsAutoApproveEnabled();

    if ($useLaravelBridge) {
        $bridgeData = postLaravelJsonBridge(
            '/api/admin/pending-accounts/list',
            [
                'bridge_authorized' => true,
                'admin_id' => $adminSessionId,
            ]
        );

        if (is_array($bridgeData) && array_key_exists('pending_accounts', $bridgeData)) {
            $pendingAccounts = is_array($bridgeData['pending_accounts']) ? $bridgeData['pending_accounts'] : [];
            $auto_approve_enabled = !empty($bridgeData['auto_approve_enabled']);
            $loadedFromLaravel = true;
        } elseif (is_array($bridgeData) && isset($bridgeData['message']) && $bridgeData['message'] !== '') {
            $loadError = 'Unable to load pending accounts from the bridge right now.';
        }
    }

    if (!$loadedFromLaravel) {
        $pendingAccounts = paLoadPendingAccounts();
    }
} catch (Throwable $e) {
    error_log('Admin pending accounts page failed: ' . $e->getMessage());
    $pendingAccounts = [];
    $loadError = 'Unable to load pending accounts right now. Please try again later.';
}

$pendingCount = count($pendingAccounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Accounts</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --brand-800: #184915;
            --brand-700: #1f5f1b;
            --brand-600: #2a7a20;
            --brand-500: #3f9a3b;
            --accent-500: #2b8aa5;
            --surface-0: #eef4ef;
            --surface-1: #ffffff;
            --surface-2: #f7faf7;
            --text-900: #223029;
            --text-700: #4d5a52;
            --line-soft: #d8e5d8;
            --line-mid: #c5d7c5;
            --shadow-soft: 0 8px 24px rgba(24, 58, 22, 0.09);
            --shadow-strong: 0 12px 30px rgba(24, 58, 22, 0.14);
        }

        html {
            overflow-x: hidden;
        }

        body {
            background:
                radial-gradient(circle at 6% 0%, rgba(63, 154, 59, 0.10) 0%, rgba(63, 154, 59, 0) 38%),
                radial-gradient(circle at 92% 12%, rgba(43, 138, 165, 0.10) 0%, rgba(43, 138, 165, 0) 35%),
                linear-gradient(165deg, #f4f8f4 0%, #edf3ee 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-900);
            line-height: 1.6;
            overflow-x: hidden;
            max-width: 100vw;
        }

        .header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 5px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            width: 100%;
            max-width: 100vw;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 6px 20px rgba(32, 96, 24, 0.25);
            backdrop-filter: blur(8px);
            overflow: hidden;
        }

        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
            cursor: pointer;
        }

        .admin-info {
            font-size: 14px;
            font-weight: 600;
            color: white;
            font-family: 'Segoe UI', Arial, sans-serif;
            letter-spacing: 0.4px;
            background: rgba(255, 255, 255, 0.17);
            padding: 6px 14px;
            border-radius: 999px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.12);
            border: 1px solid rgba(255, 255, 255, 0.35);
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            height: calc(100vh - 40px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 40px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 999;
            border-right: 1px solid rgba(255, 255, 255, 0.18);
        }

        .sidebar.collapsed {
            transform: translateX(-250px);
        }

        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            color: white;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
        }

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
    transition: all 0.3s ease;
    font-size: 15px;
    line-height: 1.2;
}

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 25px;
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left: 4px solid #4CAF50;
        }

        .sidebar-menu img {
    width: 20px;
    height: 20px;
    margin-right: 0;
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
    letter-spacing: 1px;
}

        .container {
            margin: 80px auto 20px;
            width: min(1320px, calc(100vw - 280px));
            transform: translateX(125px);
            padding: 0 20px;
            box-sizing: border-box;
            transition: transform 0.3s ease, width 0.3s ease;
        }

        .container.expanded {
            width: min(1400px, calc(100vw - 32px));
            transform: translateX(0);
        }
        
        .container > * {
            max-width: 100%;
            box-sizing: border-box;
        }

        .page-header {
            background: var(--surface-1);
            padding: 24px;
            border-radius: 14px;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--line-soft);
            margin-bottom: 18px;
            text-align: left;
            max-width: 100%;
            box-sizing: border-box;
            word-wrap: break-word;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--brand-500), var(--accent-500));
        }

        .page-header h2 {
            color: var(--brand-700);
            font-size: 25px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header .subtitle {
            color: var(--text-700);
            font-size: 14px;
            margin-bottom: 4px;
        }

        .table-container {
            background: var(--surface-1);
            border-radius: 14px;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--line-soft);
            overflow: hidden;
            margin-bottom: 20px;
            max-width: 100%;
            box-sizing: border-box;
        }

        .table-header {
            background: linear-gradient(135deg, var(--brand-700) 0%, var(--brand-500) 100%);
            color: white;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 17px;
            font-weight: 600;
        }

        .table-stats {
            background: rgba(255,255,255,0.15);
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: 0.3px;
            border: 1px solid rgba(255,255,255,0.28);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e7efe7;
        }

        th {
            background: linear-gradient(180deg, #f8fbf8 0%, #f2f7f2 100%);
            color: #245224;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
        }

        td {
            background: white;
            font-size: 14px;
        }

        tr:nth-child(even) td {
            background: #fcfefc;
        }

        tr:hover td {
            background: #eef7ee;
        }

        .student-number {
            color: #206018;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }

        .student-name {
            font-weight: 600;
            color: #333;
        }

        .action-icons {
            display: flex;
            gap: 12px;
        }

        .action-form {
            margin: 0;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
            font-size: 14px;
            border: 0;
            cursor: pointer;
        }

        .approve-btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(76, 175, 80, 0.3);
        }

        .approve-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 18px rgba(76, 175, 80, 0.42);
            filter: saturate(1.03);
        }

        .reject-btn {
            background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(244, 67, 54, 0.3);
        }

        .reject-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 18px rgba(244, 67, 54, 0.42);
            filter: saturate(1.03);
        }

        .info-banner {
            background: linear-gradient(135deg, #edf6ff 0%, #f4f9ff 100%);
            border: 1px solid #cce1f6;
            border-left: 4px solid #2684d8;
            padding: 14px 16px;
            margin: 14px 14px 10px;
            border-radius: 10px;
            color: #155ea9;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            box-sizing: border-box;
        }

        .info-banner i {
            margin-right: 10px;
            font-size: 18px;
        }

        .info-banner a {
            color: #1565c0;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            transition: border-color 0.3s ease;
        }

        .info-banner a:hover {
            border-bottom-color: #1565c0;
        }

        .empty-state {
            text-align: center;
            padding: 38px 20px;
            background: linear-gradient(180deg, #fbfefb 0%, #f5faf5 100%);
            border-radius: 12px;
            margin: 20px auto;
            max-width: 100%;
            box-sizing: border-box;
            word-wrap: break-word;
            border: 1px dashed #cad9ca;
        }

        .empty-state i {
            font-size: 52px;
            color: #206018;
            margin-bottom: 12px;
            opacity: 0.7;
            animation: none;
        }

        .empty-state h3 {
            font-size: 22px;
            margin-bottom: 8px;
            color: #206018;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 8px;
        }
        
        .empty-state .help-text {
            color: #5d6a62;
            font-size: 0.9rem;
            margin-top: 16px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #206018;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            box-sizing: border-box;
        }
        
        .empty-state .help-text a {
            word-break: break-word;
        }
        
        .success-message {
            background: linear-gradient(135deg, #eaf8ea 0%, #f3fcf3 100%);
            color: #145923;
            padding: 12px 14px;
            border-radius: 10px;
            margin: 14px;
            border-left: 4px solid #28a745;
            font-weight: 600;
            font-size: 13px;
            border: 1px solid #d2ebd3;
        }

        .error-message {
            background: linear-gradient(135deg, #fff1f0 0%, #fff8f7 100%);
            color: #8d1f16;
            padding: 12px 14px;
            border-radius: 10px;
            margin: 14px;
            border-left: 4px solid #dc3545;
            font-weight: 600;
            font-size: 13px;
            border: 1px solid #f0c7cc;
        }

        @media (max-width: 1280px) {
            * {
                max-width: 100%;
            }

            .sidebar {
                transform: translateX(-250px);
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }
            
            .container {
                padding: 80px 10px 20px;
                max-width: 100% !important;
                width: 100% !important;
                overflow-x: hidden;
                margin: 80px 0 20px;
                transform: none;
            }

            .container.expanded {
                width: 100% !important;
                margin: 80px 0 20px;
                transform: none;
            }
            
            .page-header {
                padding: 20px 10px;
                margin: 0 0 15px 0;
                width: 100%;
                text-align: center;
            }
            
            .page-header h2 {
                font-size: 20px;
                word-wrap: break-word;
            }
            
            .page-header .subtitle {
                font-size: 13px;
                word-wrap: break-word;
            }
            
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0;
                border-radius: 10px;
                width: 100%;
            }
            
            .empty-state {
                margin: 15px auto;
                padding: 40px 15px;
            }
            
            .empty-state .help-text {
                font-size: 12px;
                padding: 10px;
                word-wrap: break-word;
                overflow-wrap: break-word;
                hyphens: auto;
            }
            
            .empty-state p {
                font-size: 14px;
                word-wrap: break-word;
            }
            
            .table-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 10px;
            }
            
            .table-header h3 {
                font-size: 18px;
            }
            
            .table-stats {
                font-size: 13px;
            }
            
            .info-banner {
                padding: 15px;
                margin: 15px 0;
                font-size: 14px;
                word-break: break-word;
            }
            
            .info-banner br {
                display: block;
                content: "";
                margin: 8px 0;
            }
            
            table {
                min-width: unset;
                width: 100%;
            }
            
            th {
                padding: 15px 12px;
                font-size: 13px;
            }
            
            td {
                padding: 12px;
                font-size: 14px;
            }
            
            .student-number {
                font-size: 13px;
            }
            
            .student-name {
                font-size: 14px;
            }
            
            .action-icons {
                gap: 10px;
            }
            
            .action-btn {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }
            
            .info-banner {
                padding: 15px 20px;
                font-size: 14px;
            }
            
            .empty-state {
                padding: 40px 20px;
                margin: 15px auto;
            }
            
            .empty-state i {
                font-size: 48px;
            }
            
            .empty-state h3 {
                font-size: 20px;
            }
            
            .empty-state p {
                font-size: 14px;
            }
        }
        
        /* Extra small devices - Card layout */
        @media (max-width: 576px) {
            .container {
                padding: 70px 5px 15px;
            }
            
            .page-header {
                padding: 15px 10px;
            }
            
            .page-header h2 {
                font-size: 18px;
            }
            
            .empty-state {
                padding: 30px 10px;
                margin: 15px auto;
            }
            
            .empty-state .help-text {
                font-size: 11px;
                padding: 8px;
            }
            
            .empty-state p {
                font-size: 13px;
            }
            
            /* Card-style table */
            table thead {
                display: none;
            }
            
            table, table tbody, table tr, table td {
                display: block;
                width: 100%;
            }
            
            table tr {
                margin-bottom: 15px;
                background: white;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                border: 1px solid #e0e0e0;
                overflow: hidden;
            }
            
            table td {
                text-align: right;
                padding: 14px 15px 14px 42%;
                border-bottom: 1px solid #f0f0f0;
                position: relative;
                min-height: 48px;
                display: block;
                word-wrap: break-word;
            }
            
            table td:last-child {
                border-bottom: none;
                padding-bottom: 15px;
            }
            
            table td::before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                top: 14px;
                font-weight: 600;
                color: #206018;
                text-align: left;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
            }
            
            .action-icons {
                justify-content: flex-end;
                gap: 15px;
            }
            
            .action-btn {
                width: 40px;
                height: 40px;
            }
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

        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: inline-flex;
            }
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
    <div class="header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info">Admin Panel</div>
    </div>

    <?php
    $activeAdminPage = 'pending_accounts';
    $adminSidebarCollapsed = true;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <div class="container" id="mainContent">
        <div class="page-header">
            <h2><i class="fas fa-users-cog"></i> Student Pending Accounts</h2>
            <p class="subtitle">Review and manage student account approval requests</p>
        </div>

        <?php if ($loadError !== ''): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-clock"></i> Pending Approvals</h3>
                <div class="table-stats" id="pendingCount"><?php echo (int) $pendingCount; ?> pending</div>
            </div>
        <table>
            <thead>
                <tr>
                    <th>STUDENT NUMBER</th>
                    <th>FULL NAME</th>
                    <th>ACTION</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Display messages
                if (isset($_GET['message'])) {
                    $messageText = htmlspecialchars((string) $_GET['message'], ENT_QUOTES, 'UTF-8');
                    echo "<div class='success-message'>
                            <i class='fas fa-check-circle'></i> {$messageText}
                          </div>";
                }
                if (isset($_GET['error'])) {
                    $errorText = htmlspecialchars((string) $_GET['error'], ENT_QUOTES, 'UTF-8');
                    echo "<div class='error-message'>
                            <i class='fas fa-exclamation-circle'></i> {$errorText}
                          </div>";
                }

                if ($auto_approve_enabled) {
                    echo "
                        <div class='info-banner'>
                            <i class='fas fa-info-circle'></i> 
                            <strong>Auto-Approval is currently ENABLED.</strong> 
                            New student accounts are automatically approved and do not appear in pending list.
                            <br><br>
                            <a href='account_approval_settings.php'>
                                <i class='fas fa-cog'></i> Manage Settings
                            </a>
                        </div>
                    ";
                }

                if ($pendingCount > 0) {
                    foreach ($pendingAccounts as $row) {
                        $fullName = htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . ' ' . $row['middle_name']);
                        echo "
                            <tr>
                                <td data-label='Student ID'><span class='student-number'>" . htmlspecialchars($row['student_id']) . "</span></td>
                                <td data-label='Student Name'><span class='student-name'>{$fullName}</span></td>
                                <td data-label='Actions'>
                                    <div class='action-icons'>
                                        <form method='post' action='approve_account.php' class='action-form' onsubmit=\"return confirm('Are you sure you want to approve this account?');\">
                                            <input type='hidden' name='student_id' value='" . htmlspecialchars($row['student_id'], ENT_QUOTES, 'UTF-8') . "'>
                                            <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . "'>
                                            <button type='submit' class='action-btn approve-btn' title='Approve Account'>
                                                <i class='fas fa-check'></i>
                                            </button>
                                        </form>
                                        <form method='post' action='reject_account.php' class='action-form' onsubmit=\"return confirm('Are you sure you want to reject this account?');\">
                                            <input type='hidden' name='student_id' value='" . htmlspecialchars($row['student_id'], ENT_QUOTES, 'UTF-8') . "'>
                                            <input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . "'>
                                            <button type='submit' class='action-btn reject-btn' title='Reject Account'>
                                                <i class='fas fa-times'></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        ";
                    }
                } else {
                    if ($auto_approve_enabled) {
                        echo "
                            <tr>
                                <td colspan='3' style='text-align: center; padding: 20px 10px;'>
                                    <div class='empty-state' style='margin: 0 auto; max-width: 500px;'>
                                        <i class='fas fa-check-circle'></i>
                                        <h3>All Clear!</h3>
                                        <p>No pending accounts to review.</p>
                                        <p>Auto-approval is enabled - new registrations are automatically approved.</p>
                                        <div class='help-text'>
                                            <strong>Tip:</strong> You can view all registered student records in the <a href='list_of_students.php' style='color: #206018; font-weight: 600;'>Registered Students Directory</a>.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        ";
                    } else {
                        echo "
                            <tr>
                                <td colspan='3' style='text-align: center; padding: 20px 10px;'>
                                    <div class='empty-state' style='margin: 0 auto; max-width: 500px;'>
                                        <i class='fas fa-inbox'></i>
                                        <h3>No Pending Accounts</h3>
                                        <p>No new student registrations waiting for approval.</p>
                                        <div class='help-text'>
                                            <strong>Note:</strong> New student registrations will appear here automatically. Students can register through the main login page.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        ";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Sidebar toggle functionality
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const logo = document.querySelector('.header img');
        
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

    // Initialize sidebar state on page load
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

    // Handle responsive behavior
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (window.innerWidth > 768) {
            // Reset to desktop view
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
        } else {
            // On mobile, keep sidebar collapsed
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
    });

    // Add smooth transitions and interactions
    document.addEventListener('DOMContentLoaded', function() {
        // Add loading animation
        const tableStats = document.getElementById('pendingCount');
        if (tableStats && tableStats.innerHTML === 'Loading...') {
            setTimeout(() => {
                if (tableStats.innerHTML === 'Loading...') {
                    tableStats.innerHTML = '0 pending';
                }
            }, 1000);
        }

    });
</script>
</body>
</html>







