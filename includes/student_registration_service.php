<?php
/**
 * Service layer for student registration and account creation
 * Handles validation, file uploads, and database operations
 */

require_once __DIR__ . '/program_shift_service.php';
require_once __DIR__ . '/student_profile_service.php';

const SRS_MAX_PICTURE_SIZE = 5000000; // 5MB
const SRS_ALLOWED_IMAGE_TYPES = ['jpg', 'png', 'jpeg', 'gif'];
const SRS_DEFAULT_PICTURE = 'pix/anonymous.jpg';
/**
 * Validate all student registration fields
 */
function srsValidateRegistration($conn, array $formData): array {
    $errors = [];

    // Check if registration is disabled
    if (isRegistrationDisabled($conn)) {
        return ['valid' => false, 'error' => 'Registration is temporarily disabled by the administrator. Please try again later.'];
    }

    // Check registration window
    $windowStatus = isRegistrationWindowOpen($conn);
    if (!$windowStatus['open']) {
        return ['valid' => false, 'error' => $windowStatus['message']];
    }

    // Validate required fields
    $required = ['student_id', 'last_name', 'first_name', 'email', 'password', 'contact_no', 'strand', 'program', 'admission_date'];
    foreach ($required as $field) {
        if (empty($formData[$field] ?? '')) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }

    if (!empty($errors)) {
        return ['valid' => false, 'error' => implode(' ', $errors)];
    }

    // Validate email domain
    $email = trim($formData['email']);
    $emailPolicy = isAllowedEmailDomain($conn, $email);
    if (!$emailPolicy['allowed']) {
        return ['valid' => false, 'error' => $emailPolicy['message']];
    }

    // Validate password length
    $password = $formData['password'];
    $minimumPasswordLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
    if (strlen($password) < $minimumPasswordLength) {
        return ['valid' => false, 'error' => 'Password must be at least ' . $minimumPasswordLength . ' characters long.'];
    }

    // Validate contact number
    $contact_no = $formData['contact_no'];
    $contactValidation = validateContactNumberInput($contact_no);
    if (!$contactValidation['valid']) {
        return ['valid' => false, 'error' => $contactValidation['message']];
    }

    return ['valid' => true, 'contact_normalized' => $contactValidation['normalized']];
}

/**
 * Validate and process file upload
 */
function srsProcessPictureUpload(): array {
    if (!isset($_FILES['picture']) || $_FILES['picture']['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => SRS_DEFAULT_PICTURE];
    }

    $isRailwayRuntime = trim((string) (getenv('RAILWAY_ENVIRONMENT_ID') ?: getenv('RAILWAY_PROJECT_NAME') ?: '')) !== '';
    if ($isRailwayRuntime && !hasPersistentUploadStorage()) {
        return ['success' => false, 'error' => 'Profile picture storage is not configured for persistence yet. Please contact the administrator to attach a persistent uploads volume.'];
    }

    if ($_FILES['picture']['size'] === 0) {
        return ['success' => true, 'path' => SRS_DEFAULT_PICTURE];
    }

    $validation = spsValidateUploadedImageFile($_FILES['picture']);
    if (!$validation['valid']) {
        return ['success' => false, 'error' => (string) $validation['error']];
    }

    // Create upload directory if needed
    $target_dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads/');
    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0755, true)) {
            return ['success' => false, 'error' => 'Could not create upload directory.'];
        }
    }

    // Generate unique filename to prevent overwrites
    $image_file_type = (string) ($validation['extension'] ?? 'jpg');
    $unique_filename = uniqid('student_', true) . '_' . bin2hex(random_bytes(8)) . '.' . $image_file_type;
    $target_file = rtrim($target_dir, "/\\") . DIRECTORY_SEPARATOR . $unique_filename;

    // Move uploaded file
    if (!move_uploaded_file($_FILES['picture']['tmp_name'], $target_file)) {
        return ['success' => false, 'error' => 'There was an error uploading your file. Please try again.'];
    }

    @chmod($target_file, 0644);

    $publicSubdir = defined('UPLOAD_PUBLIC_SUBDIR') ? trim((string) UPLOAD_PUBLIC_SUBDIR, "/\\") : 'uploads';
    return ['success' => true, 'path' => $publicSubdir . '/' . $unique_filename];
}

/**
 * Check if student ID already exists
 */
function srsStudentIdExists($conn, string $student_id): bool {
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ? LIMIT 1");
        $stmt->execute([$student_id]);
        return $stmt->rowCount() > 0;
    }

    if (is_object($conn) && method_exists($conn, 'prepare')) {
        $stmt = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        if (method_exists($stmt, 'bind_param')) {
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result && (int)($result->num_rows ?? 0) > 0;
            $stmt->close();
            return $exists;
        }
    }

    return false;
}

/**
 * Get auto-approval setting
 */
function srsIsAutoApprovalEnabled($conn): bool {
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_students' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && (string)$result['setting_value'] === '1';
    }

    if (is_object($conn) && method_exists($conn, 'query')) {
        $query = "SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_students' ORDER BY id DESC LIMIT 1";
        $result = $conn->query($query);
        if ($result && (int)($result->num_rows ?? 0) > 0) {
            $row = $result->fetch_assoc();
            return (string)($row['setting_value'] ?? '') === '1';
        }
    }

    return false;
}

/**
 * Create student account in database
 */
function srsCreateStudentAccount($conn, array $formData, string $hashedPassword, string $picturePath): array {
    if ($conn instanceof PDO) {
        return ['success' => false, 'error' => 'PDO registration path is not enabled for this handler.'];
    }

    if (is_object($conn) && method_exists($conn, 'prepare')) {
        $status = srsIsAutoApprovalEnabled($conn) ? 'approved' : 'pending';
        
        $student_id = $formData['student_id'];
        $last_name = $formData['last_name'];
        $first_name = $formData['first_name'];
        $middle_name = !empty($formData['middle_name']) ? $formData['middle_name'] : null;
        $email = $formData['email'];
        $contact_no = $formData['contact_no'];
        $address = $formData['address'] ?? '';
        $strand = $formData['strand'];
        $program = $formData['program'];
        $admission_date = $formData['admission_date'];
        $curriculum_year = function_exists('psResolveLatestCurriculumYear')
            ? psResolveLatestCurriculumYear($conn, $program)
            : '';
        if ($curriculum_year === '') {
            $curriculum_year = null;
        }
        
        $stmt = $conn->prepare(
            "INSERT INTO student_info 
            (student_number, last_name, first_name, middle_name, email, password, contact_number, house_number_street, strand, program, curriculum_year, date_of_admission, picture, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error: ' . ($conn->error ?? 'prepare failed')];
        }
        
        $bind_result = $stmt->bind_param(
            "ssssssssssssss",
            $student_id, $last_name, $first_name, $middle_name, $email, $hashedPassword,
            $contact_no, $address, $strand, $program, $curriculum_year, $admission_date, $picturePath, $status
        );
        
        if (!$bind_result) {
            return ['success' => false, 'error' => 'Database error: ' . ($stmt->error ?? 'bind failed')];
        }
        
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Error saving data: ' . ($stmt->error ?? 'execute failed')];
        }
        
        $stmt->close();
        
        // Record password history
        recordPasswordHistory($conn, $student_id, $hashedPassword);
        
        return [
            'success' => true,
            'status' => $status,
            'message' => $status === 'approved' 
                ? 'Student account created and approved successfully. You can now login.' 
                : 'Student data saved successfully. Your account is pending approval.'
        ];
    }
    
    return ['success' => false, 'error' => 'Invalid database connection type'];
}
?>
