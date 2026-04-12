<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/academic_hold_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Check if the user is logged in
if (!isset($_SESSION['student_id'])) {
  header("Location: ../index.php");
    exit();
}

// Get database connection to fetch fresh data
$student_id = $_SESSION['student_id'];
$last_name = htmlspecialchars($_SESSION['last_name'] ?? '');
$first_name = htmlspecialchars($_SESSION['first_name'] ?? '');
$middle_name = htmlspecialchars($_SESSION['middle_name'] ?? '');
$picture = resolveScopedPictureSrc($_SESSION['picture'] ?? '', '../', 'pix/anonymous.jpg');
$academicHold = ['active' => false, 'title' => '', 'message' => '', 'courses' => []];
$bridgeLoaded = false;

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/dashboard/overview',
        [
            'bridge_authorized' => true,
            'role' => 'student',
            'student_id' => $student_id,
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        if (isset($bridgeData['student']) && is_array($bridgeData['student'])) {
            $row = $bridgeData['student'];
            $last_name = htmlspecialchars((string) ($row['last_name'] ?? $last_name));
            $first_name = htmlspecialchars((string) ($row['first_name'] ?? $first_name));
            $middle_name = htmlspecialchars((string) ($row['middle_name'] ?? $middle_name));
            $picturePath = (string) ($row['picture'] ?? '');
            if ($picturePath !== '') {
                $picture = resolveScopedPictureSrc($picturePath, '../', 'pix/anonymous.jpg');
            }
        }
        $bridgeLoaded = true;
    }
}

