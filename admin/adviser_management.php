<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/adviser_management_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Batch</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --brand-700: #1a4f16;
            --brand-600: #206018;
            --brand-500: #2e7d32;
            --brand-400: #4CAF50;
            --surface-100: #f8f9fa;
            --surface-200: #eef2ef;
            --text-muted: #647067;
            --panel-bg: #ffffff;
            --panel-border: #dbe5db;
            --panel-shadow: 0 3px 10px rgba(32, 96, 24, 0.08);
        }
        
        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.8);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            padding: 25px 35px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
            z-index: 2001;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            min-width: 320px;
            border: 2px solid rgba(32, 96, 24, 0.1);
        }
        
        .modal-container.active {
            transform: translate(-50%, -50%) scale(1);
            opacity: 1;
        }
        
        .modal-icon {
            font-size: 52px;
            color: #4CAF50;
            margin-bottom: 18px;
            animation: pulse 2s infinite;
        }
        
        .modal-title {
            color: #206018;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }
        
        .modal-close {
            position: absolute;
            top: 12px;
            right: 16px;
            font-size: 28px;
            color: #aaa;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .modal-close:hover {
            color: #206018;
            background: rgba(32, 96, 24, 0.1);
            transform: rotate(90deg);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        body {
            background: #f2f5f1;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            padding-top: 45px;
        }
        
        .header {
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
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 45px;
        }
        
        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
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
        
        .content {
            margin: 24px 24px 24px 274px;
            animation: slideInUp 0.6s ease-out;
            transition: margin-left 0.3s ease;
        }

        .content.expanded {
            margin-left: 24px;
        }
        
        .page-header {
            text-align: center;
            background: var(--panel-bg);
            padding: 16px 18px;
            border-radius: 10px;
            box-shadow: var(--panel-shadow);
            margin-bottom: 16px;
            border: 1px solid var(--panel-border);
        }

        .page-header h2 {
            color: #206018;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: 0.2px;
        }

        .page-header .subtitle {
            color: #666;
            font-size: 13px;
            font-weight: 400;
            margin-top: 4px;
        }
        
        .table-container {
            margin: 0 auto 20px;
            width: 100%;
            max-width: 100%;
            background: var(--panel-bg);
            border-radius: 10px;
            box-shadow: var(--panel-shadow);
            overflow: hidden;
            border: 1px solid var(--panel-border);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .table-container:hover {
            box-shadow: 0 4px 14px rgba(32, 96, 24, 0.12);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            background: transparent;
        }
        
        th {
            background: #206018;
            color: #fff;
            padding: 10px 10px;
            text-align: left;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            border-bottom: 2px solid #1a4d14;
            top: 0;
            z-index: 5;
        }
        
        th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, #4CAF50 50%, transparent 100%);
        }
        
        td {
            padding: 10px 10px;
            text-align: left;
            border-bottom: 1px solid rgba(32, 96, 24, 0.08);
            font-size: 13px;
            background: #ffffff;
            transition: all 0.3s ease;
            position: relative;
            vertical-align: top;
        }
        
        tr:nth-child(even) td {
            background: #f7faf7;
        }
        
        tr:hover td {
            background: #eef6ee;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        /* Compact column widths */
        th:nth-child(1), td:nth-child(1) {
            width: 20%;
            font-weight: 600;
            color: #206018;
            font-size: 15px;
            text-align: center;
        }
        
        th:nth-child(2), td:nth-child(2) {
            width: 80%;
        }
        
        .batch-form {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            flex-wrap: wrap;
            padding: 4px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            border: 1px solid rgba(32, 96, 24, 0.08);
        }
        
        .batch-select {
            flex-grow: 1;
        }
        
        .batch-checkbox-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            padding: 4px 0;
            justify-content: flex-start;
            max-height: 92px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .batch-checkbox-group::-webkit-scrollbar {
            width: 6px;
        }

        .batch-checkbox-group::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 8px;
        }

        .batch-checkbox-group::-webkit-scrollbar-thumb {
            background: rgba(32, 96, 24, 0.45);
            border-radius: 8px;
        }
        
        .batch-checkbox-group label {
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            padding: 5px 10px;
            border-radius: 14px;
            transition: all 0.3s ease;
            background: #f4f7f4;
            border: 1px solid #dbe5db;
            cursor: pointer;
            font-weight: 500;
            box-shadow: none;
            font-size: 11px;
            min-width: fit-content;
            position: relative;
            overflow: hidden;
        }
        
        .batch-checkbox-group label:hover:not(.disabled-batch) {
            background: #e9f4e9;
            border-color: #9fca9f;
        }
        
        .batch-checkbox-group label:has(input:checked) {
            background: #2e7d32;
            color: white;
            border-color: #2e7d32;
            box-shadow: none;
            transform: none;
        }
        
        .batch-checkbox-group input[type="checkbox"] {
            transform: scale(1.1);
            cursor: pointer;
            accent-color: #4CAF50;
        }

        .batch-checkbox-group input[type="checkbox"]:focus-visible {
            outline: 2px solid #206018;
            outline-offset: 2px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 18px;
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.25);
            gap: 6px;
            margin-top: 12px;
        }
        
        .back-btn:hover {
            background: linear-gradient(135deg, #45a049 0%, #3e8e41 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        
        .back-btn::before {
            content: '←';
            font-size: 18px;
            font-weight: bold;
        }

        .bulk-update-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 0;
            padding: 8px 18px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: #206018;
            box-shadow: 0 2px 6px rgba(32, 96, 24, 0.2);
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .table-actions {
            margin: 0 0 18px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            padding: 10px 14px;
            box-shadow: var(--panel-shadow);
        }

        .program-filter-card {
            margin: 0 0 16px 0;
            padding: 12px 14px;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            box-shadow: var(--panel-shadow);
        }

        .program-filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .program-filter-form label {
            font-size: 13px;
            font-weight: 700;
            color: var(--brand-700);
            letter-spacing: 0.2px;
        }

        .program-filter-form select {
            min-width: 280px;
            max-width: 100%;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid rgba(32, 96, 24, 0.25);
            font-size: 13px;
            background: #fff;
            color: #1f2a22;
        }

        .program-filter-note {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .selection-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--brand-700);
            font-weight: 600;
            background: rgba(32, 96, 24, 0.08);
            border: 1px solid rgba(32, 96, 24, 0.15);
            padding: 7px 12px;
            border-radius: 999px;
        }

        .selection-summary .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--brand-400);
            box-shadow: 0 0 0 5px rgba(76, 175, 80, 0.2);
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .bulk-update-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(32, 96, 24, 0.3);
            background: #1b5617;
        }

        .clear-selection-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border: 1px solid rgba(32, 96, 24, 0.25);
            border-radius: 50px;
            background: #fff;
            color: var(--brand-700);
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.2px;
            transition: all 0.25s ease;
            text-transform: uppercase;
        }

        .clear-selection-btn:hover {
            background: var(--surface-200);
            transform: translateY(-1px);
        }

        .bulk-update-btn:disabled,
        .clear-selection-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .submit-btn {
            flex-shrink: 0;
            background: #2e7d32;
            color: white;
            border: none;
            border-radius: 12px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 6px rgba(76, 175, 80, 0.25);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            position: relative;
            overflow: hidden;
        }
        
        .submit-btn:hover {
            background: #256a29;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.35);
        }
        
        .submit-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(76, 175, 80, 0.3);
        }
        
        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .submit-btn:hover::before {
            left: 100%;
        }
        
        .disabled-batch {
            background: linear-gradient(135deg, #e0e0e0 0%, #d0d0d0 100%) !important;
            color: #888 !important;
            pointer-events: none;
            opacity: 0.6;
            border-color: #ccc !important;
        }
        .unassign-btn {
            margin-left: 0;
            background: #c73939;
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.3s ease;
            position: static;
            display: inline-block;
            vertical-align: middle;
            box-shadow: 0 2px 6px rgba(220, 53, 69, 0.25);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .unassign-btn:hover {
            background: #ab2f2f;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(199, 57, 57, 0.35);
        }
        
        .add-batch-btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 28px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .add-batch-btn:hover {
            background: linear-gradient(135deg, #45a049 0%, #3e8e41 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        
        .add-batch-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .add-batch-btn:hover::before {
            left: 100%;
        }

        .add-batch-section {
            text-align: center;
            margin: 30px auto;
            padding: 25px;
            background: var(--panel-bg);
            border-radius: 12px;
            box-shadow: var(--panel-shadow);
            border: 1px solid var(--panel-border);
        }
        
        /* Responsive and animation enhancements */
        @keyframes slideInUp {
            from { 
                opacity: 0; 
                transform: translateY(30px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        @media (max-width: 1200px) {
            .table-container {
                width: 99%;
            }
            
            .batch-checkbox-group {
                gap: 6px;
            }
            
            .batch-checkbox-group label {
                font-size: 11px;
                padding: 5px 10px;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-250px);
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }

            .content {
                margin: 70px 10px 10px;
            }

            .content.expanded {
                margin-left: 10px;
            }
            
            .page-header h2 {
                font-size: 24px;
            }

            .page-header .subtitle {
                font-size: 14px;
            }
            
            .back-btn {
                padding: 10px 20px;
                font-size: 14px;
            }
            
            th, td {
                padding: 12px 8px;
                font-size: 13px;
            }
            
            .batch-checkbox-group {
                gap: 4px;
            }
            
            .batch-checkbox-group label {
                font-size: 10px;
                padding: 4px 8px;
            }
            
            .submit-btn, .unassign-btn {
                padding: 6px 10px;
                font-size: 11px;
            }

            .table-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .selection-summary {
                justify-content: center;
            }

            .action-buttons {
                justify-content: center;
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
    $activeAdminPage = 'adviser_management';
    $adminSidebarCollapsed = true;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <div class="content" id="mainContent">
        <div class="page-header">
            <h2><i class="fas fa-users-cog"></i> Adviser Management</h2>
            <p class="subtitle">Manage adviser batch assignments and responsibilities</p>
        </div>
    <!-- Modal for Success Message -->
    <div id="successModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="modal-title" id="modalMessage">
                Batches Successfully Updated
            </div>
        </div>
    </div>
    <?php
    if (isset($_GET['message'])) {
        echo "<script>
            window.onload = function() {
                var modal = document.getElementById('successModal');
                var container = modal.querySelector('.modal-container');
                var msg = document.getElementById('modalMessage');
                modal.style.display = 'block';
                msg.textContent = '" . htmlspecialchars($_GET['message']) . "';
                setTimeout(() => container.classList.add('active'), 10);
                setTimeout(function() {
                    container.classList.remove('active');
                    setTimeout(function() { modal.style.display = 'none'; }, 300);
                }, 1500);
            };
        </script>";
    }
    if (isset($_GET['error'])) {
        echo "<div style='text-align: center; color: red; font-weight: bold; margin: 10px 0;'>" . htmlspecialchars($_GET['error']) . "</div>";
    }

    $selectedProgram = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
    $availablePrograms = [];
    $batches = [];
    $advisers = [];
    $batchAssignments = [];
    $usedBatchFallback = false;
    $dbError = '';
    $conn = null;

    $bridgeLoaded = false;
    if (getenv('USE_LARAVEL_BRIDGE') === '1') {
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser-management/overview',
            [
                'bridge_authorized' => true,
                'user_type' => 'admin',
                'username' => $_SESSION['admin_username'] ?? ($_SESSION['username'] ?? ''),
                'selected_program' => $selectedProgram,
            ]
        );

        if (is_array($bridgeData) && !empty($bridgeData['success'])) {
            $selectedProgram = (string) ($bridgeData['selected_program'] ?? '');
            $availablePrograms = isset($bridgeData['available_programs']) && is_array($bridgeData['available_programs'])
                ? $bridgeData['available_programs']
                : [];
            $batches = isset($bridgeData['batches']) && is_array($bridgeData['batches'])
                ? $bridgeData['batches']
                : [];
            $advisers = isset($bridgeData['advisers']) && is_array($bridgeData['advisers'])
                ? $bridgeData['advisers']
                : [];
            $batchAssignments = isset($bridgeData['batch_assignments']) && is_array($bridgeData['batch_assignments'])
                ? $bridgeData['batch_assignments']
                : [];
            $usedBatchFallback = !empty($bridgeData['used_batch_fallback']);
            $bridgeLoaded = true;
        } elseif (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
            $dbError = 'Database error. Please try again later.';
        }
    }

    if (!$bridgeLoaded) {
        try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $conn = new PDO($dsn, DB_USER, DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $result = amLoadAdviserManagementData($conn, $selectedProgram);
        $selectedProgram = (string)$result['selectedProgram'];
        $availablePrograms = (array)$result['availablePrograms'];
        $batches = (array)$result['batches'];
        $advisers = (array)$result['advisers'];
        $batchAssignments = (array)$result['batchAssignments'];
        $usedBatchFallback = (bool)$result['usedBatchFallback'];

        if (isset($_GET['debug'])) {
            echo amBuildDebugHtml($conn, $selectedProgram, $availablePrograms, $advisers, $batches);
        }
    } catch (PDOException $e) {
        error_log('Adviser management DB error: ' . $e->getMessage());
        $dbError = 'Database error. Please try again later.';
    }
    }
    ?>

    <div class="program-filter-card">
        <form method="GET" action="adviser_management.php" class="program-filter-form">
            <label for="programSelect">Select Program First:</label>
            <select id="programSelect" name="program" onchange="this.form.submit()">
                <option value="">-- Select Program --</option>
                <?php foreach ($availablePrograms as $programKey => $programLabel): ?>
                    <option value="<?php echo htmlspecialchars($programKey); ?>" <?php echo $selectedProgram === $programKey ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($programLabel); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="program-filter-note">
            <?php if ($selectedProgram !== ''): ?>
                Showing adviser assignments for <strong><?php echo htmlspecialchars(amGetProgramLabelFromKey($selectedProgram)); ?></strong>.
                <?php if ($usedBatchFallback): ?>
                    No matching student batches were found for this program, so all available batches are shown.
                <?php endif; ?>
            <?php else: ?>
                Choose a program from adviser accounts to load batches before assigning advisers.
            <?php endif; ?>
        </div>
    </div>

    <div class="table-container">
    <table>
        <thead>
            <tr>
                <th>Batch</th>
                <th>Assigned Adviser</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if ($dbError !== '') {
                echo "<tr><td colspan='2' style='text-align: center; color: red;'>" . $dbError . "</td></tr>";
            } elseif (empty($availablePrograms)) {
                echo "<tr><td colspan='2' style='text-align:center; padding:40px 0; color:#206018; font-size:18px; font-weight:600; background:rgba(255,255,255,0.85); border-radius:8px;'>No adviser programs found. Please set adviser.program first.</td></tr>";
            } elseif ($selectedProgram === '') {
                echo "<tr><td colspan='2' style='text-align:center; padding:40px 0; color:#206018; font-size:18px; font-weight:600; background:rgba(255,255,255,0.85); border-radius:8px;'>Please select a program first to view its batches.</td></tr>";
            } elseif (empty($batches)) {
                echo "<tr><td colspan='2' style='text-align:center; padding:40px 0; color:#206018; font-size:18px; font-weight:600; background:rgba(255,255,255,0.85); border-radius:8px;'>No student batches found for " . htmlspecialchars($selectedProgram) . ".</td></tr>";
            } else {
                foreach ($batches as $batch) {
                    $batchStr = (string)$batch;
                    $assignedAdvisers = isset($batchAssignments[$batchStr]) ? $batchAssignments[$batchStr] : [];
                    ?>
                    <tr>
                        <td style="text-align: center; font-size: 16px; font-weight: 700; color: #206018; background: linear-gradient(135deg, rgba(76,175,80,0.1) 0%, rgba(32,96,24,0.05) 100%);">
                            <i class="fas fa-calendar-alt" style="margin-right: 6px; color: #4CAF50; font-size: 14px;"></i>
                            <?php echo htmlspecialchars($batch); ?>
                        </td>
                        <td>
                            <div style="display: flex; align-items: flex-start; gap: 8px; flex-wrap: wrap;">
                                <form method="POST" action="../batch_update.php" style="display: flex; align-items: flex-start; gap: 8px; flex: 1; background: rgba(255, 255, 255, 0.8); padding: 6px; border-radius: 6px; border: 1px solid rgba(32, 96, 24, 0.1); box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);">
                                    <input type="hidden" name="batch" value="<?php echo htmlspecialchars($batchStr); ?>">
                                    <input type="hidden" name="selected_program" value="<?php echo htmlspecialchars($selectedProgram); ?>">
                                    <div class="batch-checkbox-group" style="flex: 1; max-width: calc(100% - 180px);">
                                    <?php
                                    if (empty($advisers)) {
                                        echo "<span style='color: #666; font-style: italic;'>No advisers found for <strong>" . htmlspecialchars(amGetProgramLabelFromKey($selectedProgram)) . "</strong>. Please assign advisers to this program first.</span>";
                                    } else {
                                        $assignedUsernames = array_column($assignedAdvisers, 'username');
                                        foreach ($advisers as $adviser) {
                                            $checked = in_array($adviser['username'], $assignedUsernames) ? 'checked' : '';
                                            echo "<label title='Program: " . htmlspecialchars($adviser['program_key']) . "'>";
                                            echo "<input type='checkbox' name='advisers[]' value='" . htmlspecialchars($adviser['username']) . "' $checked>";
                                            echo "<span>" . htmlspecialchars($adviser['full_name']) . "</span>";
                                            echo "</label>";
                                        }
                                    }
                                    ?>

                                    </div>
                                    <div style="display: flex; gap: 6px; align-items: flex-start; flex-shrink: 0;">
                                        <?php if (!empty($advisers)): ?>
                                        <button type="submit" class="submit-btn" title="Update Adviser Assignments" name="direct_submit" value="1">
                                            <i class="fas fa-save" style="color: white; margin-right: 2px; font-size: 10px;"></i>Update
                                        </button>
                                        <?php endif; ?>
                                        <?php if (!empty($assignedAdvisers)): ?>
                                        <button type="submit" class="unassign-btn" name="unassign_batch" value="1" onclick="return confirm('Are you sure you want to unassign all advisers from this batch?');">
                                            <i class="fas fa-times" style="margin-right: 2px; font-size: 10px;"></i>Unassign All
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php $canBulkUpdate = ($dbError === '' && $selectedProgram !== '' && !empty($batches)); ?>
    <div class="table-actions">
        <div class="selection-summary" id="selectionSummary" aria-live="polite">
            <span class="dot" aria-hidden="true"></span>
            <span id="selectionSummaryText">0 advisers selected across 0 batches</span>
        </div>
        <div class="action-buttons">
            <button type="button" class="clear-selection-btn" onclick="clearAllSelections()" <?php echo $canBulkUpdate ? '' : 'disabled'; ?>>
                <i class="fas fa-eraser"></i> Clear Selections
            </button>
            <button type="button" class="bulk-update-btn" onclick="updateAllBatchAssignments()" <?php echo $canBulkUpdate ? '' : 'disabled'; ?>>
                <i class="fas fa-save"></i> Update All Selected
            </button>
        </div>
    </div>
<script>
    function updateSelectionSummary() {
        const forms = document.querySelectorAll('form[action="../batch_update.php"]');
        let selectedAdviserCount = 0;
        let affectedBatchCount = 0;

        forms.forEach((form) => {
            const selected = form.querySelectorAll('input[name="advisers[]"]:checked').length;
            selectedAdviserCount += selected;
            if (selected > 0) {
                affectedBatchCount++;
            }
        });

        const summaryText = document.getElementById('selectionSummaryText');
        if (summaryText) {
            summaryText.textContent = selectedAdviserCount + ' adviser(s) selected across ' + affectedBatchCount + ' batch(es)';
        }
    }

    function clearAllSelections() {
        const hasChecked = document.querySelector('input[name="advisers[]"]:checked');
        if (!hasChecked) {
            return;
        }

        const confirmed = confirm('Clear all selected advisers across every batch?');
        if (!confirmed) {
            return;
        }

        document.querySelectorAll('input[name="advisers[]"]:checked').forEach((checkbox) => {
            checkbox.checked = false;
        });

        updateSelectionSummary();
    }

    function updateAllBatchAssignments() {
        const forms = document.querySelectorAll('form[action="../batch_update.php"]');
        const assignments = {};
        let hasAnySelection = false;

        forms.forEach((form) => {
            const batchInput = form.querySelector('input[name="batch"]');
            if (!batchInput || !batchInput.value) {
                return;
            }

            const selectedAdvisers = Array.from(form.querySelectorAll('input[name="advisers[]"]:checked'))
                .map((checkbox) => checkbox.value)
                .filter((value) => value && value.trim() !== '');

            if (selectedAdvisers.length > 0) {
                hasAnySelection = true;
            }

            assignments[batchInput.value] = selectedAdvisers;
        });

        if (!Object.keys(assignments).length) {
            alert('No batch assignments were found to update.');
            return;
        }

        if (!hasAnySelection) {
            const proceedWithoutSelection = confirm('No advisers are selected. This will clear adviser assignments for all listed batches. Continue?');
            if (!proceedWithoutSelection) {
                return;
            }
        } else {
            const confirmUpdateAll = confirm('Update adviser assignments for all listed batches?');
            if (!confirmUpdateAll) {
                return;
            }
        }

        const bulkForm = document.createElement('form');
        bulkForm.method = 'POST';
        bulkForm.action = '../batch_update_all.php';

        const payloadInput = document.createElement('input');
        payloadInput.type = 'hidden';
        payloadInput.name = 'assignments_json';
        payloadInput.value = JSON.stringify(assignments);
        bulkForm.appendChild(payloadInput);

        const selectedProgram = new URLSearchParams(window.location.search).get('program');
        if (selectedProgram && selectedProgram.trim() !== '') {
            const programInput = document.createElement('input');
            programInput.type = 'hidden';
            programInput.name = 'selected_program';
            programInput.value = selectedProgram;
            bulkForm.appendChild(programInput);
        }

        document.body.appendChild(bulkForm);
        bulkForm.submit();
    }

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

        document.querySelectorAll('input[name="advisers[]"]').forEach((checkbox) => {
            checkbox.addEventListener('change', updateSelectionSummary);
        });

        updateSelectionSummary();
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

    // Custom confirmation dialog for batch updates
    function confirmUpdate(event) {
        event.preventDefault();
        const form = event.target.closest('form');
        
        // Check if any checkboxes are selected
        const checkboxes = form.querySelectorAll('input[name="advisers[]"]:checked');
        
        if (checkboxes.length === 0) {
            alert('Please select at least one adviser to assign');
            return false;
        }
        
        // Create confirmation modal
        const confirmModal = document.createElement('div');
        confirmModal.className = 'modal-overlay';
        confirmModal.innerHTML = `
            <div class="modal-container">
                <span class="modal-close">&times;</span>
                <div class="modal-icon">
                    <i class="fas fa-question-circle" style="color: #206018;"></i>
                </div>
                <div class="modal-title">
                    Confirm Batch Assignment
                </div>
                <div style="margin: 15px 0; color: #666;">
                    Are you sure you want to update the batch assignments for this adviser?<br>
                    <small>Selected batches: ${checkboxes.length}</small>
                </div>
                <div style="margin-top: 20px;">
                    <button class="submit-btn" style="margin-right: 10px;" onclick="submitForm()">Yes, Update</button>
                    <button class="unassign-btn" style="background-color: #6c757d;" onclick="closeModal()">Cancel</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(confirmModal);
        const container = confirmModal.querySelector('.modal-container');
        confirmModal.style.display = 'block';
        setTimeout(() => container.classList.add('active'), 10);

        // Handle close button
        const closeBtn = confirmModal.querySelector('.modal-close');
        closeBtn.onclick = closeModal;

        // Handle click outside
        confirmModal.onclick = (e) => {
            if (e.target === confirmModal) {
                closeModal();
            }
        };

        function closeModal() {
            container.classList.remove('active');
            setTimeout(() => {
                confirmModal.remove();
            }, 300);
        }

        function submitForm() {
            form.submit();
        }

        // Make functions available to onclick handlers
        window.submitForm = submitForm;
        window.closeModal = closeModal;

        return false;
    }
</script>
</body>
</html>






