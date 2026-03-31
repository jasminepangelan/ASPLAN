<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$coordinator_name = isset($_SESSION['full_name']) ? htmlspecialchars((string)$_SESSION['full_name']) : 'Program Coordinator';
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
            --brand-300: #86ca77;
            --surface-100: #f8f9fa;
            --surface-200: #eef2ef;
            --surface-300: #f4f8f3;
            --text-muted: #647067;
            --panel-bg: #ffffff;
            --panel-border: #dbe5db;
            --panel-shadow: 0 12px 28px rgba(32, 96, 24, 0.08);
            --panel-shadow-soft: 0 4px 12px rgba(21, 43, 21, 0.08);
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

        .modal-open {
            overflow: hidden;
        }

        .confirm-modal {
            width: min(92vw, 560px);
            min-width: 0;
            text-align: left;
            padding: 28px 30px 24px;
        }

        .confirm-modal .modal-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(255, 244, 221, 0.96) 0%, rgba(255, 235, 205, 0.92) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0 16px;
            box-shadow: inset 0 0 0 1px rgba(217, 119, 6, 0.14);
        }

        .confirm-modal .modal-icon i {
            color: #d97706;
            font-size: 26px;
        }

        .confirm-modal .modal-title {
            color: #173b17;
            font-size: 24px;
            line-height: 1.2;
            margin-bottom: 10px;
        }

        .confirm-modal-body {
            color: #4f5e54;
            font-size: 15px;
            line-height: 1.65;
            margin-bottom: 18px;
        }

        .confirm-modal-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 999px;
            background: #edf7ee;
            color: #206018;
            border: 1px solid #cde6ce;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 22px;
        }

        .confirm-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .confirm-action-btn {
            border: none;
            border-radius: 12px;
            padding: 12px 18px;
            min-width: 150px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .confirm-action-btn:hover {
            transform: translateY(-1px);
        }

        .confirm-secondary-btn {
            background: #f3f6f4;
            color: #274d22;
            border: 1px solid #d7e2d7;
            box-shadow: 0 4px 10px rgba(21, 43, 21, 0.05);
        }

        .confirm-secondary-btn:hover {
            background: #e9efe9;
        }

        .confirm-primary-btn {
            background: linear-gradient(135deg, #2e7d32 0%, #1f6f2a 100%);
            color: #fff;
            box-shadow: 0 8px 18px rgba(32, 96, 24, 0.18);
        }

        .confirm-primary-btn:hover {
            box-shadow: 0 10px 20px rgba(32, 96, 24, 0.22);
        }

        .confirm-destructive-btn {
            background: linear-gradient(135deg, #e74c3c 0%, #c62828 100%);
            color: #fff;
            box-shadow: 0 8px 18px rgba(198, 40, 40, 0.18);
        }

        .confirm-destructive-btn:hover {
            box-shadow: 0 10px 20px rgba(198, 40, 40, 0.22);
        }

        .success-modal {
            width: min(92vw, 640px);
            min-width: 0;
            text-align: left;
            padding: 28px 30px 24px;
        }

        .success-modal .modal-icon {
            width: 62px;
            height: 62px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(236, 253, 245, 0.98) 0%, rgba(220, 252, 231, 0.96) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0 16px;
            box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.14);
        }

        .success-modal .modal-icon i {
            color: #16a34a;
            font-size: 28px;
        }

        .success-modal .modal-title {
            color: #173b17;
            font-size: 24px;
            line-height: 1.2;
            margin-bottom: 10px;
        }

        .success-modal-body {
            color: #4f5e54;
            font-size: 15px;
            line-height: 1.65;
            margin-bottom: 18px;
        }

        .success-modal-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 999px;
            background: #edf7ee;
            color: #206018;
            border: 1px solid #cde6ce;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 22px;
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
            background: radial-gradient(circle at top left, #f8fbf8 0%, #f2f6f1 45%, #edf4ec 100%);
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
            padding: 15px 15px;
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
            padding-bottom: 20px;
        }

        .content.expanded {
            margin-left: 24px;
        }
        
        .page-header {
            text-align: center;
            background: var(--panel-bg);
            padding: 18px 20px;
            border-radius: 14px;
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
            border-radius: 14px;
            box-shadow: var(--panel-shadow);
            overflow: hidden;
            border: 1px solid var(--panel-border);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .table-container:hover {
            box-shadow: 0 16px 30px rgba(32, 96, 24, 0.12);
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
            background: #f8fcf8;
        }
        
        tr:hover td {
            background: #eef8ee;
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
            padding: 8px;
            background: #ffffff;
            border-radius: 10px;
            border: 1px solid rgba(32, 96, 24, 0.08);
            box-shadow: var(--panel-shadow-soft);
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
            padding: 6px 10px;
            border-radius: 14px;
            transition: all 0.3s ease;
            background: var(--surface-300);
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
            border-color: var(--brand-300);
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
            padding: 9px 18px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #2f8a35 0%, #206018 100%);
            box-shadow: 0 8px 16px rgba(32, 96, 24, 0.24);
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
            border-radius: 14px;
            padding: 12px 14px;
            box-shadow: var(--panel-shadow);
        }

        .program-filter-card {
            margin: 0 0 16px 0;
            padding: 14px;
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 14px;
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
            box-shadow: 0 10px 20px rgba(32, 96, 24, 0.3);
            background: linear-gradient(135deg, #379d3d 0%, #256b1f 100%);
        }

        .clear-selection-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 14px;
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
            background: linear-gradient(135deg, #39a63f 0%, #248129 100%);
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
            background: linear-gradient(135deg, #44b349 0%, #2b8f2f 100%);
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
            background: linear-gradient(135deg, #d14848 0%, #b73434 100%);
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
            background: linear-gradient(135deg, #de5757 0%, #c03c3c 100%);
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
        <div class="admin-info"><?php echo $coordinator_name; ?> | Program Coordinator</div>
    </div>

    <!-- Sidebar Navigation -->
    <div class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header">
            <h3>Program Coordinator Panel</h3>
        </div>
                <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Modules</div>
                <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum" style="filter: brightness(0) invert(1);"> Curriculum Management</a></li>
                <li><a href="adviser_management.php" class="active"><img src="../pix/account.png" alt="Advisers" style="filter: brightness(0) invert(1);"> Adviser Management</a></li>
                <li><a href="list_of_students.php"><img src="../pix/checklist.png" alt="Students" style="filter: brightness(0) invert(1);"> List of Students</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift" style="filter: brightness(0) invert(1);"> Program Shift Requests</a></li>
                <li><a href="profile.php"><img src="../pix/account.png" alt="Profile" style="filter: brightness(0) invert(1);"> Update Profile</a></li>
            </div>



            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out" style="filter: brightness(0) invert(1);"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="content" id="mainContent">
        <div class="page-header">
            <h2><i class="fas fa-users-cog"></i> Adviser Management</h2>
            <p class="subtitle">Manage adviser batch assignments and responsibilities</p>
        </div>
    <!-- Modal for Success Message -->
    <div id="successModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-container success-modal" role="alertdialog" aria-modal="true" aria-labelledby="successTitle">
            <button type="button" class="modal-close" id="successClose" aria-label="Close success notification">&times;</button>
            <div class="modal-icon" aria-hidden="true">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="modal-title" id="successTitle">Adviser assignments updated</div>
            <div class="success-modal-body" id="modalMessage">
                Your adviser batch updates were saved successfully.
            </div>
            <div class="success-modal-meta">
                <i class="fas fa-shield-alt"></i>
                Changes have been applied to the selected batches.
            </div>
            <div class="confirm-modal-actions" style="justify-content: flex-end;">
                <button type="button" class="confirm-action-btn confirm-primary-btn" id="successDismiss">Done</button>
            </div>
        </div>
    </div>
    <div id="bulkConfirmModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-container confirm-modal" role="dialog" aria-modal="true" aria-labelledby="bulkConfirmTitle">
            <button type="button" class="modal-close" id="bulkConfirmClose" aria-label="Close confirmation">&times;</button>
            <div class="modal-icon" aria-hidden="true">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="modal-title" id="bulkConfirmTitle">Update adviser assignments?</div>
            <div class="confirm-modal-body" id="bulkConfirmBody">
                This will update adviser assignments for all listed batches.
            </div>
            <div class="confirm-modal-meta" id="bulkConfirmMeta">
                Review your selections before continuing.
            </div>
            <div class="confirm-modal-actions">
                <button type="button" class="confirm-action-btn confirm-secondary-btn" id="bulkConfirmCancel">Cancel</button>
                <button type="button" class="confirm-action-btn confirm-primary-btn" id="bulkConfirmProceed">Update assignments</button>
            </div>
        </div>
    </div>
    <?php
    if (isset($_GET['message'])) {
        echo "<script>
            window.__successModalMessage = " . json_encode((string)$_GET['message']) . ";
        </script>";
    }
    if (isset($_GET['error'])) {
        echo "<div style='text-align: center; color: red; font-weight: bold; margin: 10px 0;'>" . htmlspecialchars($_GET['error']) . "</div>";
    }

    $host = 'localhost';
    $db = 'osas_db';
    $user = 'root';
    $pass = '';

    $selectedProgram = '';
    $availablePrograms = [];
    $batches = [];
    $advisers = [];
    $batchAssignments = [];
    $usedBatchFallback = false;
    $dbError = '';

    // DEBUG MODE
    if (isset($_GET['debug'])) {
        echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; margin: 20px; border-radius: 8px; font-family: monospace; font-size: 12px;">';
        echo '<h3>🛠 DEBUG INFO</h3>';
        
        echo '<strong>Selected Program:</strong> ' . htmlspecialchars($selectedProgram) . '<br>';
        echo '<strong>Available Programs:</strong><br>';
        foreach ($availablePrograms as $k => $v) {
            echo '&nbsp;&nbsp;' . htmlspecialchars($k) . ' → ' . htmlspecialchars($v) . '<br>';
        }
        
        echo '<strong>Raw Adviser Programs:</strong><br>';
        $debugStmt = $conn->query("SELECT DISTINCT TRIM(program) as prog FROM adviser WHERE program IS NOT NULL ORDER BY prog");
        while ($row = $debugStmt->fetch_assoc()) {
            $norm = normalizeProgramKey($row['prog']);
            echo '&nbsp;&nbsp;' . htmlspecialchars($row['prog']) . ' → normalized: ' . htmlspecialchars($norm) . '<br>';
        }
        
        echo '<strong>Adviser Count:</strong> ' . count($advisers) . '<br>';
        foreach ($advisers as $adv) {
            echo '&nbsp;&nbsp;' . htmlspecialchars($adv['full_name']) . ' (' . htmlspecialchars($adv['program_key']) . ')<br>';
        }
        
        echo '<strong>Batch Count:</strong> ' . count($batches) . '</div>';
    }

    function acronymFromPhrase($text) {
        $cleaned = strtoupper(trim((string)$text));
        if ($cleaned === '') {
            return '';
        }

        $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $tokens = explode(' ', $cleaned);
        $skip = ['OF', 'IN', 'AND', 'THE', 'A', 'AN', 'MAJOR', 'PROGRAM'];
        $result = '';

        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $skip, true)) {
                continue;
            }
            $result .= substr($token, 0, 1);
        }

        return $result;
    }

    function normalizeProgramKey($programName) {
        $programName = trim((string)$programName);
        if ($programName === '') {
            return '';
        }

        $normalized = strtoupper(preg_replace('/\s+/', ' ', $programName));

        if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN|ABENG|ABPSYCH|ABCOMM)\b/', $normalized, $codeMatch)) {
            $baseCode = strtoupper($codeMatch[1]);
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
            $baseCode = 'BS' . acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
            $baseCode = 'BSED';
        } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
            $baseCode = 'BEED';
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE', '', $normalized));
            $baseCode = 'BS' . acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF ARTS') !== false) {
            $subject = trim(str_replace('BACHELOR OF ARTS', '', $normalized));
            $baseCode = 'AB' . acronymFromPhrase($subject);
        } else {
            $baseCode = strtoupper($programName);
        }

        $majorKey = '';
        if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
            $majorKey = acronymFromPhrase($majorMatch[1]);
        }

        if ($majorKey !== '') {
            return $baseCode . '-' . $majorKey;
        }

        return $baseCode;
    }

    function getProgramLabelFromKey($programKey) {
        $programKey = trim((string)$programKey);
        if ($programKey === '') {
            return $programKey;
        }

        $parts = explode('-', $programKey, 2);
        if (count($parts) === 2 && $parts[1] !== '') {
            return $parts[0] . ' - ' . $parts[1];
        }

        return $programKey;
    }

    function extractProgramKeys($programText) {
        $programText = trim((string)$programText);
        if ($programText === '') {
            return [];
        }

        $normalized = strtoupper((string)preg_replace('/\s+/', ' ', $programText));
        $keys = [];

        if (strpos($normalized, 'BSBA') !== false) {
            if (strpos($normalized, 'MARKETING MANAGEMENT') !== false || preg_match('/\bBSBA\s*-\s*MM\b|\bBSBA\s+MM\b|\bMM\b/', $normalized)) {
                $keys['BSBA-MM'] = true;
            }
            if (strpos($normalized, 'HUMAN RESOURCE MANAGEMENT') !== false || preg_match('/\bBSBA\s*-\s*HRM\b|\bBSBA\s+HRM\b|\bHRM\b/', $normalized)) {
                $keys['BSBA-HRM'] = true;
            }
        }

        $segments = preg_split('/\s*(?:,|;|\/|\||&|\band\b)\s*/i', $programText) ?: [$programText];
        foreach ($segments as $segment) {
            $key = normalizeProgramKey((string)$segment);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        if (empty($keys)) {
            $key = normalizeProgramKey($programText);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_values(array_keys($keys));
    }

    function resolveCoordinatorProgramKeys(PDO $conn, $username) {
        $username = trim((string)$username);
        if ($username === '') {
            return [];
        }

        $keys = [];
        $tables = ['program_coordinator', 'program_coordinators'];
        foreach ($tables as $table) {
            $check = $conn->prepare("SHOW TABLES LIKE ?");
            $check->execute([$table]);
            if (!$check->fetchColumn()) {
                continue;
            }

            $col = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE 'program'");
            $col->execute();
            if (!$col->fetchColumn()) {
                continue;
            }

            $stmt = $conn->prepare("SELECT TRIM(program) AS program FROM `$table` WHERE username = ?");
            $stmt->execute([$username]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$row || !isset($row['program'])) {
                    continue;
                }
                foreach (extractProgramKeys((string)$row['program']) as $programKey) {
                    $keys[$programKey] = true;
                }
            }
        }

        $fallback = $conn->prepare("SELECT TRIM(program) AS program FROM adviser WHERE username = ?");
        $fallback->execute([$username]);
        while ($fallbackRow = $fallback->fetch(PDO::FETCH_ASSOC)) {
            if (!$fallbackRow || !isset($fallbackRow['program'])) {
                continue;
            }
            foreach (extractProgramKeys((string)$fallbackRow['program']) as $programKey) {
                $keys[$programKey] = true;
            }
        }

        return array_values(array_keys($keys));
    }

    function resolveScopedSelectedProgram($requestedProgram, array $allowedPrograms) {
        if (empty($allowedPrograms)) {
            return '';
        }

        $requestedProgram = normalizeProgramKey((string)$requestedProgram);
        if ($requestedProgram !== '' && in_array($requestedProgram, $allowedPrograms, true)) {
            return $requestedProgram;
        }

        return (string)($allowedPrograms[0] ?? '');
    }

    $bridgeLoaded = false;
    $coordinatorPrograms = [];
    if (getenv('USE_LARAVEL_BRIDGE') === '1') {
        $bridgeData = postLaravelJsonBridge(
            'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser-management/overview',
            [
                'bridge_authorized' => true,
                'user_type' => $_SESSION['user_type'] ?? '',
                'username' => $_SESSION['username'] ?? '',
                'selected_program' => $_GET['program'] ?? '',
            ]
        );

        if (is_array($bridgeData) && !empty($bridgeData['success'])) {
            $selectedProgram = (string) ($bridgeData['selected_program'] ?? '');
            $coordinatorPrograms = isset($bridgeData['coordinator_programs']) && is_array($bridgeData['coordinator_programs'])
                ? $bridgeData['coordinator_programs']
                : [];
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
            $dbError = htmlspecialchars((string) ($bridgeData['message'] ?? 'Failed to load adviser management overview.'));
        }
    }

    if (!$bridgeLoaded) {
        try {
        $conn = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $coordinatorPrograms = resolveCoordinatorProgramKeys($conn, (string)$_SESSION['username']);
        $selectedProgram = resolveScopedSelectedProgram((string)($_GET['program'] ?? ''), $coordinatorPrograms);
        foreach ($coordinatorPrograms as $programKey) {
            $availablePrograms[$programKey] = getProgramLabelFromKey($programKey);
        }

        if ($selectedProgram !== '') {
            $batchQuery = "SELECT DISTINCT LEFT(student_number, 4) as batch, TRIM(program) AS program
                           FROM student_info
                           WHERE student_number IS NOT NULL
                             AND student_number != ''
                           ORDER BY batch DESC";
            $batchStmt = $conn->prepare($batchQuery);
            $batchStmt->execute();
            while ($batchRow = $batchStmt->fetch(PDO::FETCH_ASSOC)) {
                if (normalizeProgramKey($batchRow['program']) === $selectedProgram) {
                    $batches[] = $batchRow['batch'];
                }
            }

            // Prevent duplicate batch rows when multiple program label variants
            // normalize to the same selected program.
            if (!empty($batches)) {
                $batches = array_values(array_unique($batches, SORT_STRING));
                rsort($batches, SORT_STRING);
            }

            // Fallback: if no student batches match the selected program,
            // show all batches so advisers can still be assigned.
            if (empty($batches)) {
                $fallbackBatchQuery = "SELECT DISTINCT LEFT(student_number, 4) as batch
                                       FROM student_info
                                       WHERE student_number IS NOT NULL
                                         AND student_number != ''
                                       ORDER BY batch DESC";
                $fallbackBatchStmt = $conn->prepare($fallbackBatchQuery);
                $fallbackBatchStmt->execute();
                while ($fallbackRow = $fallbackBatchStmt->fetch(PDO::FETCH_ASSOC)) {
                    $batches[] = $fallbackRow['batch'];
                }
                if (!empty($batches)) {
                    $batches = array_values(array_unique($batches, SORT_STRING));
                    rsort($batches, SORT_STRING);
                }
                $usedBatchFallback = !empty($batches);
            }
        }

        // Filter advisers by selected program using PHP normalization
        $adviserQuery = "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, username, TRIM(program) AS program
                         FROM adviser 
                         WHERE program IS NOT NULL AND TRIM(program) != ''
                         ORDER BY first_name, last_name";
        $adviserStmt = $conn->prepare($adviserQuery);
        $adviserStmt->execute();
        while ($adviserRow = $adviserStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($selectedProgram === '' || normalizeProgramKey($adviserRow['program']) === $selectedProgram) {
                $advisers[] = [
                    'id' => $adviserRow['id'],
                    'full_name' => $adviserRow['full_name'],
                    'username' => $adviserRow['username'],
                    'program_key' => normalizeProgramKey($adviserRow['program'])
                ];
            }
        }

        $assignmentQuery = "SELECT ab.batch, a.username, CONCAT(a.first_name, ' ', a.last_name) as full_name, TRIM(a.program) AS program
                            FROM adviser_batch ab
                            INNER JOIN adviser a ON ab.adviser_id = a.id";
        $assignmentQuery .= " ORDER BY ab.batch DESC";
        $assignmentStmt = $conn->prepare($assignmentQuery);
        $assignmentStmt->execute();

        while ($row = $assignmentStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($selectedProgram !== '' && normalizeProgramKey($row['program']) !== $selectedProgram) {
                continue;
            }

            $batch = (string)$row['batch'];
            if (!isset($batchAssignments[$batch])) {
                $batchAssignments[$batch] = [];
            }

            $batchAssignments[$batch][] = [
                'username' => $row['username'],
                'full_name' => htmlspecialchars($row['full_name'])
            ];
        }
    } catch (PDOException $e) {
        $dbError = "Database error: " . htmlspecialchars($e->getMessage());
    }
    }
    ?>

    <div class="program-filter-card">
        <div class="program-filter-note">
            <?php if ($selectedProgram !== ''): ?>
                Managing adviser assignments for your program: <strong><?php echo htmlspecialchars(getProgramLabelFromKey($selectedProgram)); ?></strong>.
                <?php if ($usedBatchFallback): ?>
                    No matching student batches were found for this program, so all available batches are shown.
                <?php endif; ?>
            <?php else: ?>
                Program is not configured for your coordinator account. Contact admin to set your program.
            <?php endif; ?>
        </div>
        <?php if (count($availablePrograms) > 1): ?>
            <form method="get" style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <label for="programSelect" style="font-weight:600;color:#206018;">Program Scope</label>
                <select id="programSelect" name="program" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid #cfe0cf;border-radius:8px;min-width:260px;">
                    <?php foreach ($availablePrograms as $programKey => $programLabel): ?>
                        <option value="<?php echo htmlspecialchars((string)$programKey); ?>" <?php echo $selectedProgram === (string)$programKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)$programLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
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
            } elseif ($selectedProgram === '') {
                echo "<tr><td colspan='2' style='text-align:center; padding:40px 0; color:#206018; font-size:18px; font-weight:600; background:rgba(255,255,255,0.85); border-radius:8px;'>Program is not configured for this coordinator account.</td></tr>";
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
                                    <input type="hidden" name="redirect_to" value="program_coordinator/adviser_management.php">
                                    <div class="batch-checkbox-group" style="flex: 1; max-width: calc(100% - 180px);">
                                    <?php
                                    if (empty($advisers)) {
                                        echo "<span style='color: #666; font-style: italic;'>No advisers found for <strong>" . htmlspecialchars(getProgramLabelFromKey($selectedProgram)) . "</strong>. Please assign advisers to this program first.</span>";
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

    let successModalTimer = null;

    function closeSuccessModal() {
        const modal = document.getElementById('successModal');
        const container = modal ? modal.querySelector('.modal-container') : null;
        const closeBtn = document.getElementById('successClose');
        const dismissBtn = document.getElementById('successDismiss');

        if (successModalTimer) {
            clearTimeout(successModalTimer);
            successModalTimer = null;
        }

        if (container) {
            container.classList.remove('active');
        }

        document.body.classList.remove('modal-open');

        setTimeout(() => {
            if (modal) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
            }
            if (closeBtn) closeBtn.onclick = null;
            if (dismissBtn) dismissBtn.onclick = null;
        }, 220);
    }

    function showSuccessModal(message) {
        const modal = document.getElementById('successModal');
        const container = modal ? modal.querySelector('.modal-container') : null;
        const msg = document.getElementById('modalMessage');
        const closeBtn = document.getElementById('successClose');
        const dismissBtn = document.getElementById('successDismiss');

        if (!modal || !container || !msg || !closeBtn || !dismissBtn) {
            return;
        }

        msg.textContent = message || 'Your adviser batch updates were saved successfully.';
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        requestAnimationFrame(() => {
            container.classList.add('active');
        });

        closeBtn.onclick = closeSuccessModal;
        dismissBtn.onclick = closeSuccessModal;
        modal.onclick = (event) => {
            if (event.target === modal) {
                closeSuccessModal();
            }
        };

        if (successModalTimer) {
            clearTimeout(successModalTimer);
        }

        successModalTimer = setTimeout(closeSuccessModal, 2200);
    }

    function openBulkConfirmModal(options) {
        const modal = document.getElementById('bulkConfirmModal');
        const container = modal ? modal.querySelector('.modal-container') : null;
        const title = document.getElementById('bulkConfirmTitle');
        const body = document.getElementById('bulkConfirmBody');
        const meta = document.getElementById('bulkConfirmMeta');
        const cancelBtn = document.getElementById('bulkConfirmCancel');
        const confirmBtn = document.getElementById('bulkConfirmProceed');
        const closeBtn = document.getElementById('bulkConfirmClose');

        if (!modal || !container || !title || !body || !meta || !cancelBtn || !confirmBtn || !closeBtn) {
            return Promise.resolve(window.confirm(options && options.fallbackMessage ? options.fallbackMessage : 'Continue with this action?'));
        }

        return new Promise((resolve) => {
            let escHandler = null;

            const closeModal = (result) => {
                if (escHandler) {
                    document.removeEventListener('keydown', escHandler);
                }

                modal.onclick = null;
                cancelBtn.onclick = null;
                confirmBtn.onclick = null;
                closeBtn.onclick = null;

                container.classList.remove('active');
                document.body.classList.remove('modal-open');

                setTimeout(() => {
                    modal.style.display = 'none';
                }, 220);

                resolve(result);
            };

            title.textContent = options.title || 'Update adviser assignments?';
            body.textContent = options.body || 'This will update adviser assignments for all listed batches.';
            meta.textContent = options.meta || 'Review your selections before continuing.';
            confirmBtn.textContent = options.confirmLabel || 'Continue';
            confirmBtn.className = 'confirm-action-btn ' + (options.destructive ? 'confirm-destructive-btn' : 'confirm-primary-btn');

            modal.style.display = 'block';
            document.body.classList.add('modal-open');
            requestAnimationFrame(() => {
                container.classList.add('active');
            });

            escHandler = (event) => {
                if (event.key === 'Escape') {
                    closeModal(false);
                }
            };

            cancelBtn.onclick = () => closeModal(false);
            confirmBtn.onclick = () => closeModal(true);
            closeBtn.onclick = () => closeModal(false);
            modal.onclick = (event) => {
                if (event.target === modal) {
                    closeModal(false);
                }
            };

            document.addEventListener('keydown', escHandler);
            setTimeout(() => confirmBtn.focus(), 0);
        });
    }

    async function clearAllSelections() {
        const hasChecked = document.querySelector('input[name="advisers[]"]:checked');
        if (!hasChecked) {
            return;
        }

        const confirmed = await openBulkConfirmModal({
            title: 'Clear adviser selections?',
            body: 'This will remove every adviser selection from the current batch list.',
            meta: 'All checked advisers will be cleared.',
            confirmLabel: 'Clear selections',
            destructive: true,
            fallbackMessage: 'Clear all selected advisers across every batch?'
        });
        if (!confirmed) {
            return;
        }

        document.querySelectorAll('input[name="advisers[]"]:checked').forEach((checkbox) => {
            checkbox.checked = false;
        });

        updateSelectionSummary();
    }

    async function updateAllBatchAssignments() {
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

        const batchCount = Object.keys(assignments).length;
        const confirmed = await openBulkConfirmModal({
            title: hasAnySelection ? 'Update adviser assignments?' : 'Clear all adviser assignments?',
            body: hasAnySelection
                ? 'This will save the current adviser selections for every listed batch.'
                : 'No advisers are currently selected, so this will clear adviser assignments for every listed batch.',
            meta: hasAnySelection
                ? `${batchCount} batch${batchCount === 1 ? '' : 'es'} will be reviewed and updated.`
                : `${batchCount} batch${batchCount === 1 ? '' : 'es'} will be cleared.`,
            confirmLabel: hasAnySelection ? 'Update assignments' : 'Clear assignments',
            destructive: !hasAnySelection,
            fallbackMessage: hasAnySelection
                ? 'Update adviser assignments for all listed batches?'
                : 'No advisers are selected. This will clear adviser assignments for all listed batches. Continue?'
        });

        if (!confirmed) {
            return;
        }

        const bulkForm = document.createElement('form');
        bulkForm.method = 'POST';
        bulkForm.action = '../batch_update_all.php';

        const payloadInput = document.createElement('input');
        payloadInput.type = 'hidden';
        payloadInput.name = 'assignments_json';
        payloadInput.value = JSON.stringify(assignments);
        bulkForm.appendChild(payloadInput);

        const selectedProgram = <?php echo json_encode($selectedProgram); ?>;
        if (selectedProgram && selectedProgram.trim() !== '') {
            const programInput = document.createElement('input');
            programInput.type = 'hidden';
            programInput.name = 'selected_program';
            programInput.value = selectedProgram;
            bulkForm.appendChild(programInput);
        }

        const redirectInput = document.createElement('input');
        redirectInput.type = 'hidden';
        redirectInput.name = 'redirect_to';
        redirectInput.value = 'program_coordinator/adviser_management.php';
        bulkForm.appendChild(redirectInput);

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

        if (window.__successModalMessage) {
            showSuccessModal(window.__successModalMessage);
        }
    });

    // Handle responsive behavior
    window.addEventListener('resize', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (window.innerWidth > 768) {
            // Reset to desktop view
            if(sidebar) sidebar.classList.remove('collapsed');
            if(mainContent) mainContent.classList.remove('expanded');
        } else {
            // On mobile, keep sidebar collapsed
            if(sidebar) sidebar.classList.add('collapsed');
            if(mainContent) mainContent.classList.add('expanded');
        }
    });
</script>
</body>
</html>




