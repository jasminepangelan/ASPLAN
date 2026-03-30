<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/account_management_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Only allow admin
if (!isset($_SESSION['admin_id'])) {
  header("Location: ../index.php");
    exit();
}

$view_student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
if (!$view_student_id) {
    die("No student selected.");
}

$row = null;
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/account-management/student-profile',
        ['student_id' => $view_student_id]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success']) && isset($bridgeData['student']) && is_array($bridgeData['student'])) {
        $row = $bridgeData['student'];
    }
}

if (!$row) {
    $row = amLoadStudentInfo($view_student_id);
}

if (!$row) {
    die("Student not found.");
}

$last_name = htmlspecialchars($row['last_name'] ?? '');
$first_name = htmlspecialchars($row['first_name'] ?? '');
$middle_name = htmlspecialchars($row['middle_name'] ?? '');
$email = htmlspecialchars($row['email'] ?? '');
$picture = amFormatPicturePath($row['picture'] ?? '');
$student_id = htmlspecialchars((string)($row['student_number'] ?? ''));
$contact_no = htmlspecialchars((string)($row['contact_number'] ?? ''));
$address = amBuildAddressString(
    $row['house_number_street'] ?? '',
    $row['brgy'] ?? '',
    $row['town'] ?? '',
    $row['province'] ?? ''
);
$admission_date = htmlspecialchars((string)($row['date_of_admission'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pre - Enrollment Assessment</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
  <style>
    :root {
      --brand-900: #174c14;
      --brand-800: #206018;
      --brand-700: #2d8f22;
      --brand-100: #eef7ee;
      --surface: #ffffff;
      --surface-soft: #f8fbf8;
      --text-900: #1f2a21;
      --text-600: #5a6a5d;
      --border: #d7e6d5;
      --shadow-soft: 0 12px 28px rgba(20, 57, 20, 0.1);
      --shadow-card: 0 8px 20px rgba(27, 71, 28, 0.12);
    }

    * {
      box-sizing: border-box;
    }
    
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      background:
        linear-gradient(rgba(242, 248, 241, 0.92), rgba(242, 248, 241, 0.96)),
        url('pix/school.jpg') no-repeat center center fixed;
      background-size: cover;
      min-height: 100vh;
      padding-top: 45px;
      color: var(--text-900);
    }

    .header {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      padding: 5px 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 20px rgba(32, 96, 24, 0.2);
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 45px;
      z-index: 1000;
    }

    .header .logo-section {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .header .logo-section img {
      height: 30px;
      width: auto;
      filter: brightness(1.1);
      cursor: pointer;
    }

    .header h1 {
      margin: 0;
      font-size: 20px;
      font-weight: 500;
      letter-spacing: 0.5px;
      color: #d9e441;
    }

    .admin-info {
      font-size: 14px;
      font-weight: 600;
      color: white;
      letter-spacing: 0.5px;
      background: rgba(255, 255, 255, 0.15);
      padding: 5px 12px;
      border-radius: 8px;
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
      margin-right: 6px;
      transition: all 0.2s ease;
    }

    .menu-toggle:hover {
      background: rgba(255, 255, 255, 0.22);
    }

    .sidebar {
      width: 250px;
      height: calc(100vh - 45px);
      background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
      color: white;
      position: fixed;
      left: 0;
      top: 45px;
      padding: 10px 0;
      box-shadow: 2px 0 5px rgba(0,0,0,0.1);
      overflow-y: auto;
      transition: transform 0.3s ease;
      z-index: 999;
    }

    .sidebar.collapsed {
      transform: translateX(-250px);
    }

    .sidebar-header {
      padding: 10px 20px;
      text-align: center;
      color: white;
      border-bottom: 2px solid rgba(255, 255, 255, 0.2);
      margin-bottom: 2px;
    }

    .sidebar-header h3 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
      line-height: 1.1;
    }

    .sidebar-menu {
      list-style: none;
      padding: 2px 0;
      margin: 0;
    }

    .sidebar-menu li {
      margin: 0;
    }

    .sidebar-menu a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 8px 20px;
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
      margin: 4px 0;
    }

    .menu-group-title {
      padding: 4px 20px 1px 20px;
      color: rgba(255, 255, 255, 0.7);
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .container {
      max-width: 980px;
      width: min(980px, calc(100% - 50px));
      margin: 20px auto 30px auto;
      background: rgba(255, 255, 255, 0.97);
      backdrop-filter: blur(12px);
      border-radius: 18px;
      box-shadow: var(--shadow-soft);
      overflow: hidden;
      border: 1px solid var(--border);
      transition: all 0.3s ease;
      transform: translateX(125px);
    }

    .container.expanded {
      transform: translateX(0);
    }

    .title {
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      padding: 18px 22px;
      text-align: center;
      font-size: 24px;
      font-weight: 800;
      letter-spacing: 0.5px;
      margin: 0;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    }

    .subtitle {
      text-align: center;
      color: var(--text-600);
      font-size: 14px;
      margin: 0 0 22px;
      letter-spacing: 0.2px;
      font-weight: 500;
    }

    .content-wrapper {
      padding: 24px 28px 30px;
    }

    .profile {
      display: grid;
      grid-template-columns: 260px minmax(0, 1fr);
      gap: 18px;
      align-items: start;
    }

    .profile .photo {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
      background: var(--surface-soft);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 20px 18px;
      width: 100%;
      min-height: 100%;
    }

    .profile .photo .photo-container {
      position: relative;
      border-radius: 50%;
      padding: 5px;
      background: linear-gradient(135deg, #206018, #2d8f22);
      box-shadow: var(--shadow-card);
    }

    .profile .photo img {
      width: 170px;
      height: 170px;
      border-radius: 50%;
      object-fit: cover;
      background: #f8f9fa;
      border: 4px solid white;
    }

    .profile .photo input[type="file"] {
      margin-top: 10px;
      padding: 8px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      font-size: 12px;
      background: white;
      cursor: pointer;
      transition: all 0.3s ease;
      width: 100%;
    }

    .profile .photo input[type="file"]:hover {
      border-color: var(--brand-700);
    }

    .profile .details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      width: 100%;
      min-width: 0;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 18px;
      box-shadow: 0 4px 14px rgba(23, 76, 20, 0.05);
    }

    .profile .details .field:last-child {
      grid-column: 1 / -1;
    }

    .profile .details .field {
      display: flex;
      flex-direction: column;
      position: relative;
    }

    .profile .details .field label {
      margin-bottom: 6px;
      font-weight: 700;
      color: var(--brand-800);
      font-size: 12px;
      letter-spacing: 0.4px;
      text-transform: uppercase;
    }

    .profile .details .field input {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d7e4d5;
      border-radius: 10px;
      font-size: 14px;
      background: #ffffff;
      transition: all 0.3s ease;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    }

    .profile .details .field input:focus {
      border-color: var(--brand-700);
      outline: none;
      box-shadow: 0 0 0 4px rgba(45, 143, 34, 0.12);
    }

    .profile .details .field input[readonly] {
      background: #f8f9fa;
      color: #6c757d;
      cursor: not-allowed;
    }

    .profile .details .field button[type="button"] {
      margin-top: 10px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 12px 0;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
    }
    .profile .details .field button[type="button"]:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(32, 96, 24, 0.4);
    }

    .buttons {
      text-align: center;
      margin-top: 20px;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    .buttons button {
      padding: 12px 26px;
      background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
      color: white;
      border: none;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      letter-spacing: 0.3px;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(32, 96, 24, 0.3);
      min-width: 140px;
    }

    .buttons button:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(32, 96, 24, 0.4);
    }

    .buttons button[style*="background-color: #888"] {
      background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
    }

    .btn-secondary {
      background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
    }

    @media (max-width: 980px) {
      .profile {
        grid-template-columns: 1fr;
      }

      .profile .photo {
        max-width: 360px;
        justify-self: center;
      }

      .buttons {
        justify-content: center;
      }
    }

    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-250px);
      }

      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }

      .profile {
        display: grid;
        grid-template-columns: 1fr;
      }
      
      .profile .details {
        grid-template-columns: 1fr;
        max-width: 100%;
        width: 100%;
      }
      
      .buttons {
        flex-direction: column;
        align-items: center;
      }
      
      .container {
        width: auto;
        margin: 15px;
        border-radius: 15px;
        transform: none;
      }

      .container.expanded {
        width: auto;
        margin: 15px;
        transform: none;
      }
      
      .content-wrapper {
        padding: 15px 20px 25px;
      }

      .buttons {
        justify-content: center;
      }
    }

    /* Modal Styles - Simple and bulletproof */
    #successModal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      z-index: 10000;
    }

    #successModal .modal-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: white;
      padding: 30px;
      border-radius: 10px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
      min-width: 300px;
    }

    #successModal .modal-content h2 {
      color: #206018;
      margin-bottom: 10px;
    }

    #successModal .modal-content p {
      color: #333;
      margin-bottom: 20px;
    }

    #successModal .modal-content button {
      background: #206018;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 5px;
      cursor: pointer;
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
    <div class="logo-section">
      <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
      <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
      <h1>ASPLAN</h1>
    </div>
    <div class="admin-info">Admin Panel</div>
  </div>

  <?php
  $activeAdminPage = 'list_of_students';
  $adminSidebarCollapsed = true;
  require __DIR__ . '/../includes/admin_sidebar.php';
  ?>

  <div class="container" id="mainContent">
    <div class="title">Student Profile</div>
    <div class="content-wrapper">
      <div class="subtitle">Review and update the student's account and personal information.</div>
      <form id="edit-student-form" enctype="multipart/form-data">
        <div class="profile">
          <div class="photo">
            <div class="photo-container">
              <img id="profile-pic" src="<?= $picture ?>" alt="Profile Photo" />
            </div>
            <input id="file-input" name="picture" type="file" accept="image/*">
          </div>
        <div class="details">
          <div class="field">
            <label for="student_id">Student Number</label>
            <input id="student_id" name="student_id" type="text" value="<?= $student_id ?>" readonly>
          </div>
          <div class="field">
            <label for="last_name">Last Name</label>
            <input id="last_name" name="last_name" type="text" value="<?= $last_name ?>">
          </div>
          <div class="field">
            <label for="first_name">First Name</label>
            <input id="first_name" name="first_name" type="text" value="<?= $first_name ?>">
          </div>
          <div class="field">
            <label for="middle_name">Middle Name</label>
            <input id="middle_name" name="middle_name" type="text" value="<?= $middle_name ?>">
          </div>
          <div class="field">
            <label for="email">Email Address</label>
            <input id="email" name="email" type="email" value="<?= $email ?>">
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="text" value="" placeholder="Enter new password or leave blank to keep current">
          </div>
          <div class="field">
            <label for="contact_no">Contact Number</label>
            <input id="contact_no" name="contact_no" type="text" value="<?= $contact_no ?>">
          </div>
          <div class="field">
            <label for="admission_date">Date of Admission</label>
            <input id="admission_date" name="admission_date" type="text" value="<?= $admission_date ?>">
          </div>
          <div class="field">
            <label for="address">Address</label>
            <input id="address" name="address" type="text" value="<?= $address ?>">
          </div>
        </div>
        <div class="buttons">
          <button type="button" onclick="saveStudentChanges()">SAVE CHANGES</button>
          <button type="button" class="btn-secondary" onclick="window.history.back();">BACK</button>
        </div>
      </form>
    </div>
  </div>
  
  <!-- Success Modal - Simple and bulletproof -->
  <div id="successModal">
    <div class="modal-content">
      <h2>Profile Updated</h2>
      <p>The student profile has been updated successfully.</p>
      <button onclick="closeSuccessModal()">OK</button>
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
      const logo = document.querySelector('.header .logo-section img');

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

    window.addEventListener('resize', function() {
      const sidebar = document.getElementById('sidebar');
      const mainContent = document.getElementById('mainContent');

      if (window.innerWidth > 768) {
        sidebar.classList.remove('collapsed');
        mainContent.classList.remove('expanded');
      } else {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('expanded');
      }
    });

    function showSuccessModal() {
      document.getElementById('successModal').style.display = 'block';
    }
    function closeSuccessModal() {
      document.getElementById('successModal').style.display = 'none';
      location.reload();
    }
    function saveStudentChanges() {
      const form = document.getElementById('edit-student-form');
      const formData = new FormData(form);
      fetch('../student/save_profile.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.text())
      .then(text => {
        let data;
        try { data = JSON.parse(text); } catch (e) { alert('Server error: ' + text); return; }
        if (data.success) {
          showSuccessModal();
        } else {
          alert('Error: ' + data.message);
        }
      })
      .catch(error => { alert('Network error: ' + error); });
    }
  </script>
</body>
</html>








