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
        '/api/dashboard/overview',
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
      padding: 28px 30px 34px;
      max-width: 1240px;
      margin: 0 auto;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      gap: 20px;
      align-items: stretch;
    }

    .dashboard-intro {
      display: grid;
      gap: 16px;
      width: 100%;
    }

    .page-header {
      padding: 4px 4px 0;
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

    .quick-actions-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      width: 100%;
    }

    .option-container {
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
      width: 100%;
      min-width: 0;
      max-width: none;
      margin: 0;
      box-sizing: border-box;
      min-height: 210px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .option-container::before {
      content: '';
      position: absolute;
      inset: 0 auto auto 0;
      width: 100%;
      height: 4px;
      background: linear-gradient(90deg, rgba(22, 79, 20, 0.95) 0%, rgba(76, 175, 80, 0.88) 100%);
      opacity: 0.95;
    }

    .option-container:hover {
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
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
      margin-bottom: 18px;
    }

    .option-container img {
      width: 40px;
      height: 40px;
      transition: transform 0.3s ease;
    }

    .option-container:hover img {
      transform: scale(1.08);
    }

    .option-title {
      font-size: 22px;
      display: block;
      font-weight: 800;
      color: #173318;
      letter-spacing: -0.3px;
      margin: 0 0 8px;
      cursor: pointer;
    }

    .option-caption {
      font-size: 13px;
      line-height: 1.6;
      color: #5c6f5d;
      margin: 0;
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
        padding: 2px 0 0;
        margin: 0 0 10px;
      }

      .page-header h1 {
        font-size: 28px;
      }

      .section-card {
        padding: 18px;
      }

      .section-head {
        align-items: flex-start;
      }

      .quick-actions-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .option-container {
        min-height: 188px;
        padding: 18px 16px 16px;
      }

      .option-icon {
        width: 64px;
        height: 64px;
        border-radius: 18px;
      }

      .option-container img {
        width: 34px;
        height: 34px;
      }

      .option-title {
        font-size: 18px;
      }

      .message-container {
        padding: 14px;
        align-items: flex-start;
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

      .content {
        padding: 12px;
      }

      .page-header {
        padding: 0;
        margin: 0 0 8px;
      }

      .page-header h1 {
        font-size: 24px;
      }

      .message-container {
        padding: 14px;
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
          <span class="message-icon">i</span>
          <span class="message-copy">
            <small>Getting Started</small>
            <span>For new users, complete your checklist grades first so the system can generate a more accurate study plan.</span>
          </span>
        </div>

      </div>

      <div class="section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Quick Access</h2>
            <p class="section-subtitle">Jump into the four core student tools with a cleaner, more focused launch area built for everyday use.</p>
          </div>
        </div>
        <div class="quick-actions-grid">
          <div class="option-container" onclick="window.location.href='checklist_stud.php'">
            <div class="option-icon">
              <img src="../pix/update.png" alt="Update Checklist Icon">
            </div>
            <div>
              <label class="option-title">Update Checklist</label>
              <p class="option-caption">Encode your grades first so course completion, back subjects, and study-plan rules stay accurate.</p>
            </div>
          </div>

          <div class="option-container" onclick="window.location.href='study_plan.php'">
            <div class="option-icon">
              <img src="../pix/studyplan.png" alt="Study Plan Icon">
            </div>
            <div>
              <label class="option-title">Study Plan</label>
              <p class="option-caption">Review your generated academic path, remaining semesters, and course sequence with clearer guidance.</p>
            </div>
          </div>

          <div class="option-container" onclick="window.location.href='program_shift_request.php'">
            <div class="option-icon">
              <img src="../pix/checklist.png" alt="Program Shift Icon">
            </div>
            <div>
              <label class="option-title">Program Shift</label>
              <p class="option-caption">Track request status, review approvals, and prepare a well-supported shift request when needed.</p>
            </div>
          </div>

          <div class="option-container" onclick="window.location.href='acc_mng.php'">
            <div class="option-icon">
              <img src="../pix/account.png" alt="Account Manager Icon">
            </div>
            <div>
              <label class="option-title">Update Profile</label>
              <p class="option-caption">Keep your personal details, account settings, and official student information current.</p>
            </div>
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

