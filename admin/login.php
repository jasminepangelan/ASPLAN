<?php
/**
 * Admin Login - Redirects to unified login page
 * All users now use the single login portal at index.php
 */

// Redirect to main login page
header("Location: ../index.php");
exit();
?>
<!DOCTYPE html>
<html>
<head>
  <title>Admin login</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    html, body {
      height: 100vh;
      margin: 0;
      padding: 0;
      overflow: hidden;
    }
    body {
      background-size: cover;
      background-position: center;
      font-family: sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 40px;
    }
        .background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.2;
        }
    .login-container {
      background: linear-gradient(135deg, #11491a 0%, #1a2a13 100%);
      color: #fff;
      padding: 20px 38px 40px 38px;
      border-radius: 28px;
      box-shadow: 0 10px 32px rgba(32,96,24,0.18);
      width: 400px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .login-container h2 {
      margin-bottom: 60px;
      font-size: 2em;
      font-weight: 900;
      text-align: center;
      font-family: 'Poppins', Arial, sans-serif;
    }
    .login-label {
      width: 100%;
      text-align: center;
      font-weight: 700;
      font-size: 1.15em;
      margin-bottom: -8px;
      font-family: 'Poppins', Arial, sans-serif;
    }
    .login-container input[type="text"],
    .login-container input[type="password"] {
      width: 100%;
      padding: 15px 0px;
      border-radius: 40px;
      border: none;
      font-size: 1.15em;
      background: #fff;
      color: #222;
      margin-bottom: 0;
      font-family: 'Poppins', Arial, sans-serif;
      text-align: left;
      padding-right: 12px;
      padding-left: 40px;
      box-sizing: border-box;
    }
    .login-actions {
      width: 100%;
      display: flex;
      flex-direction: row;
      justify-content: center;
      gap: 32px;
      margin-top: 28px;
    }
    .login-btn {
      width: 160px;
      background: #fff;
      color: #11491a;
      font-size: 1.15em;
      font-weight: bold;
      border-radius: 40px;
      border: none;
      padding: 16px 0;
      box-shadow: 0 2px 8px rgba(32,96,24,0.10);
      font-family: 'Poppins', Arial, sans-serif;
      cursor: pointer;
      transition: background 0.2s, color 0.2s;
    }
    .login-btn:hover {
      background: #11491a;
      color: #fff;
    }
    .login-container hr {
      margin: 20px 0;
      border: 0;
      border-top: 1px solid #ccc;
      width: 100%;
    }
        /* Modal Styles */
    .modal {
      display: none !important;
      position: fixed !important;
      z-index: 1000 !important;
      left: 0 !important;
      top: 0 !important;
      width: 100% !important;
      height: 100% !important;
      background-color: rgba(0, 0, 0, 0.5) !important;
      animation: fadeIn 0.3s ease-in-out !important;
    }
    .modal-content {
      margin: 150px auto !important;
      padding: 12px !important;
      border-radius: 15px !important;
      width: 90% !important;
      max-width: 400px !important;
      text-align: center !important;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3) !important;
      animation: slideIn 0.4s ease-out !important;
      position: relative !important;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg,rgb(220, 235, 53), #206018) !important;
    }
    .modal-icon {
      width: 80px;
      height: 80px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      margin: 0 auto 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 40px;
      color: white;
      font-weight: bold;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
      border: 3px solid rgba(255, 255, 255, 0.3);
    }
    .modal-title {
      color: white !important;
      font-size: 24px !important;
      font-weight: bold !important;
      margin-bottom: 10px !important;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2) !important;
    }
    .modal-message {
      color: rgba(255, 255, 255, 0.9) !important;
      font-size: 16px !important;
      margin: 12px !important;
      line-height: 1.5 !important;
      text-align: center;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .modal-button {
      background: rgba(255, 255, 255, 0.2);
      color: white;
      border: 2px solid rgba(255, 255, 255, 0.3);
      padding: 12px 30px;
      border-radius: 25px;
      cursor: pointer;
      font-size: 16px;
      font-weight: bold;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-block;
    }
    .modal-button:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }
    </style>
</head>
<body>
  <img class="background" height="1080" width="1920"/>
  <div class="login-container">
    <h2>ADMIN LOGIN</h2>
    <form id="loginForm" action="login_process.php" method="post" style="width: 100%; display: flex; flex-direction: column; align-items: center; gap: 28px;">
      <div class="login-label">Username</div>
      <input type="text" name="username" placeholder="Enter username" required>
      <div class="login-label">Password</div>
      <input type="password" name="password" placeholder="Enter password" required>
      <div class="login-actions">
        <button type="submit" class="login-btn">Login</button>
        <button type="button" class="login-btn" onclick="window.location.href='../index.php'">Back</button>
      </div>
    </form>
  </div>

  <!-- Success Modal -->
<div id="successModal" class="modal">
  <div class="modal-content">
    <div class="modal-icon">
      <i class="fas fa-check" style="color: white;"></i>
    </div>
    <div class="modal-title">Login Successful</div>
    <div class="modal-message">Welcome, Admin! Redirecting to dashboard...</div>
  </div>
</div>

  <script>
    // No JS needed for login, let the form submit normally
  </script>
</body>
</html>