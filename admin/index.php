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
      padding: 30px;
      max-width: 100%;
      box-sizing: border-box;
    }

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
      filter: none;
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
      <div class="options">
        <!-- User Management Option -->
        <div class="option" onclick="window.location.href='account_module.php'">
          <img src="../pix/account.png" alt="User Management Icon">
          <label>User Management</label>
        </div>
        <!-- List of Students Option -->
        <div class="option" onclick="window.location.href='list_of_students.php'">
          <img src="../pix/generic_user.svg" alt="List of Students Icon">
          <label>List of students</label>
        </div>
        <div class="option" onclick="window.location.href='../program_coordinator/curriculum_management.php'">
          <img src="../pix/curr.png" alt="Curriculum Management Icon">
          <label>Curriculum Management</label>
        </div>
        <div class="option" onclick="window.location.href='programs.php'">
          <img src="../pix/update.png" alt="Programs Icon">
          <label>Programs</label>
        </div>
        <div class="option" onclick="window.location.href='account_approval_settings.php'">
          <img src="../pix/set.png" alt="Settings Icon">
          <label>Settings</label>
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








