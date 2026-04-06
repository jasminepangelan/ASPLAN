<?php
session_start();

// Database connection
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

function aceLoadStudentsFromAssignedScope(mysqli $conn, array $batches, ?string $adviserProgram, string $search, int $recordsPerPage, int $currentPage): array
{
    $recordsPerPage = max(1, $recordsPerPage);
    $currentPage = max(1, $currentPage);

    if (empty($batches)) {
        return [[], 0, 1];
    }

    $batchPlaceholders = implode(',', array_fill(0, count($batches), '?'));
    $whereParts = ["LEFT(student_number, 4) IN ($batchPlaceholders)"];
    $params = array_values($batches);
    $types = str_repeat('s', count($batches));

    if (!empty($adviserProgram)) {
        $whereParts[] = "program = ?";
        $params[] = $adviserProgram;
        $types .= 's';
    }

    $search = trim($search);
    if ($search !== '') {
        $searchParam = '%' . $search . '%';
        $whereParts[] = "(student_number LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ?)";
        array_push($params, $searchParam, $searchParam, $searchParam, $searchParam);
        $types .= 'ssss';
    }

    $whereClause = implode(' AND ', $whereParts);

    $countQuery = "SELECT COUNT(*) as total FROM student_info WHERE $whereClause";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        return [[], 0, 1];
    }
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult ? (int) (($countResult->fetch_assoc()['total'] ?? 0)) : 0;
    $countStmt->close();

    $totalPages = max(1, (int) ceil(max(0, $totalRecords) / $recordsPerPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $recordsPerPage;

    $query = "SELECT student_number AS student_id, last_name, first_name, middle_name, program
              FROM student_info
              WHERE $whereClause
              ORDER BY last_name ASC, first_name ASC
              LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return [[], $totalRecords, $totalPages];
    }

    $pageParams = $params;
    $pageParams[] = $recordsPerPage;
    $pageParams[] = $offset;
    $pageTypes = $types . 'ii';
    $stmt->bind_param($pageTypes, ...$pageParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();

    return [$rows, $totalRecords, $totalPages];
}

// Check if the adviser is logged in
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

// Get the logged-in adviser's ID, name, and program
$adviser_id = $_SESSION['id']; // Make sure session has the 'id' key for the adviser
$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

$adviser_program = null;
$batches = [];
$displayRows = [];
$total_records = 0;
$total_pages = 1;
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser/checklist-eval/overview',
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

    // Fetch adviser's program
    $adviser_stmt = $conn->prepare("SELECT program FROM adviser WHERE id = ?");
    $adviser_stmt->bind_param("i", $adviser_id);
    $adviser_stmt->execute();
    $adviser_result = $adviser_stmt->get_result();
    $adviser_data = $adviser_result->fetch_assoc();
    $adviser_program = $adviser_data ? $adviser_data['program'] : null;

    // Fetch all batches assigned to the adviser
    $stmt = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ?");
    $stmt->bind_param("i", $adviser_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $batches = [];
    while ($row = $result->fetch_assoc()) {
        $batchValue = trim((string) ($row['batch'] ?? ''));
        if ($batchValue !== '') {
            $batches[] = $batchValue;
        }
    }
}

if (empty($batches)) {
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
        background: #fee2e2;
        color: #ef4444;
        font-size: 32px;
        width: 72px;
        height: 72px;
        border-radius: 20px;
        box-shadow: 0 8px 16px -4px rgba(239, 68, 68, 0.3);
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
                <div class="modal-icon"><i>🛡️</i></div>
                <div class="modal-title">Access Restricted</div>
                <div class="modal-subtitle">Authentication Failed: No Batch Assignment</div>
                <div class="modal-desc">
                    <strong>You are not currently assigned to any student batch.</strong>
                    To access student records and evaluation tools, please request batch assignment from your system administrator.
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

if (!$bridgeLoaded && !empty($batches)) {
    [$displayRows, $total_records, $total_pages] = aceLoadStudentsFromAssignedScope(
        $conn,
        $batches,
        $adviser_program,
        $search,
        $records_per_page,
        $current_page
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List of students</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #eef2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            color: #333;
            overflow-x: hidden;
        }

        /* Title bar styling */
        .header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: white;
            padding: 5px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            justify-content: space-between;
            height: 42px;
        }

        .title-content {
            display: flex;
            align-items: center;
        }

        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
            cursor: pointer;
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

        /* Sidebar styling */
        .sidebar {
            width: 250px;
            height: calc(100vh - 42px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 42px;
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

        .sidebar-menu li {
            margin: 0;
        }

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

        .menu-group {
            margin-bottom: 20px;
        }

        .menu-group-title {
            padding: 10px 20px 5px;
            font-size: 12px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            font-weight: 600;
            letter-spacing: 1px;
        }



        /* Main content styling */
        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 42px);
            background-color: #f5f5f5;
            width: calc(100vw - 250px);
            overflow-x: hidden;
            transition: margin-left 0.3s ease, width 0.3s ease;
            padding-top: 42px;
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100vw;
        }

        .container {
            width: min(94%, 1420px);
            margin: 22px auto 0;
            padding: 22px 24px 26px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 250, 247, 0.96));
            border: 1px solid #dbe6d9;
            border-radius: 20px;
            box-shadow: 0 18px 42px rgba(24, 66, 20, 0.08);
        }

        /* Search container styling */
        .search-container {
            background: #ffffff;
            padding: 16px 18px;
            margin-top: 14px;
            border-radius: 14px;
            border: 1px solid #e0e8de;
            box-shadow: 0 8px 20px rgba(24, 66, 20, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }

        .search-form {
            display: flex;
            gap: 8px;
            flex: 1;
            min-width: 250px;
        }

        .search-input {
            flex: 1;
            padding: 11px 14px;
            border: 1px solid #d4ddd2;
            border-radius: 10px;
            font-size: 13px;
            transition: all 0.3s ease;
            min-width: 180px;
            background: #fbfcfb;
            color: #244122;
        }

        .search-input:focus {
            outline: none;
            border-color: #206018;
            box-shadow: 0 0 0 4px rgba(32, 96, 24, 0.10);
            background: #ffffff;
        }

        .search-btn, .clear-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .search-btn {
            background: linear-gradient(135deg, #206018 0%, #2d8023 100%);
            color: white;
        }

        .search-btn:hover {
            background: linear-gradient(135deg, #1a4f14 0%, #206018 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(32, 96, 24, 0.3);
        }

        .clear-btn {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }

        .clear-btn:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }

        .total-students {
            font-size: 14px;
            color: #355033;
            padding: 10px 16px;
            background: linear-gradient(180deg, #f7faf7 0%, #edf4ec 100%);
            border-radius: 10px;
            border: 1px solid #d8e4d5;
            white-space: nowrap;
            font-weight: 600;
        }

        .total-students strong {
            color: #206018;
            font-size: 16px;
            font-weight: 700;
        }

        .table-scroll {
            max-height: 550px;
            overflow-y: auto;
            overflow-x: auto;
            width: 100%;
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(24, 66, 20, 0.08);
            border: 1px solid #dbe5d9;
            position: relative;
            margin-top: 16px;
        }

        .table-header {
            background: linear-gradient(135deg, #206018 0%, #2d8023 100%);
            color: white;
            text-align: center;
            padding: 16px 18px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.4px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.18);
            border-bottom: 3px solid #1a4f14;
            border-radius: 16px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.10);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid #edf2ec;
        }

        th {
            background: #2f3331;
            color: white;
            font-weight: 800;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        tr {
            transition: all 0.3s ease;
        }

        tr:nth-child(even) {
            background-color: #f8fbf8;
        }

        tr:hover {
            background-color: #edf6ec;
        }

        td:first-child {
            font-weight: 600;
            color: #206018;
        }

        td:nth-child(2) {
            font-weight: 500;
            color: #333;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            text-decoration: none;
            font-weight: 700;
            padding: 8px 14px;
            border-radius: 10px;
            transition: all 0.22s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 96px;
            font-size: 12px;
            border: 1px solid transparent;
            cursor: pointer;
            letter-spacing: 0.1px;
            box-shadow: 0 6px 14px rgba(18, 54, 14, 0.12);
        }

        .btn-grades {
            background: linear-gradient(135deg, #5bbf58 0%, #3ea63f 100%);
            color: #fff;
            border-color: #3a913a;
        }

        .btn-grades:hover {
            background: linear-gradient(135deg, #4aae48 0%, #358f36 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(53, 143, 54, 0.24);
        }

        .btn-form {
            background: linear-gradient(135deg, #1f5b1a 0%, #154512 100%);
            color: #fff;
            border-color: #123b10;
        }

        .btn-form:hover {
            background: linear-gradient(135deg, #194a15 0%, #0f330d 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(21, 69, 18, 0.26);
        }

        .btn-profile {
            background: linear-gradient(135deg, #f4fbf1 0%, #e7f5e1 100%);
            color: #206018;
            border-color: #bdd8b4;
        }

        .btn-profile:hover {
            background: linear-gradient(135deg, #e8f5e1 0%, #d7edcc 100%);
            color: #174913;
            border-color: #9fca92;
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(23, 73, 19, 0.16);
        }

        /* Make the table cell containing buttons wider */
        td:last-child {
            min-width: 180px;
            white-space: nowrap;
            text-align: center;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1001;
                top: 36px;
                height: calc(100vh - 36px);
            }
            
            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100vw;
                padding-top: 36px;
            }
            
            .menu-toggle {
                display: block;
            }

            .header {
                padding: 5px 8px;
                font-size: 12px;
                height: 36px;
            }

            .header img {
                height: 22px !important;
                margin-right: 6px !important;
            }

            .adviser-name {
                font-size: 10px;
                padding: 3px 6px;
            }

            .container {
                width: 95%;
                padding: 16px 14px 18px;
                margin-top: 16px;
            }
            
            .search-container {
                flex-direction: column;
                padding: 15px;
            }

            .search-form {
                width: 100%;
                min-width: auto;
            }

            .search-input {
                min-width: auto;
                font-size: 13px;
            }

            .search-btn, .clear-btn {
                padding: 8px 14px;
                font-size: 11px;
            }

            .total-students {
                width: 100%;
                text-align: center;
                font-size: 14px;
            }
            
            th, td {
                padding: 12px 8px;
                font-size: 12px;
            }
            
            .btn {
                padding: 6px 10px;
                font-size: 10px;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
        }

        @media (max-width: 480px) {
            .header {
                font-size: 10px;
                padding: 4px 6px;
                height: 32px;
            }

            .header img {
                height: 20px !important;
                margin-right: 4px !important;
            }

            .adviser-name {
                font-size: 8px;
                padding: 2px 5px;
            }

            .sidebar {
                top: 32px;
                height: calc(100vh - 32px);
            }

            .main-content {
                padding-top: 32px;
            }
        }

        /* Empty state styling */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            font-style: italic;
        }

        /* Pagination styling */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 25px;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: #666;
            font-size: 13px;
            font-weight: 500;
            padding: 0 15px;
        }

        .pagination-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .page-btn {
            padding: 8px 14px;
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            color: #206018;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            border: 2px solid #e0e0e0;
            min-width: 40px;
            text-align: center;
        }

        .page-btn:hover:not(.active):not(.disabled) {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border-color: #206018;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
        }

        .page-btn.active {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border-color: #206018;
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
            pointer-events: none;
        }

        .page-btn.disabled {
            background: #f0f0f0;
            color: #ccc;
            border-color: #e0e0e0;
            cursor: not-allowed;
            opacity: 0.6;
        }

        @media (max-width: 768px) {
            .pagination {
                flex-direction: column;
                align-items: center;
                gap: 10px;
                padding: 12px 10px;
            }

            .pagination-info {
                font-size: 12px;
                text-align: center;
            }

            .pagination-controls {
                justify-content: center;
                gap: 5px;
            }

            .page-btn {
                padding: 6px 10px;
                font-size: 11px;
                min-width: 35px;
            }
        }

    </style>
</head>
<body>
    <div class="header">
        <div class="title-content">
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <span class="adviser-name"><?= $adviser_name ; echo " | Adviser " ?></span>
     </div>

    <!-- Sidebar Navigation -->
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
                <li><a href="#" class="active"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
                <li><a href="study_plan_list.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>
            </div>
            
            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="container">
        <div class="table-header">
            List of Students
        </div>
        
        <div class="search-container">
            <form method="GET" action="" class="search-form">
                <input type="text" name="search" placeholder="Search by Student ID, Name..." value="<?= htmlspecialchars($search) ?>" class="search-input">
                <button type="submit" class="search-btn">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="?" class="clear-btn">Clear</a>
                <?php endif; ?>
            </form>
            <div class="total-students">
                Total Students: <strong><?= $total_records ?></strong>
            </div>
        </div>
        
        <div class="table-scroll">
            <table border="1">
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Last Name</th>
                        <th>First Name</th>
                        <th>Middle Name</th>
                        <th>Program</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($displayRows)): ?>
                    <?php foreach ($displayRows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['student_id'] ?? $row['student_number'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['last_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['first_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($row['middle_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['program'] ?? '') ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="checklist.php?student_id=<?= htmlspecialchars((string) ($row['student_id'] ?? $row['student_number'] ?? '')) ?>" class="btn btn-grades">Grades</a>
                                    <a href="account_management.php?student_id=<?= htmlspecialchars((string) ($row['student_id'] ?? $row['student_number'] ?? '')) ?>" class="btn btn-profile">Profile</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-state">No students found for your batch and program.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Showing <?= min($offset + 1, $total_records) ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> students
            </div>
            <div class="pagination-controls">
                <?php 
                $search_param = !empty($search) ? '&search=' . urlencode($search) : '';
                if ($current_page > 1): ?>
                    <a href="?page=1<?= $search_param ?>" class="page-btn">First</a>
                    <a href="?page=<?= $current_page - 1 ?><?= $search_param ?>" class="page-btn">Previous</a>
                <?php else: ?>
                    <span class="page-btn disabled">First</span>
                    <span class="page-btn disabled">Previous</span>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a href="?page=<?= $i ?><?= $search_param ?>" class="page-btn <?= $i == $current_page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?= $current_page + 1 ?><?= $search_param ?>" class="page-btn">Next</a>
                    <a href="?page=<?= $total_pages ?><?= $search_param ?>" class="page-btn">Last</a>
                <?php else: ?>
                    <span class="page-btn disabled">Next</span>
                    <span class="page-btn disabled">Last</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.querySelector('.menu-toggle');
        const logo = document.querySelector('.header img');
        
        if (window.innerWidth <= 768 && 
            sidebar && !sidebar.contains(event.target) && 
            (!menuToggle || !menuToggle.contains(event.target)) &&
            (!logo || !logo.contains(event.target))) {
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

    // Real-time search functionality
    const searchInput = document.querySelector('.search-input');
    const searchForm = document.querySelector('.search-form');
    let searchTimeout;

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            // Clear previous timeout
            clearTimeout(searchTimeout);
            
            // Set new timeout to submit form after user stops typing (500ms delay)
            searchTimeout = setTimeout(function() {
                searchForm.submit();
            }, 500);
        });

        // Also submit on Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                searchForm.submit();
            }
        });
    }
</script>
</body>
</html>
