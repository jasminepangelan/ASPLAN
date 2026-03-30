<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Check if the adviser is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';
$csrfToken = getCSRFToken();
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
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            color: #333;
            overflow-x: hidden;
            margin: 0;
            padding: 0;
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
            z-index: 1000;
            display: flex;
            align-items: center;
            box-shadow: 0 4px 20px rgba(32, 96, 24, 0.3);
            backdrop-filter: blur(10px);
            justify-content: space-between;
        }

        .title-content {
            display: flex;
            align-items: center;
        }
        
        .header img {
            height: 30px;
            width: auto;
            margin-right: 15px;
            vertical-align: middle;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
        }
        
        .header span {
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
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            padding: 5px;
        }

        /* Sidebar styling */
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
            min-height: calc(100vh - 32px);
            background-color: #f5f5f5;
            width: calc(100vw - 250px);
            overflow-x: hidden;
            transition: margin-left 0.3s ease, width 0.3s ease;
            padding-top: 32px;
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100vw;
        }
        
        .content {
            position: relative;
            text-align: center;
            margin: 50px 20px 20px;
            animation: fadeInUp 0.6s ease-out;
        }
        
        .content h2 {
            background: linear-gradient(135deg, #206018 0%, #4caf50 100%);
            color: #fff;
            display: inline-block;
            padding: 10px 25px;
            border-radius: 25px;
            font-size: 20px;
            font-weight: 800;
            box-shadow: 0 8px 25px rgba(32, 96, 24, 0.3);
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            letter-spacing: 1px;
            margin-bottom: 20px;
            position: relative;
            top: 0;
            overflow: hidden;
        }
        
        .content h2::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .content h2:hover::before {
            left: 100%;
        }
        
        .table-container {
            margin: -40px auto;
            width: 95%;
            max-width: 1200px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(32, 96, 24, 0.15);
            overflow: hidden;
            border: 1px solid rgba(32, 96, 24, 0.1);
            backdrop-filter: blur(10px);
            animation: slideInUp 0.7s ease-out;
        }
        
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 16px;
            background: transparent;
        }
        
        th, td {
            padding: 18px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(32, 96, 24, 0.1);
            transition: all 0.3s ease;
        }
        
        th {
            background: linear-gradient(135deg, #206018 0%, #2e7d32 100%);
            color: #fff;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            border: none;
            position: relative;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        
        th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, #4caf50, #81c784);
        }
        
        tbody tr {
            transition: all 0.3s ease;
        }
        
        tbody tr:nth-child(even) td {
            background-color: rgba(244, 248, 244, 0.8);
        }
        
        tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(32, 96, 24, 0.15);
        }
        
        tbody tr:hover td {
            background: linear-gradient(135deg, rgba(234, 246, 233, 0.9) 0%, rgba(200, 230, 201, 0.8) 100%);
            color: #1b5e20;
        }
        
        td {
            background-color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
        }
        
        .student-number {
            color: #206018;
            font-weight: 700;
            font-size: 17px;
            letter-spacing: 1px;
            background: linear-gradient(135deg, rgba(32, 96, 24, 0.1) 0%, rgba(76, 175, 80, 0.05) 100%);
            padding: 8px 12px;
            border-radius: 8px;
            border-left: 4px solid #206018;
        }
        
        .action-icons {
            text-align: center;
            display: flex;
            gap: 20px;
            justify-content: center;
            align-items: center;
        }
        
        .action-icons a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            transition: all 0.3s ease;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        
        .action-icons a.approve-btn {
            background: linear-gradient(135deg, #4caf50 0%, #66bb6a 100%);
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
        }
        
        .action-icons a.reject-btn {
            background: linear-gradient(135deg, #f44336 0%, #ef5350 100%);
            box-shadow: 0 4px 15px rgba(244, 67, 54, 0.3);
        }
        
        .action-icons a:hover {
            transform: translateY(-3px) scale(1.1);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }
        
        .action-icons a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }
        
        .action-icons a:hover::before {
            left: 100%;
        }
        
        .action-icons i {
            font-size: 18px;
            color: white;
            z-index: 1;
            position: relative;
        }
        
        .modal {
            display: none !important;
            position: fixed !important;
            z-index: 2000 !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background: rgba(0, 0, 0, 0.6) !important;
            backdrop-filter: blur(5px) !important;
            animation: fadeIn 0.3s ease-in-out !important;
        }
        
        .modal-content {
            margin: 10% auto !important;
            padding: 30px !important;
            border-radius: 20px !important;
            width: 90% !important;
            max-width: 450px !important;
            text-align: center !important;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3) !important;
            animation: slideInDown 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55) !important;
            position: relative !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }
        
        .approve-modal {
            background: linear-gradient(135deg, #4caf50 0%, #66bb6a 100%) !important;
        }
        
        .reject-modal {
            background: linear-gradient(135deg, #f44336 0%, #ef5350 100%) !important;
        }
        
        .modal-icon {
            width: 90px;
            height: 90px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            margin: 0 auto 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 45px;
            color: white;
            font-weight: bold;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
            border: 3px solid rgba(255, 255, 255, 0.3);
            animation: pulse 2s infinite;
        }
        
        .modal-title {
            color: white !important;
            font-size: 28px !important;
            font-weight: 700 !important;
            margin-bottom: 15px !important;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3) !important;
            letter-spacing: 1px !important;
        }
        
        .modal-message {
            color: rgba(255, 255, 255, 0.95) !important;
            font-size: 18px !important;
            margin: 20px 0 30px 0 !important;
            line-height: 1.6 !important;
            text-align: center;
            font-weight: 500;
        }
        
        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .modal-button {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 14px 28px;
            border-radius: 30px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            min-width: 120px;
            backdrop-filter: blur(10px);
        }
        
        .modal-button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        .modal-button.primary {
            background: rgba(255, 255, 255, 0.9);
            color: #333;
            border-color: rgba(255, 255, 255, 0.9);
        }
        
        .modal-button.primary:hover {
            background: white;
            color: #333;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeInUp {
            from { 
                opacity: 0; 
                transform: translateY(30px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        @keyframes slideInUp {
            from { 
                opacity: 0; 
                transform: translateY(50px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
        }
        
        @keyframes slideInDown {
            from { 
                opacity: 0; 
                transform: translateY(-50px) scale(0.9); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0) scale(1); 
            }
        }
        
        @keyframes pulse {
            0%, 100% { 
                transform: scale(1); 
            }
            50% { 
                transform: scale(1.05); 
            }
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1001;
            }
            
            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                width: 100vw;
                padding-top: 32px;
            }
            
            .menu-toggle {
                display: block;
            }

            .header {
                padding: 5px 8px;
                font-size: 12px;
            }

            .header img {
                height: 22px !important;
                margin-right: 6px !important;
            }
            
            .adviser-name {
                font-size: 10px;
                padding: 3px 6px;
            }
            
            .content {
                margin: 20px 15px;
            }
            
            .content h2 {
                font-size: 16px;
                padding: 8px 18px;
            }
            
            .table-container {
                width: 95%;
                margin: 0 auto 15px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            table {
                min-width: 600px;
            }
            
            th {
                padding: 12px 10px;
                font-size: 13px;
            }
            
            td {
                padding: 10px 8px;
                font-size: 13px;
            }
            
            .student-number {
                font-size: 13px;
                padding: 6px 8px;
            }
            
            .action-icons {
                gap: 10px;
            }
            
            .action-icons a {
                width: 38px;
                height: 38px;
                font-size: 14px;
            }
            
            .modal-content {
                width: 90% !important;
                padding: 25px !important;
                margin: 15% auto !important;
            }
            
            .modal-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .modal-button {
                width: 100%;
                max-width: 200px;
            }
            
            .empty-state {
                padding: 40px 20px;
                margin: 20px 15px;
                border-radius: 12px;
            }
            
            .empty-state-icon {
                font-size: 48px;
            }
            
            .empty-state h3 {
                font-size: 1.3rem;
            }
            
            .empty-state p {
                font-size: 0.9rem;
            }

            .empty-state .help-text {
                font-size: 0.85rem;
                padding: 10px;
            }
        }
        
        /* Extra small devices - Card layout */
        @media (max-width: 576px) {
            .header {
                padding: 5px 8px;
                font-size: 11px;
            }

            .header img {
                height: 20px !important;
                margin-right: 5px !important;
            }
            
            .header span {
                font-size: 11px;
            }
            
            .adviser-name {
                font-size: 9px;
                padding: 3px 6px;
            }
            
            .content {
                margin: 15px 10px;
            }
            
            .content h2 {
                font-size: 14px;
                padding: 7px 14px;
            }
            
            .table-container {
                width: 95%;
                margin: 0 auto 15px;
            }
            
            /* Card-style table for mobile */
            table thead {
                display: none;
            }
            
            table, table tbody, table tr, table td {
                display: block;
                width: 100%;
            }
            
            table tr {
                margin-bottom: 15px;
                background: rgba(255, 255, 255, 0.95);
                border-radius: 15px;
                box-shadow: 0 8px 20px rgba(32, 96, 24, 0.15);
                border: 1px solid rgba(32, 96, 24, 0.1);
                overflow: hidden;
            }
            
            table td {
                text-align: right;
                padding: 12px 15px 12px 40%;
                border-bottom: 1px solid rgba(32, 96, 24, 0.1);
                position: relative;
                background: transparent;
                min-height: 45px;
                display: flex;
                align-items: center;
                justify-content: flex-end;
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
                top: 50%;
                transform: translateY(-50%);
                font-weight: 700;
                text-transform: uppercase;
                color: #206018;
                font-size: 11px;
                letter-spacing: 0.5px;
                text-align: left;
            }
            
            .student-number {
                width: auto;
                text-align: right;
            }
            
            .action-icons {
                justify-content: flex-end;
            }
            
            .action-icons a {
                width: 40px;
                height: 40px;
            }

            .empty-state {
                padding: 30px 15px;
                margin: 15px 10px;
                border-radius: 10px;
            }
            
            .empty-state-icon {
                font-size: 40px;
            }
            
            .empty-state h3 {
                font-size: 1.1rem;
                margin-bottom: 10px;
            }
            
            .empty-state p {
                font-size: 0.85rem;
            }

            .empty-state .help-text {
                font-size: 0.8rem;
                padding: 8px;
                margin-top: 12px;
            }
        }
        
        /* Loading animation for table */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
        }
        
        .loading::after {
            content: '';
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #206018;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Empty State Styling */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 15px;
            margin: 20px auto;
            max-width: 600px;
            box-shadow: 0 8px 20px rgba(32, 96, 24, 0.1);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.7;
            animation: float 3s ease-in-out infinite;
        }
        
        .empty-state h3 {
            color: #206018;
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 12px;
        }
        
        .empty-state p {
            color: #666;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 8px;
        }
        
        .empty-state .help-text {
            color: #999;
            font-size: 0.9rem;
            margin-top: 16px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #206018;
            text-align: left;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-8px); }
        }

        @media (max-width: 480px) {
            .header {
                font-size: 10px;
                padding: 4px 6px;
            }

            .header img {
                height: 20px !important;
                margin-right: 4px !important;
            }

            .adviser-name {
                font-size: 8px;
                padding: 2px 5px;
            }

            .empty-state {
                padding: 25px 12px;
                margin: 10px 8px;
            }
            
            .empty-state-icon {
                font-size: 36px;
            }
            
            .empty-state h3 {
                font-size: 1rem;
            }
            
            .empty-state p {
                font-size: 0.8rem;
            }

            .empty-state .help-text {
                font-size: 0.75rem;
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title-content">
            <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar" style="display:inline-flex;align-items:center;justify-content:center;margin-right:8px;border:0;background:transparent;color:#fff;font-size:20px;cursor:pointer;">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;"> ASPLAN</span>
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
                <li><a href="#" class="active"><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
                <li><a href="checklist_eval.php"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
                <li><a href="study_plan_list.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan List</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>

            
            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="content">
            <h2>STUDENT PENDING ACCOUNTS</h2>
        </div>
        <?php
        if (!isset($_SESSION['id'])) {
            echo "<div class='empty-state'><p style='color: red;'>Access denied. Please log in.</p></div>";
            exit;
        }

        $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
        $pendingAccounts = [];
        $loadedFromLaravel = false;

        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser/pending-accounts/list',
                [
                    'adviser_id' => (int) $_SESSION['id'],
                ]
            );

            if (is_array($bridgeData) && array_key_exists('pending_accounts', $bridgeData)) {
                $pendingAccounts = is_array($bridgeData['pending_accounts']) ? $bridgeData['pending_accounts'] : [];
                $loadedFromLaravel = true;
            }
        }

        if (!$loadedFromLaravel) {
            // Get database connection
            $conn = getDBConnection();

            // Get adviser's batches
            $adviser_id = $_SESSION['id'];
            $batch_query = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ?");
            $batch_query->bind_param("i", $adviser_id);
            $batch_query->execute();
            $batch_result = $batch_query->get_result();
            if ($batch_result->num_rows === 0) {
                echo "<div class='empty-state'><p>No batch assigned to this adviser.</p></div>";
                closeDBConnection($conn);
                exit;
            }
            $batches = [];
            while ($row = $batch_result->fetch_assoc()) {
                $batches[] = $row['batch'];
            }
            $batch_query->close();

            if (count($batches) === 0) {
                echo "<div class='empty-state'><p>No batch assigned to this adviser.</p></div>";
                closeDBConnection($conn);
                exit;
            }

            // Build query for all batches
            $placeholders = implode(',', array_fill(0, count($batches), '?'));
            $query = "SELECT student_number AS student_id, last_name, first_name, middle_name FROM student_info WHERE status = 'pending' AND (";
            foreach ($batches as $i => $batch) {
                $query .= ($i > 0 ? " OR " : "") . "student_number LIKE CONCAT(?, '%')";
            }
            $query .= ")";
            $stmt = $conn->prepare($query);
            // Bind all batch values
            $stmt->bind_param(str_repeat('s', count($batches)), ...$batches);
            $stmt->execute();
            $result = $stmt->get_result();

            $pendingAccounts = [];
            $displayed = [];
            while ($row = $result->fetch_assoc()) {
                if (in_array($row['student_id'], $displayed)) {
                    continue;
                }
                $displayed[] = $row['student_id'];
                $pendingAccounts[] = [
                    'student_id' => $row['student_id'],
                    'last_name' => $row['last_name'],
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'],
                ];
            }

            $stmt->close();
            closeDBConnection($conn);
        }

        if (count($pendingAccounts) > 0) {
            // Show table with pending accounts
            echo '<div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>STUDENT NUMBER</th>
                        <th>FULL NAME</th>
                        <th>ACTION</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($pendingAccounts as $row) {
                $fullName = htmlspecialchars($row['last_name'] . ', ' . $row['first_name'] . (!empty($row['middle_name']) ? ' ' . $row['middle_name'] : ''));
                echo "
                    <tr>
                        <td data-label='Student ID' class='student-number'>" . htmlspecialchars($row['student_id']) . "</td>
                        <td data-label='Student Name'>{$fullName}</td>
                        <td data-label='Actions' class='action-icons'>
                            <a href='javascript:void(0);' class='approve-btn' onclick='showApproveModal(" . json_encode($row['student_id']) . ")' title='Approve Account'>
                                <i class='fas fa-check'></i>
                            </a>
                            <a href='javascript:void(0);' class='reject-btn' onclick='showRejectModal(" . json_encode($row['student_id']) . ")' title='Reject Account'>
                                <i class='fas fa-times'></i>
                            </a>
                        </td>
                    </tr>
                ";
            }
            
            echo '</tbody>
            </table>
        </div>';
        } else {
            // Show empty state outside of table
            echo '
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <h3>All Clear!</h3>
                    <p>No pending account approvals for your assigned batches.</p>
                    <div class="help-text">
                        <strong>💡 Note:</strong> New student registrations for your batches will appear here automatically.
                    </div>
                </div>
            ';
        }
        ?>
    </div>
    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
      <div class="modal-content approve-modal">
        <div class="modal-icon">
          <i class="fas fa-check"></i>
        </div>
        <div class="modal-title">Approve Account</div>
        <div class="modal-message">Are you sure you want to approve this student account? This action will grant them access to the system.</div>
        <div class="modal-buttons">
          <button class="modal-button primary" id="confirmApprove">Yes, Approve</button>
          <button class="modal-button" onclick="closeModal('approveModal')">Cancel</button>
        </div>
      </div>
    </div>
    
    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
      <div class="modal-content reject-modal">
        <div class="modal-icon">
          <i class="fas fa-times"></i>
        </div>
        <div class="modal-title">Reject Account</div>
        <div class="modal-message">Are you sure you want to reject this student account? This action cannot be undone.</div>
        <div class="modal-buttons">
          <button class="modal-button primary" id="confirmReject">Yes, Reject</button>
          <button class="modal-button" onclick="closeModal('rejectModal')">Cancel</button>
        </div>
      </div>
    </div>
    
    <!-- Success Modal -->
    <div id="successModal" class="modal">
      <div class="modal-content approve-modal">
        <div class="modal-icon">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="modal-title">Success!</div>
        <div class="modal-message">The account has been processed successfully.</div>
        <div class="modal-buttons">
          <button class="modal-button primary" onclick="closeModal('successModal')">OK</button>
        </div>
      </div>
    </div>
        <form id="accountActionForm" method="post" style="display:none;">
            <input type="hidden" id="actionStudentId" name="student_id" value="">
            <input type="hidden" id="actionCsrfToken" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        </form>
    <script>
        function submitAccountAction(endpoint, studentId) {
            const form = document.getElementById('accountActionForm');
            document.getElementById('actionStudentId').value = studentId;
            document.getElementById('actionCsrfToken').value = <?php echo json_encode($csrfToken); ?>;
            form.action = endpoint;
            form.submit();
        }

    // Enhanced modal functionality with better UX
    function showApproveModal(studentId) {
      const modal = document.getElementById('approveModal');
      modal.style.setProperty('display', 'block', 'important');
      
      // Add click outside to close
      modal.onclick = function(event) {
        if (event.target === modal) {
          closeModal('approveModal');
        }
      };
      
      document.getElementById('confirmApprove').onclick = function() {
        // Add loading state
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        this.disabled = true;
        
        setTimeout(() => {
                    submitAccountAction('approve_account.php', studentId);
        }, 500);
      };
    }
    
    function showRejectModal(studentId) {
      const modal = document.getElementById('rejectModal');
      modal.style.setProperty('display', 'block', 'important');
      
      // Add click outside to close
      modal.onclick = function(event) {
        if (event.target === modal) {
          closeModal('rejectModal');
        }
      };
      
      document.getElementById('confirmReject').onclick = function() {
        // Add loading state
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        this.disabled = true;
        
        setTimeout(() => {
                    submitAccountAction('reject_account.php', studentId);
        }, 500);
      };
    }
    
    function closeModal(modalId) {
      const modal = document.getElementById(modalId);
      modal.style.setProperty('display', 'none', 'important');
      
      // Reset button states
      const confirmBtns = modal.querySelectorAll('.modal-button.primary');
      confirmBtns.forEach(btn => {
        btn.disabled = false;
        if (modalId === 'approveModal') {
          btn.innerHTML = 'Yes, Approve';
        } else if (modalId === 'rejectModal') {
          btn.innerHTML = 'Yes, Reject';
        } else {
          btn.innerHTML = 'OK';
        }
      });
    }
    
    function showSuccessModal() {
      document.getElementById('successModal').style.setProperty('display', 'block', 'important');
      setTimeout(function() {
        closeModal('successModal');
      }, 2000);
    }
    
    // Enhanced page load functionality
    window.addEventListener('DOMContentLoaded', function() {
      // Add smooth animations to table rows
      const tableRows = document.querySelectorAll('tbody tr');
      tableRows.forEach((row, index) => {
        row.style.animationDelay = `${index * 0.1}s`;
        row.style.animation = 'fadeInUp 0.6s ease-out forwards';
        row.style.opacity = '0';
      });
      
      // Handle URL parameters for success messages
      const urlParams = new URLSearchParams(window.location.search);
      const message = urlParams.get('message');
      
      if (message) {
        if (message.includes('approved') || message.includes('success')) {
          showSuccessModal();
        }
        
        // Clean URL after showing message
        setTimeout(function() {
          const newUrl = window.location.pathname;
          window.history.replaceState({}, document.title, newUrl);
        }, 2100);
      }
      
      // Add keyboard navigation
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
          closeModal('approveModal');
          closeModal('rejectModal');
          closeModal('successModal');
        }
      });
      
      // Add loading state for page navigation
      const backBtn = document.querySelector('.back-btn');
      if (backBtn) {
        backBtn.addEventListener('click', function(e) {
          e.preventDefault();
          this.innerHTML = this.innerHTML.replace('Back', '<i class="fas fa-spinner fa-spin"></i> Loading...');
          setTimeout(() => {
            window.location.href = this.href;
          }, 300);
        });
      }
      
      // Add tooltips for action buttons
      const actionButtons = document.querySelectorAll('.action-icons a');
      actionButtons.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
          const tooltip = this.getAttribute('title');
          if (tooltip) {
            // You can add custom tooltip implementation here if needed
          }
        });
      });
    });
    
    // Sidebar toggle functionality
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
    
    // Add CSS for initial hidden state of table rows
    const style = document.createElement('style');
    style.textContent = `
      tbody tr {
        opacity: 0;
      }
    `;
    document.head.appendChild(style);
    </script>
</body>
</html>
