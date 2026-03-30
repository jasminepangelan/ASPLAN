
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/list_of_students_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Only allow admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$selectedProgram = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
$selectedBatch = isset($_GET['batch']) ? trim((string)$_GET['batch']) : '';

$queryParams = [];
if ($search !== '') {
    $queryParams['search'] = $search;
}
if ($selectedProgram !== '') {
    $queryParams['program'] = $selectedProgram;
}
if ($selectedBatch !== '') {
    $queryParams['batch'] = $selectedBatch;
}
$paginationSuffix = empty($queryParams) ? '' : '&' . http_build_query($queryParams);
$exportParams = $queryParams;
$exportParams['export'] = 'csv';
$exportUrl = '?' . http_build_query($exportParams);

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $bridgeLoaded = false;
    if (getenv('USE_LARAVEL_BRIDGE') === '1') {
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/list-of-students/overview',
            [
                'bridge_authorized' => true,
                'search' => $search,
                'program' => $selectedProgram,
                'batch' => $selectedBatch,
                'export' => true,
            ]
        );

        if (is_array($bridgeData) && !empty($bridgeData['success'])) {
            $students = isset($bridgeData['export_students']) && is_array($bridgeData['export_students'])
                ? $bridgeData['export_students']
                : [];

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=students_list_' . date('Y-m-d_H-i-s') . '.csv');
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($output, ['Student ID', 'Last Name', 'First Name', 'Middle Name', 'Program']);
            foreach ($students as $row) {
                fputcsv($output, [
                    $row['student_number'] ?? '',
                    $row['last_name'] ?? '',
                    $row['first_name'] ?? '',
                    $row['middle_name'] ?? '',
                    $row['program'] ?? '',
                ]);
            }
            fclose($output);
            exit();
        }
    }

    $students = losExportStudents($search, $selectedProgram, $selectedBatch);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_list_' . date('Y-m-d_H-i-s') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Student ID', 'Last Name', 'First Name', 'Middle Name', 'Program']);
    foreach ($students as $row) {
        fputcsv($output, [
            $row['student_number'],
            $row['last_name'],
            $row['first_name'],
            $row['middle_name'] ?? '',
            $row['program'] ?? ''
        ]);
    }
    fclose($output);
    exit();
}

$records_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page);
$offset = ($current_page - 1) * $records_per_page;

$availablePrograms = [];
$availableBatches = [];
$students = [];
$total_records = 0;
$total_pages = 1;

