<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$coordinator_name = isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : '';

$coordinatorProgramKeys = [];
$coordinatorShiftSummary = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/dashboard/overview',
        [
            'bridge_authorized' => true,
            'role' => 'program_coordinator',
            'username' => $_SESSION['username'] ?? '',
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['summary']) && is_array($bridgeData['summary'])) {
        $coordinatorShiftSummary = $bridgeData['summary'];
        $coordinatorProgramKeys = isset($bridgeData['program_keys']) && is_array($bridgeData['program_keys'])
            ? $bridgeData['program_keys']
            : [];
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $conn = getDBConnection();
    psEnsureProgramShiftTables($conn);
    $coordinatorProgramKeys = psResolveCoordinatorProgramKeys($conn, $_SESSION['username'] ?? '');
    $coordinatorShiftSummary = psGetCoordinatorShiftSummary($conn, $coordinatorProgramKeys);
    closeDBConnection($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Homepage - Program Coordinator</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #333;
      overflow-x: hidden;
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

    .sidebar-header h3 { margin: 0; }
    
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
        border-left: 4px solid transparent;
    }

    .sidebar-menu a:hover {
        background: rgba(255, 255, 255, 0.1);
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
        filter: brightness(0) invert(1);
        flex: 0 0 20px;
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
        line-height: 1.2;
    }

    .main-content {
      margin-left: 250px;
      min-height: calc(100vh - 45px);
      background-color: #f5f5f5;
      width: calc(100vw - 250px);
      transition: margin-left 0.3s ease, width 0.3s ease;
    }

    .main-content.expanded { margin-left: 0; width: 100vw; }
    .content { padding: 30px; }

    .page-header {
      background: white;
      padding: 20px 30px;
      margin: -30px -30px 30px -30px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .page-header h1 {
      position: relative;
      top: 14px;
      left: 10px;
      color: #333;
      font-size: 24px;
      font-weight: 600;
    }

    .options {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 30px;
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

    .option:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.15); border-color: #4CAF50; }
    .option img { width: 80px; height: 80px; margin-bottom: 15px; }
    .option label { font-size: 16px; display: block; font-weight: 600; margin-top: 10px; color: #333; }
    .option a { text-decoration: none; color: inherit; }

    .shift-summary {
      background: #fff;
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

    @media (max-width: 768px) {
      .sidebar { transform: translateX(-250px); }
      .sidebar:not(.collapsed) { transform: translateX(0); }
      .main-content { margin-left: 0; width: 100vw; }
      .header { padding: 5px 8px; font-size: 12px; }
      .header img { height: 22px; margin-right: 6px; }
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

  <div class="sidebar collapsed" id="sidebar">
    <div class="sidebar-header">
      <h3>Program Coordinator Panel</h3>
    </div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php" class="active"><img src="../pix/home1.png" alt="Dashboard" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Modules</div>
                <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum" style="filter: brightness(0) invert(1);"> Curriculum Management</a></li>
                <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers" style="filter: brightness(0) invert(1);"> Adviser Management</a></li>
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

  <div class="main-content expanded" id="mainContent">
    <div class="page-header">
      <h1>Program Coordinator Dashboard</h1>
    </div>
    <div class="content">
      <div class="shift-summary">
        <div class="shift-stat">
          <div class="count"><?php echo (int)($coordinatorShiftSummary['pending'] ?? 0); ?></div>
          <div class="label">Pending Coordinator Review</div>
        </div>
        <div class="shift-stat">
          <div class="count"><?php echo (int)($coordinatorShiftSummary['approved'] ?? 0); ?></div>
          <div class="label">Approved and Executed</div>
        </div>
        <div class="shift-stat">
          <div class="count"><?php echo (int)($coordinatorShiftSummary['rejected'] ?? 0); ?></div>
          <div class="label">Rejected in Final Stage</div>
        </div>
      </div>
      <div class="options">
        <div class="option" onclick="window.location.href='curriculum_management.php'">
          <img src="../pix/curr.png" alt="Curriculum Management">
          <label>Curriculum Management</label>
        </div>

        <div class="option" onclick="window.location.href='adviser_management.php'">
          <img src="../pix/account.png" alt="Adviser Management">
          <label>Adviser Management</label>
        </div>

        <div class="option" onclick="window.location.href='list_of_students.php'">
          <img src="../pix/checklist.png" alt="List of Students">
          <label>List of Students</label>
        </div>

        <div class="option" onclick="window.location.href='program_shift_requests.php'">
          <img src="../pix/update.png" alt="Program Shift Requests">
          <label>Program Shift Requests</label>
        </div>

        <div class="option" onclick="window.location.href='profile.php'">
          <img src="../pix/account.png" alt="Update Profile">
          <label>Update Profile</label>
        </div>

        <div class="option">
          <a href="logout.php">
            <img src="../pix/singout.png" alt="Sign Out">
            <label>Sign Out</label>
          </a>
        </div>
      </div>
    </div>
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
      const logo = document.querySelector('.header img');
      if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target))) {
        sidebar.classList.add('collapsed');
        document.getElementById('mainContent').classList.add('expanded');
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
  </script>
</body>
</html>
