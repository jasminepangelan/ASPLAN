<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/handlers_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

hsSetJsonHeader();
elsInfo('Adviser account creation initiated', [], 'adviser_handler');

try {
    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
    // Validate required fields
    $validation = hsValidateRequiredFields(['last_name', 'first_name', 'username', 'password', 'sex', 'pronoun', 'program']);
    if (!$validation['valid']) {
        $message = 'Missing required fields: ' . implode(', ', $validation['missing']);
        elsWarning($message, ['missing_fields' => $validation['missing']], 'adviser_handler');
        throw new Exception($message);
    }

    if ($useLaravelBridge) {
        $bridgePayload = $_POST;
        $bridgePayload['bridge_authorized'] = true;
        $bridgeData = postLaravelJsonBridge(
            '/api/adviser/create-account',
            $bridgePayload
        );

        if (is_array($bridgeData)) {
            echo json_encode($bridgeData);
            hsExitJson();
        }
    }

    $last_name = $validation['values']['last_name'];
    $first_name = $validation['values']['first_name'];
    $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : NULL;
    $username = $validation['values']['username'];
    $password = $_POST['password'];
    $sex = $validation['values']['sex'];
    $pronoun = $validation['values']['pronoun'];
    $program = $validation['values']['program'];

    // Get database connection
    $conn = getDBConnection();

    // Check if username already exists
    $usernameValidation = hsValidateUniqueUsername($conn, $username, 'adviser');
    if (!$usernameValidation['valid']) {
        throw new Exception($usernameValidation['message']);
    }

    // Keep adviser.id populated because adviser_batch references this numeric key.
    $nextIdResult = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM adviser");
    $nextIdRow = $nextIdResult ? $nextIdResult->fetch_assoc() : null;
    $nextId = isset($nextIdRow['next_id']) ? (int)$nextIdRow['next_id'] : 1;

    // Insert into the `adviser` table with separate name columns
    $stmt = $conn->prepare("INSERT INTO adviser (last_name, first_name, middle_name, username, password, sex, pronoun, program, id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $hashed_password = hsHashPassword($password);
    $stmt->bind_param("ssssssssi", $last_name, $first_name, $middle_name, $username, $hashed_password, $sex, $pronoun, $program, $nextId);
    
    if ($stmt->execute()) {
        elsInfo('Adviser account created successfully', ['username' => $username, 'program' => $program], 'adviser_handler');
        hsSuccessResponse('Adviser account created successfully!');
    } else {
        throw new Exception('Failed to create adviser account');
    }

    $stmt->close();
    closeDBConnection($conn);
    hsExitJson();

} catch (Exception $e) {
    elsError('Adviser account creation failed', ['error' => $e->getMessage()], 'adviser_handler', $e);
    hsErrorResponse($e->getMessage());
    hsExitJson();
}
?>


