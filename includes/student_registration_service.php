<?php
/**
 * Service layer for student registration and account creation
 * Handles validation, file uploads, and database operations
 */

require_once __DIR__ . '/program_shift_service.php';
require_once __DIR__ . '/student_profile_service.php';
require_once __DIR__ . '/student_masterlist_service.php';

const SRS_MAX_PICTURE_SIZE = 5000000; // 5MB
const SRS_ALLOWED_IMAGE_TYPES = ['jpg', 'png', 'jpeg', 'gif'];
const SRS_DEFAULT_PICTURE = 'pix/anonymous.jpg';

function srsEnsureRejectionLogTable($conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS student_rejection_log (
        student_number VARCHAR(50) PRIMARY KEY,
        rejected_at DATETIME NOT NULL,
        rejected_by VARCHAR(120) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";

    if ($conn instanceof PDO) {
        $conn->exec($sql);
        return;
    }

    if (is_object($conn) && method_exists($conn, 'query')) {
        $conn->query($sql);
    }
}

function srsRecordStudentRejection($conn, string $studentId, ?string $rejectedBy = null): void {
    srsEnsureRejectionLogTable($conn);

    if ($conn instanceof PDO) {
        $stmt = $conn->prepare("
            INSERT INTO student_rejection_log (student_number, rejected_at, rejected_by, updated_at)
            VALUES (?, NOW(), ?, NOW())
            ON DUPLICATE KEY UPDATE rejected_at = NOW(), rejected_by = VALUES(rejected_by), updated_at = NOW()
        ");
        $stmt->execute([$studentId, $rejectedBy]);
        return;
    }

    if (is_object($conn) && method_exists($conn, 'prepare')) {
        $stmt = $conn->prepare("
            INSERT INTO student_rejection_log (student_number, rejected_at, rejected_by, updated_at)
            VALUES (?, NOW(), ?, NOW())
            ON DUPLICATE KEY UPDATE rejected_at = NOW(), rejected_by = VALUES(rejected_by), updated_at = NOW()
        ");
        if ($stmt && method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('ss', $studentId, $rejectedBy);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function srsClearStudentRejectionLog($conn, string $studentId): void {
    srsEnsureRejectionLogTable($conn);

    if ($conn instanceof PDO) {
        $stmt = $conn->prepare('DELETE FROM student_rejection_log WHERE student_number = ?');
        $stmt->execute([$studentId]);
        return;
    }

    if (is_object($conn) && method_exists($conn, 'prepare')) {
        $stmt = $conn->prepare('DELETE FROM student_rejection_log WHERE student_number = ?');
        if ($stmt && method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function srsDeleteStudentRowsByColumn($conn, string $tableName, string $columnName, string $studentId): bool {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return false;
    }

    if (function_exists('psTableExists') && !psTableExists($conn, $tableName)) {
        return true;
    }

    $safeTableName = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    $safeColumnName = preg_replace('/[^A-Za-z0-9_]/', '', $columnName);
    if ($safeTableName === '' || $safeColumnName === '') {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM `{$safeTableName}` WHERE `{$safeColumnName}` = ?");
    if (!$stmt || !method_exists($stmt, 'bind_param')) {
        return false;
    }

    $stmt->bind_param('s', $studentId);
    $executed = $stmt->execute();
    $stmt->close();

    return (bool) $executed;
}

function srsFetchProgramShiftRequestIds($conn, string $studentId): array {
    $requestIds = [];

    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return $requestIds;
    }

    if (function_exists('psTableExists') && !psTableExists($conn, 'program_shift_requests')) {
        return $requestIds;
    }

    $stmt = $conn->prepare('SELECT id FROM program_shift_requests WHERE student_number = ?');
    if (!$stmt || !method_exists($stmt, 'bind_param')) {
        return $requestIds;
    }

    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $requestId = (int)($row['id'] ?? 0);
        if ($requestId > 0) {
            $requestIds[] = $requestId;
        }
    }
    $stmt->close();

    return $requestIds;
}

function srsDeleteProgramShiftRowsByRequestIds($conn, string $tableName, array $requestIds): bool {
    if (empty($requestIds)) {
        return true;
    }

    if (!is_object($conn) || !method_exists($conn, 'query')) {
        return false;
    }

    if (function_exists('psTableExists') && !psTableExists($conn, $tableName)) {
        return true;
    }

    $safeTableName = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if ($safeTableName === '') {
        return false;
    }

    $requestIds = array_values(array_filter(array_map('intval', $requestIds), static function ($value) {
        return $value > 0;
    }));
    if (empty($requestIds)) {
        return true;
    }

    $idList = implode(',', $requestIds);
    return (bool) $conn->query("DELETE FROM `{$safeTableName}` WHERE request_id IN ({$idList})");
}

function srsPurgeOrphanedStudentArtifacts($conn, string $studentId): array {
    if (!is_object($conn) || !method_exists($conn, 'prepare')) {
        return ['success' => false, 'error' => 'Invalid database connection type'];
    }

    $studentId = trim($studentId);
    if ($studentId === '') {
        return ['success' => false, 'error' => 'Student ID is required'];
    }

    $requestIds = srsFetchProgramShiftRequestIds($conn, $studentId);

    $deleteTargets = [
        ['table' => 'student_checklists', 'column' => 'student_id'],
        ['table' => 'student_study_plan_overrides', 'column' => 'student_id'],
        ['table' => 'student_study_plan_course_additions', 'column' => 'student_id'],
        ['table' => 'student_email_verifications', 'column' => 'student_number'],
        ['table' => 'student_rejection_log', 'column' => 'student_number'],
        ['table' => 'password_history', 'column' => 'student_number'],
        ['table' => 'program_shift_credit_map', 'column' => 'student_number'],
    ];

    foreach ($deleteTargets as $target) {
        if (!srsDeleteStudentRowsByColumn($conn, (string)$target['table'], (string)$target['column'], $studentId)) {
            return [
                'success' => false,
                'error' => 'Failed to clear stale student data from ' . $target['table'] . '.',
            ];
        }
    }

    foreach (['program_shift_approvals', 'program_shift_audit'] as $tableName) {
        if (!srsDeleteProgramShiftRowsByRequestIds($conn, $tableName, $requestIds)) {
            return [
                'success' => false,
                'error' => 'Failed to clear stale student shift history from ' . $tableName . '.',
            ];
        }
    }

    if (!srsDeleteStudentRowsByColumn($conn, 'program_shift_requests', 'student_number', $studentId)) {
        return [
            'success' => false,
            'error' => 'Failed to clear stale student data from program_shift_requests.',
        ];
    }

    return ['success' => true, 'error' => null];
}

function srsLoadExistingStudentRegistrationRow($conn, string $studentId): ?array {
    if ($conn instanceof PDO) {
        $stmt = $conn->prepare('SELECT student_number, status FROM student_info WHERE student_number = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    if (is_object($conn) && method_exists($conn, 'prepare')) {
        $stmt = $conn->prepare('SELECT student_number, status FROM student_info WHERE student_number = ? LIMIT 1');
        if ($stmt && method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            return is_array($row) ? $row : null;
        }
    }

    return null;
}

function srsLoadStudentRejectionLog($conn, string $studentId): ?array {
    srsEnsureRejectionLogTable($conn);

    if ($conn instanceof PDO) {
        $stmt = $conn->prepare('SELECT student_number, rejected_at, rejected_by FROM student_rejection_log WHERE student_number = ? LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    if (is_object($conn) && method_exists($conn, 'prepare')) {
        $stmt = $conn->prepare('SELECT student_number, rejected_at, rejected_by FROM student_rejection_log WHERE student_number = ? LIMIT 1');
        if ($stmt && method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            return is_array($row) ? $row : null;
        }
    }

    return null;
}

function srsGetRegistrationAvailability($conn, string $studentId): array {
    $existing = srsLoadExistingStudentRegistrationRow($conn, $studentId);
    if (!is_array($existing)) {
        return ['allowed' => true, 'reapply' => false, 'message' => ''];
    }

    $status = strtolower(trim((string)($existing['status'] ?? '')));
    if ($status === 'archived') {
        return [
            'allowed' => false,
            'reapply' => false,
            'message' => 'This account has been archived. Please contact the administrator if access needs to be restored.',
        ];
    }

    if ($status !== 'rejected') {
        return [
            'allowed' => false,
            'reapply' => false,
            'message' => 'Student number already exists in the system.',
        ];
    }

    $cooldownDays = policySettingInt($conn, 'rejection_cooldown_days', 0, 0, 365);
    if ($cooldownDays <= 0) {
        return ['allowed' => true, 'reapply' => true, 'message' => ''];
    }

    $rejectionLog = srsLoadStudentRejectionLog($conn, $studentId);
    $rejectedAtRaw = (string)($rejectionLog['rejected_at'] ?? '');
    $rejectedAtTs = $rejectedAtRaw !== '' ? strtotime($rejectedAtRaw) : false;

    if ($rejectedAtTs === false) {
        return ['allowed' => true, 'reapply' => true, 'message' => ''];
    }

    $eligibleAt = strtotime('+' . $cooldownDays . ' days', $rejectedAtTs);
    if ($eligibleAt !== false && $eligibleAt > time()) {
        return [
            'allowed' => false,
            'reapply' => false,
            'message' => 'This account was rejected. You may re-apply after ' . date('M d, Y h:i A', $eligibleAt) . '.',
        ];
    }

    return ['allowed' => true, 'reapply' => true, 'message' => ''];
}
/**
 * Validate all student registration fields
 */
function srsValidateRegistration($conn, array $formData): array {
    $errors = [];
    $allowedStatuses = ['regular', 'irregular'];
    $allowedRegistrationClassifications = ['old', 'new', 'transferee'];

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
    $required = ['student_id', 'last_name', 'first_name', 'email', 'password', 'contact_no', 'strand', 'program', 'admission_date', 'stud_classification', 'registration_classification'];
    foreach ($required as $field) {
        if (empty($formData[$field] ?? '')) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }

    if (!empty($errors)) {
        return ['valid' => false, 'error' => implode(' ', $errors)];
    }

    $statusClassification = strtolower(trim((string)($formData['stud_classification'] ?? '')));
    if ($statusClassification === '') {
        $statusClassification = 'regular';
    }

    if (!in_array($statusClassification, $allowedStatuses, true)) {
        return ['valid' => false, 'error' => 'Student status must be Regular or Irregular.'];
    }

    $registrationClassification = strtolower(trim((string)($formData['registration_classification'] ?? '')));
    if ($registrationClassification === '') {
        $registrationClassification = 'new';
    }

    if (!in_array($registrationClassification, $allowedRegistrationClassifications, true)) {
        return ['valid' => false, 'error' => 'Student classification must be Old, New, or Transferee.'];
    }

    $masterlistValidation = smlValidateStudentRegistrationAgainstMasterlist($conn, $formData);
    if (!$masterlistValidation['valid']) {
        return ['valid' => false, 'error' => $masterlistValidation['message']];
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

    return [
        'valid' => true,
        'contact_normalized' => $contactValidation['normalized'],
        'registration_classification_normalized' => $registrationClassification === 'transferee'
            ? 'Transferee'
            : ($registrationClassification === 'old' ? 'Old' : 'New'),
        'classification_normalized' => $statusClassification === 'irregular'
            ? 'Irregular'
            : 'Regular'
    ];
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
        $availability = srsGetRegistrationAvailability($conn, (string)$formData['student_id']);
        if (!$availability['allowed']) {
            return ['success' => false, 'error' => $availability['message']];
        }

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
        $registration_classification = trim((string)($formData['registration_classification'] ?? ''));
        if ($registration_classification === '') {
            $registration_classification = strcasecmp((string)($formData['stud_classification'] ?? ''), 'Transferee') === 0
                ? 'Transferee'
                : 'New';
        }

        $stud_classification = trim((string)($formData['stud_classification'] ?? ''));
        if ($stud_classification === '') {
            $stud_classification = strcasecmp($registration_classification, 'Transferee') === 0
                ? 'Transferee'
                : 'Regular';
        }
        $admission_date = $formData['admission_date'];
        $curriculum_year = function_exists('psResolveLatestCurriculumYear')
            ? psResolveLatestCurriculumYear($conn, $program)
            : '';
        if ($curriculum_year === '') {
            $curriculum_year = null;
        }
        $hasRegistrationClassification = spsEnsureRegistrationClassificationColumn($conn);

        if (!empty($availability['reapply'])) {
            $sql = "UPDATE student_info
                SET last_name = ?, first_name = ?, middle_name = ?, email = ?, password = ?, contact_number = ?, house_number_street = ?, strand = ?, program = ?, stud_classification = ?";
            $params = [
                $last_name,
                $first_name,
                $middle_name,
                $email,
                $hashedPassword,
                $contact_no,
                $address,
                $strand,
                $program,
                $stud_classification,
            ];

            if ($hasRegistrationClassification) {
                $sql .= ", registration_classification = ?";
                $params[] = $registration_classification;
            }

            $sql .= ", curriculum_year = ?, date_of_admission = ?, picture = ?, status = ?, approved_by = NULL
                WHERE student_number = ?";
            $params[] = $curriculum_year;
            $params[] = $admission_date;
            $params[] = $picturePath;
            $params[] = $status;
            $params[] = $student_id;
        } else {
            // When a student row was manually deleted but related history tables were not,
            // reset leftover artifacts so a reused student number starts with a clean record.
            $purgeResult = srsPurgeOrphanedStudentArtifacts($conn, $student_id);
            if (!$purgeResult['success']) {
                return ['success' => false, 'error' => (string)($purgeResult['error'] ?? 'Failed to clear stale student data.')];
            }

            $columns = [
                'student_number',
                'last_name',
                'first_name',
                'middle_name',
                'email',
                'password',
                'contact_number',
                'house_number_street',
                'strand',
                'program',
                'stud_classification',
            ];
            $params = [
                $student_id,
                $last_name,
                $first_name,
                $middle_name,
                $email,
                $hashedPassword,
                $contact_no,
                $address,
                $strand,
                $program,
                $stud_classification,
            ];

            if ($hasRegistrationClassification) {
                $columns[] = 'registration_classification';
                $params[] = $registration_classification;
            }

            $columns = array_merge($columns, [
                'curriculum_year',
                'date_of_admission',
                'picture',
                'status',
            ]);
            $params[] = $curriculum_year;
            $params[] = $admission_date;
            $params[] = $picturePath;
            $params[] = $status;

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO student_info (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        }

        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error: ' . ($conn->error ?? 'prepare failed')];
        }

        $bind_result = $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        
        if (!$bind_result) {
            return ['success' => false, 'error' => 'Database error: ' . ($stmt->error ?? 'bind failed')];
        }
        
        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Error saving data: ' . ($stmt->error ?? 'execute failed')];
        }
        
        $stmt->close();
        srsClearStudentRejectionLog($conn, $student_id);
        
        // Record password history
        recordPasswordHistory($conn, $student_id, $hashedPassword);
        
        return [
            'success' => true,
            'status' => $status,
            'message' => $status === 'approved' 
                ? (!empty($availability['reapply'])
                    ? 'Student account re-applied and approved successfully. You can now login.'
                    : 'Student account created and approved successfully. You can now login.')
                : (!empty($availability['reapply'])
                    ? 'Student account re-applied successfully. Your account is pending approval.'
                    : 'Student data saved successfully. Your account is pending approval.')
        ];
    }
    
    return ['success' => false, 'error' => 'Invalid database connection type'];
}
?>
