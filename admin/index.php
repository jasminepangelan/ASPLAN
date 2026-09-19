<?php
require_once __DIR__ . '/../config/config.php';

// Check if the user is already logged in
if (!isset($_SESSION['admin_username'])) {
    header("Location: login.php");
    exit();
}

$admin_name = isset($_SESSION['admin_full_name']) ? htmlspecialchars($_SESSION['admin_full_name']) : '';

$conn = getDBConnection();

// 1. Total Registered Students
$resTotalStudents = $conn->query("SELECT COUNT(*) AS c FROM student_info");
$totalStudents = $resTotalStudents ? $resTotalStudents->fetch_assoc()['c'] : 0;

// 2. Pending Accounts
$resPending = $conn->query("SELECT COUNT(*) AS c FROM student_info WHERE status = 'pending'");
$pendingAccounts = $resPending ? $resPending->fetch_assoc()['c'] : 0;

// 3. Pending Program Shifts
$resShifts = $conn->query("SELECT COUNT(*) AS c FROM program_shift_requests WHERE status LIKE 'pending%'");
$pendingShifts = $resShifts ? $resShifts->fetch_assoc()['c'] : 0;

// 4. Active Advisers
$resAdvisers = $conn->query("SELECT COUNT(*) AS c FROM adviser");
$activeAdvisers = $resAdvisers ? $resAdvisers->fetch_assoc()['c'] : 0;

// 5. Chart: Registration Trend
$chartTrendLabels = [];
$chartTrendData = [];
$resTrend = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_yr, COUNT(*) AS count 
    FROM student_info 
    WHERE created_at IS NOT NULL
    GROUP BY month_yr 
    ORDER BY month_yr ASC 
    LIMIT 6
");
if ($resTrend) {
    while ($row = $resTrend->fetch_assoc()) {
        $chartTrendLabels[] = date('M Y', strtotime($row['month_yr'] . '-01'));
        $chartTrendData[] = (int)$row['count'];
    }
}

