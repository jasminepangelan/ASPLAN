<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';
require_once __DIR__ . '/../includes/student_profile_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

elsInfo('Student profile update attempted', [], 'student_profile');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    elsWarning('Invalid request method for profile update', ['method' => $_SERVER['REQUEST_METHOD']], 'student_profile');
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!validateCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
    elsWarning('Profile update blocked due to invalid CSRF token', [], 'student_profile');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token validation failed. Please refresh the page and try again.']);
    exit;
}

try {
    $isAdmin = isset($_SESSION['admin_id']) || isset($_SESSION['admin_username']);
    $sessionStudentId = trim((string) ($_SESSION['student_id'] ?? $_SESSION['student_number'] ?? ''));
    $requestedStudentId = trim((string) ($_POST['student_id'] ?? ''));

    if (!$isAdmin && $sessionStudentId === '') {
        elsWarning('Unauthorized profile update attempt without session identity', [], 'student_profile');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }

    if ($isAdmin) {
        if ($requestedStudentId === '') {
            elsWarning('Admin profile update: no student ID provided', [], 'student_profile');
            echo json_encode(['success' => false, 'message' => 'No student ID provided']);
            exit;
        }

        if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $requestedStudentId)) {
            elsWarning('Admin profile update: invalid student ID format', ['student_id' => $requestedStudentId], 'student_profile');
            echo json_encode(['success' => false, 'message' => 'Invalid student ID provided.']);
            exit;
        }

        $student_id = $requestedStudentId;
        $profileContext = 'admin';
    } else {
        if ($requestedStudentId !== '' && !hash_equals($sessionStudentId, $requestedStudentId)) {
            elsWarning(
                'Student profile update blocked due to mismatched student ID',
                ['session_student_id' => $sessionStudentId, 'posted_student_id' => $requestedStudentId],
                'student_profile'
            );
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only update your own profile.']);
            exit;
        }

        $student_id = $sessionStudentId;
        $profileContext = 'student';
    }

    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
    $hasPictureUpload = isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK;

    if ($useLaravelBridge && $student_id !== '' && !$hasPictureUpload) {
        $formFields = $_POST;
        $formFields['student_id'] = $student_id;
        $formFields['profile_context'] = $profileContext;
        $formFields['bridge_authorized'] = true;

        if ($profileContext === 'student') {
            $formFields['session_student_id'] = $student_id;
        } else {
            $formFields['admin_id'] = (string) ($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? '');
        }

        $bridgeData = postLaravelJsonBridge(
            '/api/student-profile/update',
            $formFields
        );

        if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
            if (!empty($bridgeData['success'])) {
                if ($profileContext === 'student' && isset($bridgeData['updated_fields']) && is_array($bridgeData['updated_fields'])) {
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

                if ($profileContext === 'student' && !empty($bridgeData['picture_path'])) {
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

        if ($profileContext === 'student') {
            // Keep the self-service session in sync with the saved profile.
            spsUpdateSessionFromFields($validatedFields);
        }
    }

    // Handle profile picture update
    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $pictureResult = spsUpdateProfilePicture($student_id, $_FILES['picture'], $conn);
        if ($pictureResult['success']) {
            if ($profileContext === 'student') {
                $_SESSION['picture'] = $pictureResult['path'];
            }
            elsInfo('Profile picture updated', ['student_id' => $student_id, 'picture_path' => $pictureResult['path']], 'student_profile');
        } else {
            elsWarning('Profile picture update failed', ['error' => $pictureResult['error'], 'student_id' => $student_id], 'student_profile');
            echo json_encode(['success' => false, 'message' => $pictureResult['error']]);
            closeDBConnection($conn);
            exit;
        }
    }

    elsInfo('Student profile updated successfully', ['student_id' => $student_id, 'fields_updated' => array_keys($validatedFields)], 'student_profile');
    echo json_encode([
        'success' => true,
        'picture_path' => $_SESSION['picture'] ?? null,
    ]);
    closeDBConnection($conn);

} catch (Exception $e) {
    elsError('Student profile update exception', ['error' => $e->getMessage()], 'student_profile', $e);
    echo json_encode(['success' => false, 'message' => 'An error occurred while updating profile.']);
}
?>
