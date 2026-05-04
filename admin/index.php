<?php
require_once __DIR__ . '/../config/config.php';

// Check if the user is already logged in
if (!isset($_SESSION['admin_username'])) {
    header("Location: /PEAS/admin/login.php");
    exit();
}

$admin_name = isset($_SESSION['admin_full_name']) ? htmlspecialchars($_SESSION['admin_full_name']) : '';
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
      padding: 28px 30px 34px;
      max-width: 1240px;
      margin: 0 auto;
      box-sizing: border-box;
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
    <div class="page-header">
      <h1>Admin Dashboard</h1>
    </div>
    <div class="content">
      <div class="message-container">
        <span class="message-icon">i</span>
        <span class="message-copy">
          <small>Admin Command Center</small>
          <span>Jump into the system’s main control areas quickly and keep <strong>user access</strong>, <strong>curriculum setup</strong>, and <strong>platform settings</strong> organized from one cleaner dashboard.</span>
        </span>
      </div>

      <div class="section-card">
        <div class="section-head">
          <div>
            <h2 class="section-title">Quick Access</h2>
            <p class="section-subtitle">Use the same polished dashboard structure as the student view while keeping the key admin modules easy to reach.</p>
          </div>
        </div>
        <div class="options">
        <div class="option" onclick="window.location.href='account_module.php'">
          <div class="option-icon"><img src="../pix/account.png" alt="User Management Icon"></div>
          <div>
            <label class="option-title">User Management</label>
            <p class="option-caption">Manage platform access, account records, and administrative user actions from one central module.</p>
          </div>
        </div>
        <div class="option" onclick="window.location.href='list_of_students.php'">
          <div class="option-icon"><img src="../pix/generic_user.svg" alt="Registered Students Icon"></div>
          <div>
            <label class="option-title">Registered Students</label>
            <p class="option-caption">Browse student records already saved in the system and move into profile, checklist, or study plan workflows.</p>
          </div>
        </div>
        <div class="option" onclick="window.location.href='curriculum_management.php'">
          <div class="option-icon"><img src="../pix/curr.png" alt="Curriculum Management Icon"></div>
          <div>
            <label class="option-title">Curriculum Management</label>
            <p class="option-caption">Configure curriculum structures and checklist foundations with the same cleaner dashboard presentation.</p>
          </div>
        </div>
        <div class="option" onclick="window.location.href='programs.php'">
          <div class="option-icon"><img src="../pix/update.png" alt="Programs Icon"></div>
          <div>
            <label class="option-title">Programs</label>
            <p class="option-caption">Open the program catalog, review current offerings, and move directly into checklist-builder workflows.</p>
          </div>
        </div>
        <div class="option" onclick="window.location.href='account_approval_settings.php'">
          <div class="option-icon"><img src="../pix/set.png" alt="Settings Icon"></div>
          <div>
            <label class="option-title">Settings</label>
            <p class="option-caption">Control approval rules, security options, and advanced system settings from one premium card-based entry.</p>
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

    document.addEventListener('click', function(event) {
      const sidebar = document.getElementById('sidebar');
      const logo = document.querySelector('.main-header img');

      if (window.innerWidth <= 768 &&
          sidebar && !sidebar.contains(event.target) &&
          (!logo || !logo.contains(event.target))) {
        sidebar.classList.add('collapsed');
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
          mainContent.classList.add('expanded');
        }
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
  </script>
</body>
</html>








