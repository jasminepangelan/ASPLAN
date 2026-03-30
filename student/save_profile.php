<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';
require_once __DIR__ . '/../includes/student_profile_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

header('Content-Type: application/json');

elsInfo('Student profile update attempted', [], 'student_profile');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    elsWarning('Invalid request method for profile update', ['method' => $_SERVER['REQUEST_METHOD']], 'student_profile');
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

    if ($useLaravelBridge && isset($_POST['student_id'])) {
        $formFields = $_POST;
        $formFields['profile_context'] = 'student';

        $bridgeData = null;
        if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
            $bridgeData = postLaravelMultipartBridge(
                'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                $formFields,
                [
                    'picture' => [
                        'path' => (string) $_FILES['picture']['tmp_name'],
                        'name' => (string) $_FILES['picture']['name'],
                        'mime' => (string) ($_FILES['picture']['type'] ?? 'application/octet-stream'),
                    ],
                ]
            );
        } else {
            $bridgeData = postLaravelJsonBridge(
                'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                $formFields
            );
        }

        if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
            if (!empty($bridgeData['success'])) {
                if (isset($bridgeData['updated_fields']) && is_array($bridgeData['updated_fields'])) {
                    foreach ($bridgeData['updated_fields'] as $field => $value) {
                        switch ($field) {
                            case 'last_name':
                            case 'first_name':
                            case 'middle_name':
                            case 'email':
                            case 'admission_date':
                            case 'address':
                                $_SESSION[$field] = $value;
                                break;
                            case 'contact_no':
                                $_SESSION['contact_no'] = $value;
                                break;
                        }
                    }
                }

                if (!empty($bridgeData['picture_path'])) {
                    $_SESSION['picture'] = (string) $bridgeData['picture_path'];
                }

                echo json_encode(['success' => true]);
                exit;
            }

            echo json_encode([
                'success' => false,
                'message' => $bridgeData['message'] ?? 'An error occurred while updating profile.',
            ]);
            exit;
        }
    }

    // Get database connection
    $conn = getDBConnection();

    // Get student_id from POST
    if (!isset($_POST['student_id'])) {
        elsWarning('Profile update: no student ID provided', [], 'student_profile');
        echo json_encode(['success' => false, 'message' => 'No student ID provided']);
        closeDBConnection($conn);
        exit;
    }
    $student_id = $_POST['student_id'];

    // Validate profile update fields
    $validationResult = spsValidateProfileUpdate($conn, $_POST);
    if (!$validationResult['valid']) {
        elsWarning('Profile update validation failed', ['error' => $validationResult['error'], 'student_id' => $student_id], 'student_profile');
        echo json_encode(['success' => false, 'message' => $validationResult['error']]);
        closeDBConnection($conn);
        exit;
    }

    // Update profile fields if any valid fields exist
    $validatedFields = $validationResult['validated_fields'];
    if (!empty($validatedFields)) {
        $updateResult = spsUpdateStudentProfile($conn, $student_id, $validatedFields);
        if (!$updateResult['success']) {
            elsError('Profile update failed', ['error' => $updateResult['error'], 'student_id' => $student_id], 'student_profile');
            echo json_encode(['success' => false, 'message' => $updateResult['error']]);
            closeDBConnection($conn);
            exit;
        }

        // Update session variables from profile fields
        spsUpdateSessionFromFields($validatedFields);
    }

    // Handle profile picture update
    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $pictureResult = spsUpdateProfilePicture($student_id, $_FILES['picture'], $conn);
        if ($pictureResult['success']) {
            $_SESSION['picture'] = $pictureResult['path'];
            elsInfo('Profile picture updated', ['student_id' => $student_id, 'picture_path' => $pictureResult['path']], 'student_profile');
        } else {
            elsWarning('Profile picture update failed', ['error' => $pictureResult['error'], 'student_id' => $student_id], 'student_profile');
            echo json_encode(['success' => false, 'message' => $pictureResult['error']]);
            closeDBConnection($conn);
            exit;
        }
    }

    elsInfo('Student profile updated successfully', ['student_id' => $student_id, 'fields_updated' => array_keys($validatedFields)], 'student_profile');
    echo json_encode(['success' => true]);
    closeDBConnection($conn);

} catch (Exception $e) {
    elsError('Student profile update exception', ['error' => $e->getMessage()], 'student_profile', $e);
    echo json_encode(['success' => false, 'message' => 'An error occurred while updating profile.']);
}
?>
