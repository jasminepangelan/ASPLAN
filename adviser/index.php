<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Check if the user is already logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$adviser_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

$adviserProgramKeys = [];
$adviserShiftSummary = ['pending' => 0, 'forwarded' => 0, 'rejected' => 0];
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/dashboard/overview',
        [
            'bridge_authorized' => true,
            'role' => 'adviser',
            'user_id' => $_SESSION['id'] ?? null,
            'username' => $_SESSION['username'] ?? '',
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['summary']) && is_array($bridgeData['summary'])) {
        $adviserShiftSummary = $bridgeData['summary'];
        $adviserProgramKeys = isset($bridgeData['program_keys']) && is_array($bridgeData['program_keys'])
            ? $bridgeData['program_keys']
            : [];
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $conn = getDBConnection();
    psEnsureProgramShiftTables($conn);
    $adviserProgramKeys = psResolveAdviserProgramKeys($conn, $_SESSION['id'] ?? null, $_SESSION['username'] ?? '');
    $adviserShiftSummary = psGetAdviserShiftSummary($conn, $adviserProgramKeys);
    closeDBConnection($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Homepage - Adviser</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      color: #333;
      overflow-x: hidden;
    }

    /* Header styling */
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

    .icon-bar {
      position: absolute;
      top: 10px;
      right: 15px;
      display: flex;
      gap: 15px;
    }

    .icon-bar img {
      width: 25px;
      height: 25px;
      cursor: pointer;
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
      min-height: calc(100vh - 46px);
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
      padding: 30px;
      max-width: 100%;
      box-sizing: border-box;
    }

    .page-header {
      background: white;
      padding: 20px 30px;
      margin: -30px -30px 30px -30px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .page-header h1 {
      position: relative;
      top: 14px; 
      left: 10px;
      margin: 0;
      color: #333;
      font-size: 24px;
      font-weight: 600;
    }

    .options {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 30px;
      max-width: 100%;
      width: 100%;
    }

    .option {
      background: white;
      padding: 30px 20px;
      border-radius: 10px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      border: 2px solid transparent;
    }

    .option:hover {
      transform: translateY(-5px);
      box-shadow: 0 5px 20px rgba(0,0,0,0.15);
      border-color: #4CAF50;
    }

    .option img {
      width: 80px;
      height: 80px;
      margin-bottom: 15px;
      transition: transform 0.3s ease;
    }

    .option:hover img {
      transform: scale(1.1);
    }

    .option label {
      font-size: 16px;
      display: block;
      font-weight: 600;
      margin-top: 10px;
      color: #333;
    }

    .option a {
      text-decoration: none;
      color: inherit;
    }

    .shift-summary {
      background: #ffffff;
      border-radius: 12px;
      border: 1px solid #d8e3d8;
      box-shadow: 0 2px 10px rgba(0,0,0,0.08);
      padding: 16px;
      margin-bottom: 24px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
    }

    .shift-stat {
      background: #f4faf3;
      border: 1px solid #d4e7d3;
      border-radius: 10px;
      padding: 12px;
    }

    .shift-stat .count {
      font-size: 22px;
      font-weight: 700;
      color: #206018;
    }

    .shift-stat .label {
      margin-top: 4px;
      font-size: 12px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      color: #3b5339;
      font-weight: 600;
    }

    /* Responsive design */
    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
        z-index: 1000;
      }
      
      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }
      
      .main-content {
        margin-left: 0;
        width: 100vw;
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

      .page-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
      }
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
    }
  </style>
</head>
<body>
  <!-- Header Bar -->
  <div class="header" style="position: relative;">
    <div class="title-content">
      <button type="button" class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" style="cursor: pointer;" onclick="toggleSidebar()">
      <span style="color: #d9e441;">ASPLAN</span>
    </div>
    <div class="adviser-name"><?= $adviser_name ; echo " | Adviser " ?></div>
    <div class="icon-bar">
      </div>
  </div>

  <!-- Sidebar Navigation -->
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h3>Adviser Panel</h3>
    </div>
    <ul class="sidebar-menu">
      <div class="menu-group">
        <div class="menu-group-title">Dashboard</div>
        <li><a href="#" class="active"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Student Management</div>
        <li><a href="pending_accounts.php"><img src="../pix/pending.png" alt="Pending"> Pending Accounts</a></li>
        <li><a href="checklist_eval.php"><img src="../pix/checklist.png" alt="Student List"> List of Students</a></li>
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
  <div class="main-content">
    <div class="page-header">
      <h1>Adviser Dashboard</h1>
    </div>
    <div class="content">
      <div class="shift-summary">
        <div class="shift-stat">
          <div class="count"><?php echo (int)($adviserShiftSummary['pending'] ?? 0); ?></div>
          <div class="label">Pending Adviser Review</div>
        </div>
        <div class="shift-stat">
          <div class="count"><?php echo (int)($adviserShiftSummary['forwarded'] ?? 0); ?></div>
          <div class="label">Forwarded to Coordinator</div>
        </div>
        <div class="shift-stat">
          <div class="count"><?php echo (int)($adviserShiftSummary['rejected'] ?? 0); ?></div>
          <div class="label">Rejected in Adviser Stage</div>
        </div>
      </div>
      <div class="options">
        <!-- Pending Accounts Option -->
        <div class="option" onclick="window.location.href='pending_accounts.php'">
          <img src="../pix/update.png" alt="Update Checklist Icon">
          <label>Pending Accounts</label>
        </div>
        <!-- Student's Checklist Option -->
        <div class="option" onclick="window.location.href='checklist_eval.php'">
          <img src="../pix/checklist.png" alt="Preregistration Form Icon">
          <label>List of Student</label>
        </div>
        <!-- Study Plan Option -->
        <div class="option" onclick="window.location.href='study_plan_list.php'">
          <img src="../pix/studyplan.png" alt="Study Plan Icon">
          <label>Study Plan List</label>
        </div>
        <!-- Program Shift Requests Option -->
        <div class="option" onclick="window.location.href='program_shift_requests.php'">
          <img src="../pix/update.png" alt="Program Shift Icon">
          <label>Program Shift Requests</label>
        </div>
        <!-- Sign Out Option -->
        <div class="option">
          <a href="logout.php">
            <img src="../pix/singout.png" alt="Sign Out Icon">
            <label>Sign Out</label>
          </a>
        </div>
      </div>
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
  </script>
</body>
</html>
