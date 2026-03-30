<?php
/**
 * Student Profile Service
 * Handles student profile updates, picture uploads, and session synchronization
 */

// Allowed fields for profile updates
define('SPS_ALLOWED_FIELDS', [
    'last_name',
    'first_name',
    'middle_name',
    'email',
    'password',
    'contact_no',
    'address',
    'admission_date'
]);

// Field mapping from POST to database columns
define('SPS_FIELD_MAP', [
    'last_name' => 'last_name',
    'first_name' => 'first_name',
    'middle_name' => 'middle_name',
    'email' => 'email',
    'password' => 'password',
    'contact_no' => 'contact_number',
    'address' => 'house_number_street',
    'admission_date' => 'date_of_admission'
]);

/**
 * Validate profile update fields
 * 
 * @param mysqli $conn Database connection
 * @param array $formData POST data
 * @return array ['valid' => bool, 'error' => string|null, 'validated_fields' => array]
 */
function spsValidateProfileUpdate($conn, array $formData): array {
    $validatedFields = [];
    $errors = [];

    // Check email if provided
    if (isset($formData['email']) && !empty($formData['email'])) {
        $emailPolicy = isAllowedEmailDomain($conn, $formData['email']);
        if (!$emailPolicy['allowed']) {
            $errors[] = $emailPolicy['message'];
        } else {
            $validatedFields['email'] = htmlspecialchars(trim($formData['email']));
        }
    }

    // Check password if provided
    if (isset($formData['password']) && !empty($formData['password'])) {
        $minimumPasswordLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
        if (strlen($formData['password']) < $minimumPasswordLength) {
            $errors[] = 'Password must be at least ' . $minimumPasswordLength . ' characters long.';
        } else {
            $validatedFields['password'] = password_hash($formData['password'], PASSWORD_BCRYPT);
        }
    }

    // Check contact number if provided
    if (isset($formData['contact_no']) && !empty($formData['contact_no'])) {
        $contactValidation = validateContactNumberInput($formData['contact_no']);
        if (!$contactValidation['valid']) {
            $errors[] = $contactValidation['message'];
        } else {
            $validatedFields['contact_no'] = $contactValidation['normalized'];
        }
    }

    // Text fields (sanitize but don't validate)
    $textFields = ['last_name', 'first_name', 'middle_name', 'address', 'admission_date'];
    foreach ($textFields as $field) {
        if (isset($formData[$field]) && $formData[$field] !== '') {
            $validatedFields[$field] = htmlspecialchars(trim($formData[$field]));
        }
    }

    if (!empty($errors)) {
        return ['valid' => false, 'error' => implode(' ', $errors), 'validated_fields' => []];
    }

    return ['valid' => true, 'error' => null, 'validated_fields' => $validatedFields];
}

/**
 * Update student profile with dynamic fields
 * 
 * @param mysqli $conn Database connection
 * @param string $studentId Student ID
 * @param array $fields Associative array of field => value pairs
 * @return array ['success' => bool, 'error' => string|null]
 */
function spsUpdateStudentProfile($conn, string $studentId, array $fields): array {
    if (empty($fields)) {
        return ['success' => false, 'error' => 'No fields to update'];
    }

    $updateParts = [];
    $params = [];
    $types = '';

    // Build dynamic UPDATE statement
    foreach ($fields as $field => $value) {
        if (!array_key_exists($field, SPS_FIELD_MAP)) {
            continue; // Skip unknown fields
        }
        
        $dbField = SPS_FIELD_MAP[$field];
        $updateParts[] = "$dbField = ?";
        $params[] = $value;
        $types .= 's';
    }

    if (empty($updateParts)) {
        return ['success' => false, 'error' => 'No valid fields to update'];
    }

    // Add student_id condition
    $params[] = $studentId;
    $types .= 's';

    $sql = "UPDATE student_info SET " . implode(', ', $updateParts) . " WHERE student_number = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error: ' . $conn->error];
    }

    if (!$stmt->bind_param($types, ...$params)) {
        return ['success' => false, 'error' => 'Bind error: ' . $stmt->error];
    }

    if (!$stmt->execute()) {
        return ['success' => false, 'error' => 'Update failed: ' . $stmt->error];
    }

    return ['success' => true, 'error' => null];
}

/**
 * Update session variables from updated profile fields
 * 
 * @param array $fields Updated field values
 * @return void
 */
function spsUpdateSessionFromFields(array $fields): void {
    // Session field mapping
    $sessionMap = [
        'last_name' => 'last_name',
        'first_name' => 'first_name',
        'middle_name' => 'middle_name',
        'email' => 'email',
        'contact_no' => 'contact_no',
        'address' => 'address'
    ];

    foreach ($fields as $field => $value) {
        if (array_key_exists($field, $sessionMap)) {
            $_SESSION[$sessionMap[$field]] = $value;
        }
    }
}

/**
 * Update student profile picture
 * 
 * @param string $studentId Student ID
 * @param array|null $fileInput $_FILES['picture'] array
 * @param mysqli $conn Database connection (optional, for DB update)
 * @return array ['success' => bool, 'path' => string|null, 'error' => string|null]
 */
function spsUpdateProfilePicture(string $studentId, ?array $fileInput, $conn = null): array {
    if (!$fileInput || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => null, 'error' => 'No file uploaded'];
    }

    // Validate file is image
    if (!getimagesize($fileInput['tmp_name'])) {
        return ['success' => false, 'path' => null, 'error' => 'File is not a valid image.'];
    }

    // Check file size (5MB max)
    if ($fileInput['size'] > 5242880) { // 5MB in bytes
        return ['success' => false, 'path' => null, 'error' => 'Picture file is too large (max 5MB).'];
    }

    // Check file type
    $ext = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($ext, $allowedTypes)) {
        return ['success' => false, 'path' => null, 'error' => 'Only JPG, JPEG, PNG & GIF files are allowed.'];
    }

    // Create uploads directory
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'path' => null, 'error' => 'Failed to create uploads directory.'];
        }
    }

    // Generate unique filename (uniqid + random bytes)
    $uniqueName = uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $filePath = $uploadDir . $uniqueName;
    $dbPath = 'uploads/' . $uniqueName;

    // Move uploaded file
    if (!move_uploaded_file($fileInput['tmp_name'], $filePath)) {
        return ['success' => false, 'path' => null, 'error' => 'Failed to upload picture.'];
    }

    // Update database if connection provided
    if ($conn) {
        $stmt = $conn->prepare("UPDATE student_info SET picture = ? WHERE student_number = ?");
        if (!$stmt) {
            return ['success' => true, 'path' => $dbPath, 'error' => 'File uploaded but database update failed: ' . $conn->error];
        }

        $stmt->bind_param('ss', $dbPath, $studentId);
        if (!$stmt->execute()) {
            return ['success' => true, 'path' => $dbPath, 'error' => 'File uploaded but database update failed: ' . $stmt->error];
        }
    }

    return ['success' => true, 'path' => $dbPath, 'error' => null];
}

/**
 * Get student profile for editing
 * 
 * @param mysqli $conn Database connection
 * @param string $studentId Student ID
 * @return array|null Student record or null if not found
 */
function spsGetStudentProfile($conn, string $studentId): ?array {
    $stmt = $conn->prepare("
        SELECT 
            student_number,
            last_name,
            first_name,
            middle_name,
            email,
            contact_number,
            house_number_street,
            picture,
            date_of_admission,
            status
        FROM student_info 
        WHERE student_number = ?
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc();
}
?>
