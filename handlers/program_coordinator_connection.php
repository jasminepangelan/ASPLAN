<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/handlers_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

hsSetJsonHeader();
elsInfo('Program Coordinator account creation initiated', [], 'coordinator_handler');

try {
    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
    // Validate required fields
    $validation = hsValidateRequiredFields(['last_name', 'first_name', 'username', 'password', 'sex', 'pronoun', 'program']);
    if (!$validation['valid']) {
        $message = 'Missing required fields: ' . implode(', ', $validation['missing']);
        elsWarning($message, ['missing_fields' => $validation['missing']], 'coordinator_handler');
        throw new Exception($message);
    }

    if ($useLaravelBridge) {
        $bridgePayload = $_POST;
        $bridgePayload['bridge_authorized'] = true;
        $bridgeData = postLaravelJsonBridge(
            '/api/program-coordinator/create',
            $bridgePayload
        );

        if (is_array($bridgeData)) {
            echo json_encode($bridgeData);
            hsExitJson();
        }
    }

    $last_name = $validation['values']['last_name'];
    $first_name = $validation['values']['first_name'];
    $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : null;
    $username = $validation['values']['username'];
    $password = $_POST['password'];
    $sex = $validation['values']['sex'];
    $pronoun = $validation['values']['pronoun'];
    $programInput = $_POST['program'];

    $conn = getDBConnection();
    
    // Resolve the correct table name
    $table = hsResolveTableVariant($conn, 'program_coordinator');
    if ($table === null) {
        throw new Exception('Program coordinator table not found.');
    }

    // Program validation
    $allowedPrograms = [
        'Bachelor of Science in Computer Science',
        'Bachelor of Science in Information Technology',
        'Bachelor of Science in Computer Engineering',
        'Bachelor of Science in Industrial Technology',
        'Bachelor of Science in Hospitality Management',
        'Bachelor of Science in Business Administration - Major in Marketing Management',
        'Bachelor of Science in Business Administration - Major in Human Resource Management',
        'Bachelor of Secondary Education major in English',
        'Bachelor of Secondary Education major Math',
        'Bachelor of Secondary Education major in Science',
    ];

    $programs = [];
    if (is_array($programInput)) {
        foreach ($programInput as $p) {
            $p = trim((string)$p);
            if ($p !== '' && in_array($p, $allowedPrograms, true)) {
                $programs[] = $p;
            }
        }
    } else {
        $singleProgram = trim((string)$programInput);
        if ($singleProgram !== '' && in_array($singleProgram, $allowedPrograms, true)) {
            $programs[] = $singleProgram;
        }
    }

    $programs = array_values(array_unique($programs));
    $program = implode(', ', $programs);

    // Check if username already exists
    $usernameValidation = hsValidateUniqueUsername($conn, $username, $table);
    if (!$usernameValidation['valid']) {
        throw new Exception($usernameValidation['message']);
    }

    $hashed_password = hsHashPassword($password);
    $hasProgramColumn = hsTableHasColumn($conn, $table, 'program');

    if ($hasProgramColumn) {
        if (count($programs) === 0) {
            throw new Exception('At least one valid program is required.');
        }

        $stmt = $conn->prepare("INSERT INTO `$table` (last_name, first_name, middle_name, username, password, sex, pronoun, program) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssss", $last_name, $first_name, $middle_name, $username, $hashed_password, $sex, $pronoun, $program);
    } else {
        $stmt = $conn->prepare("INSERT INTO `$table` (last_name, first_name, middle_name, username, password, sex, pronoun) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $last_name, $first_name, $middle_name, $username, $hashed_password, $sex, $pronoun);
    }

    if ($stmt->execute()) {
        elsInfo('Program Coordinator account created successfully', ['username' => $username, 'program' => $program], 'coordinator_handler');
        hsSuccessResponse('Program Coordinator account has been created successfully.');
    } else {
        throw new Exception('Failed to create program coordinator account');
    }

    $stmt->close();
    closeDBConnection($conn);
    hsExitJson();

} catch (Exception $e) {
    elsError('Program Coordinator account creation failed', ['error' => $e->getMessage()], 'coordinator_handler', $e);
    hsErrorResponse($e->getMessage());
    hsExitJson();
}
?>

