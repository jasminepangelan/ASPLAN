<?php
// Admin Password Reset Utility
// Use this file to reset admin password if needed

require_once __DIR__ . '/../includes/laravel_bridge.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Both password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        if (getenv('USE_LARAVEL_BRIDGE') === '1') {
            $bridgeData = postLaravelJsonBridge(
                'http://localhost/ASPLAN_v10/laravel-app/public/api/admin/reset-password',
                [
                    'bridge_authorized' => true,
                    'new_password' => $new_password,
                    'confirm_password' => $confirm_password,
                ]
            );

            if (is_array($bridgeData)) {
                if (!empty($bridgeData['success'])) {
                    $success = (string) ($bridgeData['message'] ?? 'Admin password updated successfully!');
                } else {
                    $error = (string) ($bridgeData['message'] ?? 'Failed to update password.');
                }
            } else {
                $error = "Failed to reach Laravel bridge.";
            }
        } else {
        // Update password
        require_once __DIR__ . '/../config/config.php';
        $conn = getDBConnection();
        if ($conn->connect_error) {
            $error = "Database connection failed: " . $conn->connect_error;
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE username = 'admin'");
            $stmt->bind_param("s", $hashed_password);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $success = "Admin password updated successfully!";
                } else {
                    $error = "Admin user not found.";
                }
            } else {
                $error = "Failed to update password: " . $stmt->error;
            }
            $stmt->close();
            $conn->close();
        }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Password Reset</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 500px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #4caf50;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #007bff;
            text-decoration: none;
        }
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Admin Password Reset</h1>
        
        <div class="warning">
            <strong>Warning:</strong> This will reset the password for the 'admin' user account. 
            Only use this if you've forgotten the current password.
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success">
                <?php echo htmlspecialchars($success); ?>
                <br><br>
                <strong>New credentials:</strong><br>
                Username: admin<br>
                Password: <?php echo htmlspecialchars($new_password); ?>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="new_password">New Password:</label>
                    <input type="password" id="new_password" name="new_password" required minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                </div>
                
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="login.php">Admin Login</a>
        </div>
    </div>
</body>
</html>
