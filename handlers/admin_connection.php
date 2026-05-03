<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/handlers_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

hsSetJsonHeader();
elsInfo('Admin account creation initiated', [], 'admin_handler');

try {
    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
    // Validate required fields
    $validation = hsValidateRequiredFields(['full_name', 'username', 'password']);
    if (!$validation['valid']) {
        $message = 'Missing required fields: ' . implode(', ', $validation['missing']);
        elsWarning($message, ['missing_fields' => $validation['missing']], 'admin_handler');
        throw new Exception($message);
    }

    if ($useLaravelBridge) {
        $bridgePayload = $_POST;
        $bridgePayload['bridge_authorized'] = true;
        $bridgeData = postLaravelJsonBridge(
            '/api/admin/create-account',
            $bridgePayload
        );

        if (is_array($bridgeData)) {
            echo json_encode($bridgeData);
            hsExitJson();
        }
    }

    $full_name = $validation['values']['full_name'];
    $username = $validation['values']['username'];
    $password = $_POST['password']; // Password validated separately below

    // Validate password not empty
    if (empty($password)) {
        throw new Exception('Password is required');
    }

    // Get database connection
    $conn = getDBConnection();

    // Check if username already exists
    $usernameValidation = hsValidateUniqueUsername($conn, $username, 'admins');
    if (!$usernameValidation['valid']) {
        throw new Exception($usernameValidation['message']);
    }

    // Insert into the `admins` table
    $stmt = $conn->prepare("INSERT INTO admins (full_name, username, password) VALUES (?, ?, ?)");
    $hashed_password = hsHashPassword($password);
    $stmt->bind_param("sss", $full_name, $username, $hashed_password);
    
    if ($stmt->execute()) {
        elsInfo('Admin account created successfully', ['username' => $username], 'admin_handler');
        hsSuccessResponse('Admin account created successfully!');
    } else {
        throw new Exception('Failed to create admin account');
    }

    $stmt->close();
    closeDBConnection($conn);
    hsExitJson();

} catch (Exception $e) {
    elsError('Admin account creation failed', ['error' => $e->getMessage()], 'admin_handler', $e);
    hsErrorResponse($e->getMessage());
    hsExitJson();
}
?>