$bridgeLoaded = false;
if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/list-of-students/overview',
        [
            'bridge_authorized' => true,
            'search' => $search,
            'program' => $selectedProgram,
            'batch' => $selectedBatch,
            'page' => $current_page,
            'records_per_page' => $records_per_page,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        $availablePrograms = isset($bridgeData['available_programs']) && is_array($bridgeData['available_programs'])
            ? $bridgeData['available_programs']
            : [];
        $selectedProgram = (string) ($bridgeData['program'] ?? $selectedProgram);
        $availableBatches = isset($bridgeData['available_batches']) && is_array($bridgeData['available_batches'])
            ? $bridgeData['available_batches']
            : [];
        $selectedBatch = (string) ($bridgeData['batch'] ?? $selectedBatch);
        $students = isset($bridgeData['students']) && is_array($bridgeData['students'])
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
    $availablePrograms = losGetAvailablePrograms();
    if ($selectedProgram !== '' && !in_array($selectedProgram, $availablePrograms, true)) {
        $selectedProgram = '';
    }
    if ($selectedProgram !== '') {
        $availableBatches = losGetAvailableBatches($selectedProgram);
    }
    if ($selectedBatch !== '' && !in_array($selectedBatch, $availableBatches, true)) {
        $selectedBatch = '';
    }

    list($students, $total_records) = losLoadStudents($search, $selectedProgram, $selectedBatch, $records_per_page, $offset);
    $total_pages = ceil($total_records / $records_per_page);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>List of Students</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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

        .main-header > div:first-child {
            display: flex;
            align-items: center;
        }
        
        .main-header img {
            height: 32px;
            margin-right: 10px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
        }
        
        .main-header span {
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: 0.6px;
        }

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

        /* Sidebar */
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
            width: min(1500px, calc(100% - 30px));
            max-width: 1500px;
            margin: 25px auto 30px auto;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            padding: 25px;
            position: relative;
            transition: all 0.3s ease;
            transform: translateX(125px);
        }

        .container.expanded {
            transform: translateX(0);
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #206018, #4CAF50, #8BC34A);
            border-radius: 20px 20px 0 0;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-header .title-section {
            flex: 1;
            min-width: 250px;
        }
        
        .page-header h1 {
            color: #206018;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 6px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .page-header .subtitle {
            color: #666;
            font-size: 0.95rem;
            font-weight: 400;
        }
        
        .search-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            flex: 0 0 auto;
        }
        
        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(32, 96, 24, 0.25);
            white-space: nowrap;
        }
        
        .export-btn:hover {
            background: linear-gradient(135deg, #1a5015 0%, #45a049 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.4);
        }
        
        .export-btn svg {
            flex-shrink: 0;
        }
        
        .search-input {
            width: 300px;
            padding: 8px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 50px;
            font-size: 13px;
            outline: none;
            transition: all 0.3s ease;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .search-input:focus {
            border-color: #206018;
            box-shadow: 0 4px 20px rgba(32, 96, 24, 0.15);
        }

        .filter-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: -8px 0 16px;
            padding: 12px;
            background: #f8fbf7;
            border: 1px solid #dce8d9;
            border-radius: 10px;
            flex-wrap: wrap;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-form label {
            font-size: 12px;
            color: #206018;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .filter-form select {
            padding: 8px 10px;
            border: 1px solid #cddccc;
            border-radius: 8px;
            font-size: 13px;
            min-width: 220px;
            background: #fff;
        }

        .filter-form select:focus {
            outline: none;
            border-color: #206018;
            box-shadow: 0 0 0 3px rgba(32, 96, 24, 0.12);
        }

        .filter-note {
            color: #627364;
            font-size: 12px;
        }
        
        .table-container {
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }
        
        .table-scroll {
            max-height: 450px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #206018 #f1f1f1;
        }
        
        .table-scroll::-webkit-scrollbar {
            width: 8px;
        }
        
        .table-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .table-scroll::-webkit-scrollbar-thumb {
            background: #206018;
            border-radius: 4px;
        }
        
        table {
            width: 100%;
            min-width: 1150px;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        th {
            background: linear-gradient(135deg, #206018 0%, #2d7a2d 100%);
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        th:first-child {
            border-radius: 0;
        }
        
        th:last-child {
            border-radius: 0;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
            transition: all 0.2s ease;
        }
        
        tr {
            transition: all 0.2s ease;
        }
        
        tr:hover {
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f7ff 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        
        tr:nth-child(even) {
            background: #fafafa;
        }
        
        tr:hover:nth-child(even) {
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f7ff 100%);
        }
        
        .student-id {
            font-weight: 600;
            color: #206018;
            font-family: 'Courier New', monospace;
        }
        
        .student-name {
            font-weight: 500;
            color: #333;
        }
        
        .program-badge {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #4CAF50, #8BC34A);
            color: white;
            border-radius: 14px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .action-btn {
            display: inline-block;
            padding: 7px 16px;
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: #fff;
            border: none;
            border-radius: 20px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 8px rgba(32, 96, 24, 0.25);
        }

        .actions-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn.secondary {
            background: linear-gradient(135deg, #1b5e20 0%, #2e7d32 100%);
        }

        .action-btn.secondary:hover {
            background: linear-gradient(135deg, #145a18 0%, #256f2b 100%);
        }

        .action-btn.tertiary {
            background: linear-gradient(135deg, #1565c0 0%, #1976d2 100%);
        }

        .action-btn.tertiary:hover {
            background: linear-gradient(135deg, #0f58aa 0%, #1669bc 100%);
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(32, 96, 24, 0.4);
            background: linear-gradient(135deg, #1a5015 0%, #45a049 100%);
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            margin-top: 20px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: #fff;
            border: none;
            border-radius: 50px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(32, 96, 24, 0.25);
        }
        
        .back-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(32, 96, 24, 0.4);
            background: linear-gradient(135deg, #1a5015 0%, #45a049 100%);
        }
        
        .back-btn::before {
            content: '←';
            margin-right: 8px;
            font-size: 18px;
        }
        
        .stats-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            color: #206018;
            margin-bottom: 4px;
        }
        
        .stats-label {
            color: #666;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-size: 12px;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #999;
            font-size: 18px;
        }
        
        .no-data::before {
            content: '📚';
            display: block;
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 10px;
            margin: 15px 0;
        }
        
        .empty-state-icon {
            font-size: 56px;
            margin-bottom: 18px;
            opacity: 0.6;
            animation: float 3s ease-in-out infinite;
        }
        
        .empty-state h3 {
            color: #206018;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #666;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 6px;
        }
        
        .empty-state .help-text {
            color: #999;
            font-size: 0.9rem;
            margin-top: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #206018;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 25px;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .pagination-btn {
            padding: 8px 14px;
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            color: #206018;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 40px;
            text-align: center;
        }
        
        .pagination-btn:hover:not(.active):not(.disabled) {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border-color: #206018;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
        }
        
        .pagination-btn.active {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border-color: #206018;
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
            cursor: default;
        }
        
        .pagination-btn.disabled {
            background: #f0f0f0;
            color: #ccc;
            border-color: #e0e0e0;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .pagination-info {
            color: #666;
            font-size: 13px;
            font-weight: 500;
            padding: 0 15px;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-250px);
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }

            .container {
                margin: 20px 10px;
                padding: 15px;
                transform: none;
            }
            
            .main-header {
                padding: 0 15px;
                height: 60px;
            }
            
            .main-header span {
                font-size: 1.1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 15px;
            }
            
            .page-header .title-section {
                min-width: auto;
            }
            
            .page-header h1 {
                font-size: 1.8rem;
            }
            
            .page-header .subtitle {
                font-size: 0.95rem;
            }
            
            .search-bar {
                width: 100%;
                flex-direction: column;
            }

            .filter-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-form {
                width: 100%;
            }

            .filter-form select {
                min-width: 0;
                width: 100%;
            }
            
            .export-btn {
                width: 100%;
                justify-content: center;
                font-size: 12px;
                padding: 10px 16px;
            }
            
            .search-input {
                width: 100%;
                max-width: none;
                font-size: 14px;
            }
            
            .stats-card {
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .stats-number {
                font-size: 2rem;
            }
            
            .table-container {
                border-radius: 10px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-scroll {
                max-height: none;
            }
            
            /* Mobile: Card-style table layout */
            table {
                font-size: 14px;
                min-width: 600px; /* Enable horizontal scroll for very small screens */
            }
            
            th {
                font-size: 13px;
                padding: 15px 12px;
            }
            
            td {
                padding: 12px;
            }
            
            .student-id {
                font-size: 14px;
                padding: 6px 10px;
            }
            
            .student-name {
                font-size: 14px;
            }
            
            .program-badge {
                font-size: 11px;
                padding: 5px 10px;
            }
            
            .action-btn {
                padding: 8px 15px;
                font-size: 12px;
            }
            
            .back-btn {
                margin-top: 20px;
                padding: 12px 20px;
                font-size: 14px;
            }
            
            .empty-state {
                padding: 40px 20px;
            }
            
            .empty-state-icon {
                font-size: 56px;
            }
            
            .empty-state h3 {
                font-size: 1.4rem;
            }
            
            .empty-state p {
                font-size: 0.95rem;
            }
        }
        
        /* Extra small devices (phones in portrait, less than 576px) */
        @media (max-width: 576px) {
            .container {
                margin: 15px 5px;
                padding: 12px;
                transform: none;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .table-container {
                margin: -30px auto 0;
                width: 100%;
            }
            
            /* Switch to card layout for very small screens */
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
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
            
            .student-id,
            .student-name,
            .program-badge {
                display: inline-block;
                font-size: 0.95rem;
            }
            
            .action-btn {
                width: 100%;
                text-align: center;
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
    <!-- Title Bar -->
    <div class="main-header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info">Admin Panel</div>
    </div>

    <?php
    $activeAdminPage = 'list_of_students';
    $adminSidebarCollapsed = true;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>
    
    <div class="container" id="mainContent">
        <div class="page-header">
            <div class="title-section">
                <h1>Student Directory</h1>
                <p class="subtitle">Manage and view all registered students</p>
            </div>
            <div class="search-bar">
                <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="export-btn" title="Export filtered students to CSV">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    Export CSV
                </a>
                <form method="GET" action="" id="searchForm">
                    <input type="hidden" name="program" value="<?php echo htmlspecialchars($selectedProgram); ?>">
                    <input type="hidden" name="batch" value="<?php echo htmlspecialchars($selectedBatch); ?>">
                    <input type="text" class="search-input" id="searchInput" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="🔍 Search students by name or ID...">
                </form>
            </div>
        </div>

        <div class="filter-row">
            <form method="GET" action="" id="filterForm" class="filter-form">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">

                <label for="programFilter">Program</label>
                <select id="programFilter" name="program">
                    <option value="">-- Select Program --</option>
                    <?php foreach ($availablePrograms as $program): ?>
                        <option value="<?php echo htmlspecialchars($program); ?>" <?php echo $selectedProgram === $program ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($program); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="batchFilter">Batch</label>
                <select id="batchFilter" name="batch" <?php echo $selectedProgram === '' ? 'disabled' : ''; ?>>
                    <option value="">-- Select Batch --</option>
                    <?php foreach ($availableBatches as $batch): ?>
                        <option value="<?php echo htmlspecialchars($batch); ?>" <?php echo $selectedBatch === $batch ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($batch); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="filter-note">
                Select program first, then choose a batch to narrow the table.
            </div>
        </div>
        
        <div class="stats-card">
            <div class="stats-number"><?php echo $total_records; ?></div>
            <div class="stats-label"><?php echo !empty($search) ? 'Students Found' : 'Total Students'; ?></div>
        </div>
        
        <div class="table-container">
            <div class="table-scroll">
                <table id="studentsTable">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Last Name</th>
                            <th>First Name</th>
                            <th>Middle Name</th>
                            <th>Program</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $row): ?>
                                <tr>
                                    <td data-label="Student ID"><span class="student-id"><?php echo htmlspecialchars($row['student_number']); ?></span></td>
                                    <td data-label="Last Name"><span class="student-name"><?php echo htmlspecialchars($row['last_name']); ?></span></td>
                                    <td data-label="First Name"><span class="student-name"><?php echo htmlspecialchars($row['first_name']); ?></span></td>
                                    <td data-label="Middle Name"><span class="student-name"><?php echo htmlspecialchars($row['middle_name'] ?? ''); ?></span></td>
                                    <td data-label="Program"><span class="program-badge"><?php echo htmlspecialchars($row['program'] ?? ''); ?></span></td>
                                    <td data-label="Actions">
                                        <div class="actions-wrap">
                                            <a href="account_management.php?student_id=<?php echo urlencode($row['student_number']); ?>" class="action-btn profile-btn">Profile</a>
                                            <a href="../program_coordinator/checklist.php?student_id=<?php echo urlencode($row['student_number']); ?>" class="action-btn secondary">Checklist</a>
                                            <a href="../program_coordinator/study_plan_view.php?student_id=<?php echo urlencode($row['student_number']); ?>" class="action-btn tertiary">Study Plan</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <div class="empty-state-icon">👥</div>
                                        <h3>No Students Yet</h3>
                                        <p>The student directory is currently empty.</p>
                                        <p>New students will appear here once they register and are approved.</p>
                                        <div class="help-text">
                                            <strong>💡 Tip:</strong> Check the <a href="pending_accounts.php" style="color: #206018; font-weight: 600;">Pending Accounts</a> page to approve new registrations.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination-container">
            <?php if ($current_page > 1): ?>
                <a href="?page=1<?php echo $paginationSuffix; ?>" class="pagination-btn">First</a>
                <a href="?page=<?php echo $current_page - 1; ?><?php echo $paginationSuffix; ?>" class="pagination-btn">Previous</a>
            <?php else: ?>
                <span class="pagination-btn disabled">First</span>
                <span class="pagination-btn disabled">Previous</span>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
                <a href="?page=<?php echo $i; ?><?php echo $paginationSuffix; ?>" 
                   class="pagination-btn <?php echo $i == $current_page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="?page=<?php echo $current_page + 1; ?><?php echo $paginationSuffix; ?>" class="pagination-btn">Next</a>
                <a href="?page=<?php echo $total_pages; ?><?php echo $paginationSuffix; ?>" class="pagination-btn">Last</a>
            <?php else: ?>
                <span class="pagination-btn disabled">Next</span>
                <span class="pagination-btn disabled">Last</span>
            <?php endif; ?>
            
            <span class="pagination-info">
                Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
            </span>
        </div>
        <?php endif; ?>
        
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

        // Real-time search with debounce
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        const searchForm = document.getElementById('searchForm');
        const filterForm = document.getElementById('filterForm');
        const programFilter = document.getElementById('programFilter');
        const batchFilter = document.getElementById('batchFilter');

        if (programFilter && filterForm) {
            programFilter.addEventListener('change', function() {
                if (batchFilter) {
                    batchFilter.value = '';
                }
                filterForm.submit();
            });
        }

        if (batchFilter && filterForm) {
            batchFilter.addEventListener('change', function() {
                filterForm.submit();
            });
        }
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchForm.submit();
            }, 500);
        });
        
        // Add smooth scroll behavior
        document.documentElement.style.scrollBehavior = 'smooth';
        
        // Add loading animation for action buttons
        document.querySelectorAll('.action-btn:not(.profile-btn)').forEach(btn => {
            btn.addEventListener('click', function() {
                this.innerHTML = '⏳ Loading...';
            });
        });
    </script>
</body>
</html>









