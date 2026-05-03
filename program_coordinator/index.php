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
        '/api/dashboard/overview',
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
    $programShiftSchemaIssues = psProgramShiftSchemaIssues($conn);
    if (empty($programShiftSchemaIssues)) {
        $coordinatorProgramKeys = psResolveCoordinatorProgramKeys($conn, $_SESSION['username'] ?? '');
        $coordinatorShiftSummary = psGetCoordinatorShiftSummary($conn, $coordinatorProgramKeys);
    } else {
        error_log('Coordinator dashboard loaded without program shift summary: ' . implode(' ', $programShiftSchemaIssues));
    }
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
    .content {
      padding: 28px 30px 34px;
      max-width: 1240px;
      margin: 0 auto;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .page-header {
      padding: 4px 2px 0;
      margin: 0 0 12px;
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

    .option img { width: 40px; height: 40px; margin-bottom: 0; transition: transform 0.3s ease; }
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

    @media (max-width: 768px) {
      .sidebar { transform: translateX(-250px); }
      .sidebar:not(.collapsed) { transform: translateX(0); }
      .main-content { margin-left: 0; width: 100vw; }
      .header { padding: 5px 8px; font-size: 12px; }
      .header img { height: 22px; margin-right: 6px; }
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
      <div class="message-container">
        <span class="message-icon">i</span>
        <span class="message-copy">
          <small>Coordinator Snapshot</small>
          <span><strong><?= (int)($coordinatorShiftSummary['pending'] ?? 0) ?></strong> pending coordinator reviews, <strong><?= (int)($coordinatorShiftSummary['approved'] ?? 0) ?></strong> approved and executed shifts, and <strong><?= (int)($coordinatorShiftSummary['rejected'] ?? 0) ?></strong> rejected in the final stage.</span>
        </span>
      </div>

      <div class="section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Quick Access</h2>
            <p class="section-subtitle">Use the same cleaner dashboard structure as the student view while keeping your coordinator-specific modules front and center.</p>
          </div>
        </div>
        <div class="options">
        <div class="option" onclick="window.location.href='curriculum_management.php'">
          <div class="option-icon"><img src="../pix/curr.png" alt="Curriculum Management"></div>
          <div>
            <label class="option-title">Curriculum Management</label>
            <p class="option-caption">Manage curriculum structures and checklist definitions with a more focused coordinator launch area.</p>
          </div>
        </div>

        <div class="option" onclick="window.location.href='adviser_management.php'">
          <div class="option-icon"><img src="../pix/account.png" alt="Adviser Management"></div>
          <div>
            <label class="option-title">Adviser Management</label>
            <p class="option-caption">Review adviser assignments and keep responsibility mapping organized from one cleaner entry point.</p>
          </div>
        </div>

        <div class="option" onclick="window.location.href='list_of_students.php'">
          <div class="option-icon"><img src="../pix/checklist.png" alt="List of Students"></div>
          <div>
            <label class="option-title">List of Students</label>
            <p class="option-caption">Open student records, checklist pages, and study plans from a more polished dashboard card layout.</p>
          </div>
        </div>

        <div class="option" onclick="window.location.href='program_shift_requests.php'">
          <div class="option-icon"><img src="../pix/update.png" alt="Program Shift Requests"></div>
          <div>
            <label class="option-title">Program Shift Requests</label>
            <p class="option-caption">Handle destination-program decisions and final approvals with a clearer dashboard workflow.</p>
          </div>
        </div>

        <div class="option" onclick="window.location.href='profile.php'">
          <div class="option-icon"><img src="../pix/account.png" alt="Update Profile"></div>
          <div>
            <label class="option-title">Update Profile</label>
            <p class="option-caption">Keep your coordinator profile and contact details updated without leaving the new card-based layout.</p>
          </div>
        </div>

        <div class="option">
          <a href="logout.php">
            <div class="option-icon"><img src="../pix/singout.png" alt="Sign Out"></div>
            <div>
              <label class="option-title">Sign Out</label>
              <p class="option-caption">End the current coordinator session securely after your curriculum and approval work is complete.</p>
            </div>
          </a>
        </div>
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

