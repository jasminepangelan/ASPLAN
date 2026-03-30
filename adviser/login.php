<?php
/**
 * Adviser Login - Redirects to unified login page
 * All users now use the single login portal at index.php
 */
session_start(); // Start the session

// Check if the user is already logged in
if (isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Redirect to main login page
header("Location: ../index.php");
exit();
?>

<!DOCTYPE html>
<html>
<head>
  <title>Adviser Login</title>
  <link rel="icon" type="image/png" href="../img/cav.png">
  <style>
    body {
         font-family: 'Arial', sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
      padding: 0;
    }

    .login-container {
        background: linear-gradient(135deg, #11491a 0%, #1a2a13 100%);
        padding: 40px;
        border-radius: 20px;
        width: 400px;
        text-align: center;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .login-container h2 {
        margin-bottom: 40px;
        font-size: 28px;
        color: white;
        font-weight: bold;
        letter-spacing: 1px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .login-container h2::before {
        font-size: 24px;
    }

    .form-group {
        margin-bottom: 20px;
        text-align: left;
    }

    .form-group label {
        display: block;
        color: white;
        font-weight: bold;
        margin-bottom: 8px;
        font-size: 14px;
        letter-spacing: 0.5px;
    }

    .login-container input[type="text"],
    .login-container input[type="password"] {
        width: 100%;
        padding: 15px 20px;
        border: 2px solid rgba(255, 255, 255, 0.2);
        border-radius: 25px;
        box-sizing: border-box;
        background-color: rgba(255, 255, 255, 0.9);
        font-size: 16px;
        color: #333;
        transition: all 0.3s ease;
    }

    .login-container input[type="text"]:focus,
    .login-container input[type="password"]:focus {
        outline: none;
        border-color: #7fb069;
        background-color: white;
        box-shadow: 0 0 10px rgba(127, 176, 105, 0.3);
    }

    .login-container input::placeholder {
        color: #666;
        font-style: italic;
    }

    .forgot-password {
        text-align: center;
        margin: 15px 0 25px 0;
    }

    .forgot-password a {
        color: white;
        text-decoration: none;
        font-style: italic;
        font-size: 14px;
        transition: color 0.3s ease;
    }

    .forgot-password a:hover {
        color: #7fb069;
        text-decoration: underline;
    }

    .btn-login {
        width: 100%;
        padding: 15px;
        background-color: #7fb069;
        color: white;
        border: none;
        border-radius: 25px;
        cursor: pointer;
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 15px;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .btn-login:hover {
        background-color: #6a9454;
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }

    .btn-back {
        width: 100%;
        padding: 12px;
        background-color: rgba(255, 255, 255, 0.2);
        color: white;
        border: 2px solid rgba(255, 255, 255, 0.3);
        border-radius: 25px;
        cursor: pointer;
        font-size: 16px;
        font-weight: bold;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .btn-back:hover {
        background-color: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
        transform: translateY(-2px);
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2>ADVISER LOGIN</h2>
    <form id="loginForm" action="login_process.php" method="post">
      <div class="form-group">
        <label for="username">USERNAME</label>
        <input type="text" id="username" name="username" placeholder="Enter Username" required>
      </div>
      <div class="form-group">
        <label for="password">PASSWORD</label>
        <input type="password" id="password" name="password" placeholder="Enter Password" required>
      </div>
      <div class="forgot-password">
        <a href="../auth/forgot_password.php">FORGOT PASSWORD?</a>
      </div>
      <button type="submit" class="btn-login">LOGIN</button>
    </form>
    <button type="button" class="btn-back" onclick="window.location.href='../index.php'">BACK</button>
  </div>
</body>
</html>
