<?php
// Set JSON content type header
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';
require_once __DIR__ . '/../includes/student_registration_service.php';

elsInfo('Student registration attempted', [], 'student_registration');

// Get database connection
$conn = getDBConnection();

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    elsWarning('Invalid request method', ['method' => $_SERVER['REQUEST_METHOD']], 'student_registration');
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    closeDBConnection($conn);
    exit;
}

try {
    // Validate registration requirements and form data
    $validationResult = srsValidateRegistration($conn, $_POST);
    if (!$validationResult['valid']) {
        elsWarning('Registration validation failed', ['error' => $validationResult['error']], 'student_registration');
        echo json_encode(['status' => 'error', 'message' => $validationResult['error']]);
        closeDBConnection($conn);
        exit;
    }

    // Normalize contact number
    $contact_no = $validationResult['contact_normalized'];

    // Get form data
    $student_id = trim($_POST['student_id']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $address = trim($_POST['address'] ?? '');
    $strand = trim($_POST['strand']);
    $program = trim($_POST['program']);
    $admission_date = trim($_POST['admission_date']);

    $registrationAvailability = srsGetRegistrationAvailability($conn, $student_id);
    if (!$registrationAvailability['allowed']) {
        elsWarning('Student registration blocked', ['student_id' => $student_id, 'message' => $registrationAvailability['message']], 'student_registration');
        echo json_encode(['status' => 'error', 'message' => $registrationAvailability['message']]);
        closeDBConnection($conn);
        exit;
    }

    // Process picture upload
    $pictureResult = srsProcessPictureUpload();
    if (!$pictureResult['success']) {
        elsWarning('Picture upload failed', ['error' => $pictureResult['error']], 'student_registration');
        echo json_encode(['status' => 'error', 'message' => $pictureResult['error']]);
        closeDBConnection($conn);
        exit;
    }
    $picture_db_path = $pictureResult['path'];

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Create student account
    $createResult = srsCreateStudentAccount($conn, [
        'student_id' => $student_id,
        'last_name' => $last_name,
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'email' => $email,
        'contact_no' => $contact_no,
        'address' => $address,
        'strand' => $strand,
        'program' => $program,
        'admission_date' => $admission_date
    ], $hashed_password, $picture_db_path);

    if ($createResult['success']) {
        $successMessage = $createResult['message'];
        if (function_exists('sevIsCvsuEmail') && sevIsCvsuEmail($email)) {
            $successMessage .= ' After your first successful login, you will be asked to verify your CvSU email with a one-time code.';
        }

        $logContext = [
            'student_id' => $student_id,
            'email' => $email,
            'status' => $createResult['status'],
            'picture_uploaded' => ($picture_db_path !== SRS_DEFAULT_PICTURE)
        ];
        elsInfo('Student account created successfully', $logContext, 'student_registration');
        echo json_encode(['status' => 'success', 'message' => $successMessage]);
    } else {
        elsError('Failed to create student account', ['error' => $createResult['error']], 'student_registration');
        echo json_encode(['status' => 'error', 'message' => $createResult['error']]);
    }

} catch (Exception $e) {
    elsError('Student registration exception', ['error' => $e->getMessage()], 'student_registration', $e);
    echo json_encode(['status' => 'error', 'message' => 'An error occurred during registration. Please try again.']);
} finally {
    // Close database connection
    closeDBConnection($conn);
}
?>