// 6. Chart: Students by Program
$chartProgramLabels = [];
$chartProgramData = [];
$resPrograms = $conn->query("
    SELECT program, COUNT(*) AS count 
    FROM student_info 
    WHERE program IS NOT NULL AND program != '' AND status = 'approved'
    GROUP BY program
    ORDER BY count DESC
    LIMIT 5
");
if ($resPrograms) {
    while ($row = $resPrograms->fetch_assoc()) {
        $short = $row['program'];
        if (strpos($row['program'], 'Computer Science') !== false) $short = 'Comp. Science';
        elseif (strpos($row['program'], 'Information Tech') !== false) $short = 'Info. Tech';
        elseif (strpos($row['program'], 'Computer Engineering') !== false) $short = 'Comp. Eng.';
        elseif (strpos($row['program'], 'Hospitality') !== false) $short = 'Hospitality';
        elseif (strpos($row['program'], 'Business') !== false) $short = 'Business Admin';
        elseif (strpos($row['program'], 'Education') !== false) $short = 'Education';
        
        $chartProgramLabels[] = $short;
        $chartProgramData[] = (int)$row['count'];
    }
}

// 7. Recent Activity Feed
$recentActivities = [];
$resActivity = $conn->query("
    SELECT action_type, summary, created_at 
    FROM admin_audit_logs 
    ORDER BY created_at DESC 
    LIMIT 6
");
if ($resActivity) {
    while ($row = $resActivity->fetch_assoc()) {
        $recentActivities[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      background: url('../img/drone_cvsu_2.png') no-repeat center center fixed;
      background-size: cover;
      font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      color: #333;
      overflow-x: hidden;
      padding-top: 45px;
    }

    .main-header {
      width: 100%;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
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
      cursor: pointer;
    }

    .main-header span {
      font-size: 1.2rem;
      font-weight: 800;
      letter-spacing: 0.6px;
    }

    .admin-info {
      font-size: 15px;
      font-weight: 600;
      color: white;
      background: rgba(255, 255, 255, 0.15);
      padding: 4px 12px;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.3);
      line-height: 1.2;
      white-space: nowrap;
    }

    /* Sidebar styling */
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

    .sidebar-header h3 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
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

    /* Main content styling */
    .main-content {
      margin-left: 250px;
      min-height: calc(100vh - 45px);
      background-color: #f5f5f5;
      width: calc(100vw - 250px);
      overflow-x: hidden;
      transition: margin-left 0.3s ease, width 0.3s ease;
    }

    .main-content.expanded {
      margin-left: 0;
      width: 100vw;
    }

    .content {
      padding: 28px 30px 34px;
      max-width: 1240px;
      margin: 0 auto;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .page-header {
      padding: 0;
      margin: 0 0 10px;
    }

    .page-header h1 {
      margin: 0;
      color: #163417;
      font-size: 34px;
      font-weight: 800;
      letter-spacing: -0.6px;
      line-height: 1.08;
    }

    .message-container {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 15px;
      color: #17421a;
      font-weight: 700;
      letter-spacing: 0.2px;
      margin: 0;
      padding: 16px 18px;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(243, 250, 245, 0.96) 100%);
      border-radius: 16px;
      width: 100%;
      border: 1px solid rgba(32, 96, 24, 0.12);
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.06);
    }

    .message-icon {
      width: 42px;
      height: 42px;
      flex: 0 0 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(228, 244, 229, 0.95) 0%, rgba(211, 237, 214, 0.95) 100%);
      color: #206018;
      font-size: 18px;
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
    }

    .message-copy {
      display: flex;
      flex-direction: column;
      gap: 3px;
    }

    .message-copy small {
      font-size: 12px;
      color: #5f765f;
      font-weight: 600;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }

    .message-copy strong { color: #163417; }

    .section-card {
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(247, 250, 248, 0.98) 100%);
      border: 1px solid rgba(32, 96, 24, 0.12);
      border-radius: 22px;
      padding: 22px;
      box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
    }

    .section-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 14px;
      margin-bottom: 18px;
    }

    .section-title {
      margin: 0;
      font-size: 26px;
      color: #163417;
      font-weight: 800;
      letter-spacing: -0.4px;
    }

    .section-subtitle {
      margin: 6px 0 0;
      font-size: 14px;
      line-height: 1.6;
      color: #58705a;
      max-width: 720px;
    }

    .options {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      width: 100%;
    }

    .option {
      position: relative;
      overflow: hidden;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.99) 0%, rgba(245, 249, 246, 0.98) 100%);
      border: 1px solid rgba(22, 79, 20, 0.12);
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
      padding: 22px 20px 20px;
      border-radius: 18px;
      text-align: left;
      cursor: pointer;
      transition: transform 0.28s ease, box-shadow 0.28s ease, border-color 0.28s ease;
      min-height: 210px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .option::before {
      content: '';
      position: absolute;
      inset: 0 auto auto 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, rgba(22, 79, 20, 0.95) 0%, rgba(76, 175, 80, 0.88) 100%);
      opacity: 0.95;
    }

    .option:hover {
      transform: translateY(-8px);
      box-shadow: 0 22px 36px rgba(15, 23, 42, 0.14);
      border-color: rgba(22, 79, 20, 0.24);
    }

    .option-icon {
      width: 74px;
      height: 74px;
      border-radius: 22px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, rgba(234, 247, 236, 0.98) 0%, rgba(221, 241, 226, 0.98) 100%);
      border: 1px solid rgba(22, 79, 20, 0.08);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
      margin-bottom: 18px;
    }

    .option img {
      width: 40px;
      height: 40px;
      margin-bottom: 0;
      transition: transform 0.3s ease;
      filter: none;
    }

    .option:hover img { transform: scale(1.08); }

    .option-title {
      font-size: 22px;
      display: block;
      font-weight: 800;
      color: #173318;
      letter-spacing: -0.3px;
      margin: 0 0 8px;
    }

    .option-caption {
      font-size: 13px;
      line-height: 1.6;
      color: #5c6f5d;
      margin: 0;
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
      .sidebar {
        transform: translateX(-250px);
      }

      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }

      .main-header span {
        font-size: 1.05rem;
      }

      .content { padding: 12px; gap: 12px; }
      .page-header { padding: 2px 0 0; margin: 0 0 10px; }
      .page-header h1 { font-size: 28px; }
      .message-container { padding: 14px; align-items: flex-start; }
      .section-card { padding: 18px; }
      .section-head { align-items: flex-start; }
      .options { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .option { min-height: 188px; padding: 18px 16px 16px; }
      .option-icon { width: 64px; height: 64px; border-radius: 18px; }
      .option img { width: 34px; height: 34px; }
      .option-title { font-size: 18px; }
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

  <style>
    /* --- NEW DASHBOARD CSS --- */
    .dashboard-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 20px;
      margin-top: 20px;
    }
    
    .metric-cards {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 15px;
      margin-bottom: 20px;
    }
    
    .metric-card {
      background: #fff;
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.05);
      display: flex;
      flex-direction: column;
      position: relative;
      overflow: hidden;
    }
    
    .metric-value {
      font-size: 28px;
      font-weight: 800;
      color: #173318;
      margin-bottom: 5px;
    }
    
    .metric-label {
      font-size: 13px;
      color: #5c6f5d;
      font-weight: 600;
    }
    
    .metric-icon {
      position: absolute;
      top: 20px;
      right: 20px;
      width: 42px;
      height: 42px;
      background: rgba(45, 143, 34, 0.08);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
    }

    .metric-icon img {
      width: 24px;
      height: 24px;
      filter: invert(36%) sepia(87%) saturate(583%) hue-rotate(69deg) brightness(97%) contrast(89%); /* Make icon green */
    }
    
    .dashboard-panel {
      background: #fff;
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.05);
      margin-bottom: 20px;
      height: 100%;
    }
    
    .panel-title {
      font-size: 16px;
      font-weight: 700;
      color: #173318;
      margin-top: 0;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .chart-container {
      position: relative;
      height: 250px;
      width: 100%;
    }

    .quick-access-list {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    
    .quick-access-item {
      display: flex;
      align-items: center;
      padding: 12px 15px;
      background: #f9fbf9;
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.2s ease;
      border: 1px solid rgba(22, 79, 20, 0.05);
    }
    
    .quick-access-item:hover {
      background: #f0f7f1;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    }
    
    .qa-icon {
      width: 36px;
      height: 36px;
      background: linear-gradient(135deg, rgba(234, 247, 236, 0.98), rgba(221, 241, 226, 0.98));
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 12px;
    }
    
    .qa-icon img { width: 20px; height: 20px; }
    
    .qa-text {
      font-weight: 600;
      color: #173318;
      font-size: 14px;
    }
    
    .activity-feed {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    
    .activity-item {
      display: flex;
      gap: 12px;
    }
    
    .activity-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #8b5cf6;
      margin-top: 5px;
      flex-shrink: 0;
    }
    .activity-dot.purple { background: #8b5cf6; }
    .activity-dot.orange { background: #f97316; }
    .activity-dot.green { background: #10b981; }
    .activity-dot.blue { background: #3b82f6; }
    
    .activity-content {
      font-size: 13px;
      flex-grow: 1;
    }
    
    .activity-title {
      font-weight: 600;
      color: #333;
      margin-bottom: 3px;
    }
    
    .activity-time {
      color: #888;
      font-size: 11px;
    }

    @media (max-width: 1024px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        .metric-cards {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .metric-cards {
            grid-template-columns: 1fr;
        }
        .quick-access-list {
            grid-template-columns: 1fr;
        }
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
  $activeAdminPage = 'index';
  $adminSidebarCollapsed = false;
  require __DIR__ . '/../includes/admin_sidebar.php';
  ?>


  <!-- Main Content -->
  <div class="main-content">
    <div class="content">
      <div class="page-header">
        <h1>Dashboard Overview</h1>
      </div>
      
      <!-- Top Metrics -->
      <div class="metric-cards">
        <div class="metric-card">
            <div class="metric-icon"><img src="../pix/generic_user.svg" alt="Students"></div>
            <div class="metric-value"><?php echo number_format($totalStudents); ?></div>
            <div class="metric-label">Total Students</div>
        </div>
        <div class="metric-card">
            <div class="metric-icon"><img src="../pix/account.png" alt="Pending"></div>
            <div class="metric-value"><?php echo number_format($pendingAccounts); ?></div>
            <div class="metric-label">Pending Approvals</div>
        </div>
        <div class="metric-card">
            <div class="metric-icon"><img src="../pix/update.png" alt="Shifts"></div>
            <div class="metric-value"><?php echo number_format($pendingShifts); ?></div>
            <div class="metric-label">Pending Program Shifts</div>
        </div>
        <div class="metric-card">
            <div class="metric-icon"><img src="../pix/curr.png" alt="Advisers"></div>
            <div class="metric-value"><?php echo number_format($activeAdvisers); ?></div>
            <div class="metric-label">Active Advisers</div>
        </div>
      </div>

      <!-- Middle Charts -->
      <div class="dashboard-grid">
        <div class="dashboard-panel">
            <h3 class="panel-title">Registration Trends (Last 6 Months)</h3>
            <div class="chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
        
        <div class="dashboard-panel">
            <h3 class="panel-title">Students by Program</h3>
            <div class="chart-container">
                <canvas id="programChart"></canvas>
            </div>
        </div>
      </div>

      <!-- Bottom Layout -->
      <div class="dashboard-grid">
        <div class="dashboard-panel">
            <h3 class="panel-title">Quick Access</h3>
            <div class="quick-access-list">
                <div class="quick-access-item" onclick="window.location.href='account_module.php'">
                    <div class="qa-icon"><img src="../pix/account.png" alt="Icon"></div>
                    <div class="qa-text">User Management</div>
                </div>
                <div class="quick-access-item" onclick="window.location.href='list_of_students.php'">
                    <div class="qa-icon"><img src="../pix/generic_user.svg" alt="Icon"></div>
                    <div class="qa-text">Registered Students</div>
                </div>
                <div class="quick-access-item" onclick="window.location.href='program_shift.php'">
                    <div class="qa-icon"><img src="../pix/update.png" alt="Icon"></div>
                    <div class="qa-text">Program Shift</div>
                </div>
                <div class="quick-access-item" onclick="window.location.href='curriculum_management.php'">
                    <div class="qa-icon"><img src="../pix/curr.png" alt="Icon"></div>
                    <div class="qa-text">Curriculum Management</div>
                </div>
                <div class="quick-access-item" onclick="window.location.href='programs.php'">
                    <div class="qa-icon"><img src="../pix/update.png" alt="Icon"></div>
                    <div class="qa-text">Programs Catalog</div>
                </div>
                <div class="quick-access-item" onclick="window.location.href='account_approval_settings.php'">
                    <div class="qa-icon"><img src="../pix/set.png" alt="Icon"></div>
                    <div class="qa-text">System Settings</div>
                </div>
            </div>
        </div>
        
        <div class="dashboard-panel">
            <h3 class="panel-title">Recent Activity</h3>
            <div class="activity-feed">
                <?php if (empty($recentActivities)): ?>
                    <p style="color: #888; font-size: 13px;">No recent activity.</p>
                <?php else: ?>
                    <?php 
                    $colors = ['purple', 'orange', 'green', 'blue'];
                    foreach ($recentActivities as $index => $act): 
                        $color = $colors[$index % count($colors)];
                        // format time
                        $time = date('M d, g:i A', strtotime($act['created_at']));
                    ?>
                    <div class="activity-item">
                        <div class="activity-dot <?php echo $color; ?>"></div>
                        <div class="activity-content">
                            <div class="activity-title"><?php echo htmlspecialchars($act['summary']); ?></div>
                            <div class="activity-time"><?php echo htmlspecialchars($act['action_type']); ?> &bull; <?php echo $time; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Existing Sidebar scripts
    function toggleSidebar() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.querySelector('.main-content');
      sidebar.classList.toggle('collapsed');
      mainContent.classList.toggle('expanded');
    }

    document.addEventListener('click', function(event) {
      const sidebar = document.getElementById('sidebar');
      const logo = document.querySelector('.main-header img');
      if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target))) {
        sidebar.classList.add('collapsed');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) mainContent.classList.add('expanded');
      }
    });

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

    window.addEventListener('resize', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.querySelector('.main-content');
      if (window.innerWidth > 768) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
      } else {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('expanded');
      }
    });

    // Chart.js Library loading and Initialization
    const chartScript = document.createElement('script');
    chartScript.src = '../js/chart.min.js';
    chartScript.onload = function() {
        try {
            var trendEl = document.getElementById('trendChart');
            if (trendEl) {
                new Chart(trendEl.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($chartTrendLabels ?: ['No Data']); ?>,
                        datasets: [{
                            label: 'New Registrations',
                            data: <?php echo json_encode($chartTrendData ?: [0]); ?>,
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#8b5cf6',
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }
        } catch (e) {
            var tc = document.getElementById('trendChart');
            if (tc) tc.parentNode.innerHTML = '<div style="color:red; padding:20px;">Trend Error: ' + e.message + '</div>';
        }

        try {
            var progEl = document.getElementById('programChart');
            if (progEl) {
                new Chart(progEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($chartProgramLabels ?: ['No Data']); ?>,
                        datasets: [{
                            data: <?php echo json_encode($chartProgramData ?: [1]); ?>,
                            backgroundColor: ['#8b5cf6', '#f97316', '#10b981', '#3b82f6', '#f43f5e'],
                            borderWidth: 2,
                            borderColor: '#fff',
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '65%',
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 }, padding: 16 } }
                        }
                    }
                });
            }
        } catch (e) {
            var pc = document.getElementById('programChart');
            if (pc) pc.parentNode.innerHTML = '<div style="color:red; padding:20px;">Prog Error: ' + e.message + '</div>';
        }
    };
    chartScript.onerror = function() {
        var tc = document.getElementById('trendChart');
        if (tc) tc.parentNode.innerHTML = '<div style="color:red; padding:20px;">Failed to load Chart.js. Please verify that the local file exists at ../js/chart.min.js</div>';
    };
    document.head.appendChild(chartScript);
  </script>
</body>
</html>