if (!$bridgeLoaded) {
    $conn = getDBConnection();
    psEnsureProgramShiftTables($conn);

    $query = $conn->prepare("SELECT last_name, first_name, middle_name, picture FROM student_info WHERE student_number = ?");
    $query->bind_param("s", $student_id);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_name = htmlspecialchars($row['last_name'] ?? '');
        $first_name = htmlspecialchars($row['first_name'] ?? '');
        $middle_name = htmlspecialchars($row['middle_name'] ?? '');
        $picture = resolveScopedPictureSrc($row['picture'] ?? '', '../', 'pix/anonymous.jpg');
    }

    $academicHold = ahsGetStudentAcademicHold($conn, $student_id);
    closeDBConnection($conn);
} else {
    $holdConn = getDBConnection();
    $academicHold = ahsGetStudentAcademicHold($holdConn, $student_id);
    closeDBConnection($holdConn);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Homepage</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    body {
      background: url('../pix/school.jpg') no-repeat center center fixed;
      background-size: cover;
      font-family: 'Segoe UI', Tahoma, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      color: #333;
      overflow: hidden;
      height: 105vh;
    }

    /* Title bar styling */
    .title-bar {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #ffffffff;
      padding: 5px 15px;
      text-align: left;
      font-size: 18px;
      font-weight: 800;
      position: sticky;
      top: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      z-index: 1000;
    }

    .title-content {
      display: flex;
      align-items: center;
    }

    .student-info {
      font-size: 16px;
      font-weight: 600;
      color: #facc41;
      font-family: 'Segoe UI', Arial, sans-serif;
      letter-spacing: 0.5px;
      background: rgba(250, 204, 65, 0.15);
      padding: 5px 15px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      border: 1px solid rgba(250, 204, 65, 0.3);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .student-info img {
      width: 27px !important;
      height: 27px !important;
      border-radius: 50%;
      object-fit: cover;
    }

    .title-bar img {
      height: 32px;
      width: auto;
      margin-right: 12px;
      vertical-align: middle;
    }

    /* Sidebar styling */
    .sidebar {
      width: 250px;
      height: calc(100vh - 38px);
      background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
      color: white;
      position: fixed;
      left: 0;
      top: 38px;
      padding: 20px 0;
      box-shadow: 2px 0 5px rgba(0,0,0,0.1);
      overflow-y: hidden;
      transition: transform 0.3s ease;
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
      min-height: calc(100vh - 38px);
      background-color: rgba(245, 245, 245, 0.95);
      width: calc(100vw - 250px);
      overflow-x: hidden;
      overflow-y: auto;
      transition: margin-left 0.3s ease, width 0.3s ease;
    }

    .main-content.expanded {
      margin-left: 0;
      width: 100vw;
    }

    .content {
      padding: 24px 30px 30px;
      max-width: 1240px;
      margin: 0 auto;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      gap: 18px;
      align-items: stretch;
    }

    .dashboard-intro {
      display: flex;
      flex-direction: column;
      gap: 14px;
      width: 100%;
    }

    .section-card {
      background: rgba(255, 255, 255, 0.97);
      border: 1px solid rgba(32, 96, 24, 0.14);
      border-radius: 14px;
      padding: 18px;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
    }

    .section-title {
      margin: 0 0 14px;
      font-size: 15px;
      color: #1d3f1b;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    .quick-actions-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      width: 100%;
    }

    .page-header {
      background: rgba(255, 255, 255, 0.95);
      padding: 20px 30px;
      margin: -30px -30px 30px -30px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .page-header h1 {
      position: relative;
      top: 13px;
      left: 10px;
      margin: 0;
      color: #333;
      font-size: 24px;
      font-weight: 600;
    }

    .option-container {
      background-color: rgba(255, 255, 255, 0.95);
      border: 1px solid rgba(32, 96, 24, 0.16);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      padding: 24px 18px;
      border-radius: 10px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
      width: 100%;
      min-width: 0;
      max-width: none;
      margin: 0;
      box-sizing: border-box;
    }

    .option-container:hover {
      transform: translateY(-5px);
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
    }

    .option-container img {
      width: 62px;
      height: 58px;
      margin-bottom: 8px;
      transition: transform 0.3s ease;
    }

    .option-container:hover img {
      transform: scale(1.1);
    }

    .option-container label {
      font-size: 16px;
      display: block;
      font-weight: bold;
      margin-top: 5px;
      color: #333;
      cursor: pointer;
    }

    .message-container {
      text-align: center;
      font-size: 16px;
      color: #206018;
      font-weight: 700;
      letter-spacing: 0.5px;
      margin: 0;
      padding: 14px 18px;
      background: rgba(255, 255, 255, 0.95);
      border-radius: 10px;
      width: 100%;
      border: 1px solid rgba(32, 96, 24, 0.12);
    }

    .academic-hold-banner {
      width: 100%;
      background: linear-gradient(135deg, #fff4f4, #ffe3e3);
      border: 1px solid #f3b5b5;
      border-left: 6px solid #c62828;
      border-radius: 14px;
      padding: 16px 18px;
      box-shadow: 0 4px 14px rgba(198, 40, 40, 0.12);
      color: #5d1b1b;
    }

    .academic-hold-banner strong {
      display: block;
      font-size: 17px;
      color: #8b1e1e;
      margin-bottom: 6px;
    }

    .academic-hold-banner p {
      margin: 0;
      font-size: 14px;
      line-height: 1.5;
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
    }

    .menu-toggle:hover {
      background: rgba(255, 255, 255, 0.22);
    }

    /* Responsive adjustments */
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
        display: inline-flex;
      }

      .content {
        padding: 12px;
        gap: 12px;
      }

      .page-header {
        padding: 15px 20px;
        margin: -15px -15px 15px -15px;
      }

      .page-header h1 {
        font-size: 20px;
        left: 0;
        top: 0;
      }

      .option-container {
        padding: 16px 12px;
        width: 100%;
        max-width: 100%;
      }

      .option-container img {
        width: 60px;
        height: 60px;
      }

      .option-container label {
        font-size: 16px;
      }

      .message-container {
        font-size: 13px;
        padding: 12px;
        max-width: 100%;
        margin: 0;
      }

      .quick-actions-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .section-card {
        padding: 14px;
      }

      .student-info {
        font-size: 10px;
        padding: 3px 6px;
      }

      .student-info img {
        width: 18px !important;
        height: 18px !important;
      }

      .title-bar {
        font-size: 12px;
        padding: 5px 8px;
      }

      .title-bar img {
        height: 22px !important;
        margin-right: 6px !important;
      }
    }

    @media (max-width: 480px) {
      .quick-actions-grid {
        grid-template-columns: 1fr;
      }

      .title-bar {
        font-size: 10px;
        padding: 4px 6px;
      }

      .student-info span {
        font-size: 8px;
      }

      .student-info {
        padding: 2px 5px;
      }
    }


  </style>
</head>
<body>
  <!-- Title Bar -->
  <div class="title-bar">
    <div class="title-content">
      <button type="button" class="menu-toggle" id="menuToggleBtn" onclick="toggleSidebar()" aria-label="Toggle sidebar" title="Toggle sidebar">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" style="height: 32px; width: auto; margin-right: 12px; cursor: pointer;" onclick="toggleSidebar()">
      <span style="color: #d9e441; font-weight: 800;">ASPLAN</span>
    </div>
    <div class="student-info">
      <img src="<?= $picture ?>" alt="Profile Picture">
      <span><?= $last_name . ', ' . $first_name . (!empty($middle_name) ? ' ' . $middle_name : '') ?> | Student</span>
    </div>
  </div>

  <!-- Sidebar Navigation -->
  <div class="sidebar collapsed" id="sidebar">
    <div class="sidebar-header">
      <h3>Student Panel</h3>
    </div>
    <ul class="sidebar-menu">
      <div class="menu-group">
        <div class="menu-group-title">Dashboard</div>
        <li><a href="#" class="active"><img src="../pix/home1.png" alt="Home" style="filter: brightness(0) invert(1);"> Dashboard</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Academic</div>
        <li><a href="checklist_stud.php"><img src="../pix/update.png" alt="Checklist"> Update Checklist</a></li>
        <li><a href="study_plan.php"><img src="../pix/studyplan.png" alt="Study Plan"> Study Plan</a></li>
        <li><a href="program_shift_request.php"><img src="../pix/checklist.png" alt="Program Shift"> Program Shift</a></li>
      </div>
      
      <div class="menu-group">
        <div class="menu-group-title">Account</div>
        <li><a href="acc_mng.php"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
        <li><a href="../auth/signout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
      </div>
    </ul>
  </div>

  <!-- Main Content -->
  <div class="main-content" id="mainContent">
    <div class="page-header">
      <h1>Student Dashboard</h1>
    </div>
    <div class="content">
      <div class="dashboard-intro">
        <?php if (!empty($academicHold['active'])): ?>
        <div class="academic-hold-banner">
          <strong><?= htmlspecialchars((string)$academicHold['title']) ?></strong>
          <p><?= htmlspecialchars((string)$academicHold['message']) ?></p>
        </div>
        <?php endif; ?>

        <div class="message-container">
          For new users: Please input first all your grades in checklist
        </div>

      </div>

      <div class="section-card">
        <h2 class="section-title">Quick Access</h2>
        <div class="quick-actions-grid">
          <div class="option-container" onclick="window.location.href='checklist_stud.php'">
            <img src="../pix/update.png" alt="Update Checklist Icon">
            <label>Update Checklist</label>
          </div>

          <div class="option-container" onclick="window.location.href='study_plan.php'">
            <img src="../pix/studyplan.png" alt="Study Plan Icon">
            <label>Study Plan</label>
          </div>

          <div class="option-container" onclick="window.location.href='program_shift_request.php'">
            <img src="../pix/checklist.png" alt="Program Shift Icon">
            <label>Program Shift</label>
          </div>

          <div class="option-container" onclick="window.location.href='acc_mng.php'">
            <img src="../pix/account.png" alt="Account Manager Icon">
            <label>Update Profile</label>
          </div>
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
      const logo = document.querySelector('.title-bar img');
      
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
