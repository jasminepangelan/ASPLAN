<?php
/**
 * Validation Service - Centralized form field and policy validation
 * Consolidates duplicate validation logic across all handlers and services
 */

require_once __DIR__ . '/../includes/security_policy.php';

// Validation result constants
define('VAS_VALID', true);
define('VAS_INVALID', false);

/**
 * Validate email against allowed domains
 * 
 * @param mysqli $conn Database connection
 * @param string $email Email to validate
 * @return array ['valid' => bool, 'message' => string, 'error' => string|null]
 */
function vasValidateEmail($conn, string $email): array {
    if (empty($email)) {
        return ['valid' => VAS_INVALID, 'message' => 'Email is required.', 'error' => 'empty_email'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => VAS_INVALID, 'message' => 'Invalid email format.', 'error' => 'invalid_email_format'];
    }

    $emailPolicy = isAllowedEmailDomain($conn, $email);
    if (!$emailPolicy['allowed']) {
        return ['valid' => VAS_INVALID, 'message' => $emailPolicy['message'], 'error' => 'email_domain_not_allowed'];
    }

    return ['valid' => VAS_VALID, 'message' => 'Email is valid.', 'error' => null];
}

/**
 * Validate password strength
 * 
 * @param mysqli $conn Database connection
 * @param string $password Password to validate
 * @param int|null $minLength Override minimum length (optional)
 * @return array ['valid' => bool, 'length_required' => int, 'message' => string, 'error' => string|null]
 */
function vasValidatePassword($conn, string $password, ?int $minLength = null): array {
    if (empty($password)) {
        return ['valid' => VAS_INVALID, 'length_required' => 0, 'message' => 'Password is required.', 'error' => 'empty_password'];
    }

    if ($minLength === null) {
        $minLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
    }

    if (strlen($password) < $minLength) {
        return [
            'valid' => VAS_INVALID,
            'length_required' => $minLength,
            'message' => "Password must be at least $minLength characters long.",
            'error' => 'password_too_short'
        ];
    }

    // Optional: Additional password strength checks can be added here
    // - Uppercase/lowercase/numbers/special chars requirements
    // - Dictionary checks
    // - Common password list

    return ['valid' => VAS_VALID, 'length_required' => $minLength, 'message' => 'Password is valid.', 'error' => null];
}

/**
 * Validate and normalize contact number
 * 
 * @param string $contactNo Contact number to validate
 * @return array ['valid' => bool, 'normalized' => string|null, 'message' => string, 'error' => string|null]
 */
function vasValidateContactNumber(string $contactNo): array {
    if (empty($contactNo)) {
        return ['valid' => VAS_INVALID, 'normalized' => null, 'message' => 'Contact number is required.', 'error' => 'empty_contact'];
    }

    $validation = validateContactNumberInput($contactNo);
    
    if (!$validation['valid']) {
        return array_merge(
            $validation,
            ['error' => 'invalid_contact_format', 'normalized' => null]
        );
    }

    return [
        'valid' => VAS_VALID,
        'normalized' => $validation['normalized'],
        'message' => 'Contact number is valid.',
        'error' => null
    ];
}

/**
 * Validate required text fields (name, address, etc.)
 * 
 * @param array $fields Associative array of field_name => value
 * @param array $required Array of required field names
 * @return array ['valid' => bool, 'missing_fields' => array, 'message' => string, 'error' => string|null]
 */
function vasValidateRequiredFields(array $fields, array $required): array {
    $missingFields = [];
    
    foreach ($required as $fieldName) {
        $value = $fields[$fieldName] ?? '';
        if (empty(trim((string)$value))) {
            $missingFields[] = $fieldName;
        }
    }

    if (!empty($missingFields)) {
        return [
            'valid' => VAS_INVALID,
            'missing_fields' => $missingFields,
            'message' => 'Missing required fields: ' . implode(', ', $missingFields),
            'error' => 'missing_required_fields'
        ];
    }

    return [
        'valid' => VAS_VALID,
        'missing_fields' => [],
        'message' => 'All required fields present.',
        'error' => null
    ];
}

/**
 * Sanitize text input (HTML escaping + trimming)
 * 
 * @param string $input Raw input string
 * @param bool $allowNull If true, empty strings return null instead of empty string
 * @return string|null Sanitized string
 */
function vasSanitizeText(string $input, bool $allowNull = false): ?string {
    $sanitized = htmlspecialchars(trim($input));
    
    if ($allowNull && $sanitized === '') {
        return null;
    }
    
    return $sanitized;
}

/**
 * Sanitize and validate name field (name cannot contain numbers or special chars)
 * 
 * @param string $name Name to validate
 * @param string $fieldLabel Field label for error messages
 * @return array ['valid' => bool, 'sanitized' => string|null, 'message' => string, 'error' => string|null]
 */
function vasSanitizeName(string $name, string $fieldLabel = 'Name'): array {
    if (empty($name)) {
        return ['valid' => VAS_INVALID, 'sanitized' => null, 'message' => "$fieldLabel is required.", 'error' => 'empty_name'];
    }

    $sanitized = vasSanitizeText($name);

    // Check for invalid characters (allow letters, spaces, hyphens, apostrophes)
    if (!preg_match("/^[\p{L}\s\-']+$/u", $sanitized)) {
        return [
            'valid' => VAS_INVALID,
            'sanitized' => $sanitized,
            'message' => "$fieldLabel contains invalid characters. Only letters, spaces, hyphens, and apostrophes are allowed.",
            'error' => 'invalid_name_characters'
        ];
    }

    return [
        'valid' => VAS_VALID,
        'sanitized' => $sanitized,
        'message' => "$fieldLabel is valid.",
        'error' => null
    ];
}

/**
 * Validate and sanitize date field
 * 
 * @param string $date Date string to validate
 * @param string $format Expected format (e.g., 'Y-m-d')
 * @return array ['valid' => bool, 'sanitized' => string|null, 'message' => string, 'error' => string|null]
 */
function vasValidateDate(string $date, string $format = 'Y-m-d'): array {
    if (empty($date)) {
        return ['valid' => VAS_INVALID, 'sanitized' => null, 'message' => 'Date is required.', 'error' => 'empty_date'];
    }

    try {
        $d = DateTime::createFromFormat($format, $date);
        if (!$d || $d->format($format) !== $date) {
            return [
                'valid' => VAS_INVALID,
                'sanitized' => null,
                'message' => "Date must be in $format format.",
                'error' => 'invalid_date_format'
            ];
        }
    } catch (Exception $e) {
        return [
            'valid' => VAS_INVALID,
            'sanitized' => null,
            'message' => "Date must be in $format format.",
            'error' => 'invalid_date_format'
        ];
    }

    return [
        'valid' => VAS_VALID,
        'sanitized' => $d->format($format),
        'message' => 'Date is valid.',
        'error' => null
    ];
}

/**
 * Batch validate multiple fields
 * Useful for validating entire form submissions
 * 
 * @param mysqli $conn Database connection
 * @param array $data Form data to validate
 * @param array $schema Validation schema [field => ['type' => 'email|password|contact|text|date|name', 'required' => bool, ...]]
 * @return array ['valid' => bool, 'errors' => array, 'sanitized_data' => array]
 */
function vasValidateBatch($conn, array $data, array $schema): array {
    $errors = [];
    $sanitized = [];

    foreach ($schema as $fieldName => $config) {
        $value = $data[$fieldName] ?? '';
        $type = $config['type'] ?? 'text';
        $required = $config['required'] ?? false;

        if (empty($value) && $required) {
            $errors[$fieldName] = 'This field is required.';
            continue;
        }

        if (empty($value) && !$required) {
            $sanitized[$fieldName] = null;
            continue;
        }

        // Validate by type
        switch ($type) {
            case 'email':
                $result = vasValidateEmail($conn, $value);
                if (!$result['valid']) {
                    $errors[$fieldName] = $result['message'];
                } else {
                    $sanitized[$fieldName] = $value; // Email already validated
                }
                break;

            case 'password':
                $result = vasValidatePassword($conn, $value);
                if (!$result['valid']) {
                    $errors[$fieldName] = $result['message'];
                } else {
                    $sanitized[$fieldName] = password_hash($value, PASSWORD_BCRYPT);
                }
                break;

            case 'contact':
                $result = vasValidateContactNumber($value);
                if (!$result['valid']) {
                    $errors[$fieldName] = $result['message'];
                } else {
                    $sanitized[$fieldName] = $result['normalized'];
                }
                break;

            case 'date':
                $format = $config['format'] ?? 'Y-m-d';
                $result = vasValidateDate($value, $format);
                if (!$result['valid']) {
                    $errors[$fieldName] = $result['message'];
                } else {
                    $sanitized[$fieldName] = $result['sanitized'];
                }
                break;

            case 'name':
                $label = $config['label'] ?? 'Name';
                $result = vasSanitizeName($value, $label);
                if (!$result['valid']) {
                    $errors[$fieldName] = $result['message'];
                } else {
                    $sanitized[$fieldName] = $result['sanitized'];
                }
                break;

            case 'text':
            default:
                $sanitized[$fieldName] = vasSanitizeText($value);
                break;
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'sanitized_data' => $sanitized
    ];
}

/**
 * Record password in password history
 * Wrapper around security_policy function with logging
 * 
 * @param mysqli $conn Database connection
 * @param string $userId User ID (student number)
 * @param string $hashedPassword Bcrypt hashed password
 * @return array ['success' => bool, 'error' => string|null]
 */
function vasRecordPasswordHistory($conn, string $userId, string $hashedPassword): array {
    try {
        recordPasswordHistory($conn, $userId, $hashedPassword);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Validate student ID (check format and optionally check uniqueness)
 * 
 * @param mysqli $conn Database connection
 * @param string $studentId Student ID to validate
 * @param bool $checkUniqueness If true, check if student_id already exists
 * @return array ['valid' => bool, 'exists' => bool, 'message' => string, 'error' => string|null]
 */
function vasValidateStudentId($conn, string $studentId, bool $checkUniqueness = false): array {
    if (empty($studentId)) {
        return ['valid' => VAS_INVALID, 'exists' => false, 'message' => 'Student ID is required.', 'error' => 'empty_student_id'];
    }

    // Basic format check: should be alphanumeric
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $studentId)) {
        return [
            'valid' => VAS_INVALID,
            'exists' => false,
            'message' => 'Student ID contains invalid characters.',
            'error' => 'invalid_student_id_format'
        ];
    }

    if ($checkUniqueness) {
        $stmt = $conn->prepare("SELECT student_number FROM student_info WHERE student_number = ?");
        if (!$stmt) {
            return ['valid' => VAS_INVALID, 'exists' => false, 'message' => 'Database error.', 'error' => 'db_error'];
        }

        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();

        if ($exists) {
            return ['valid' => VAS_INVALID, 'exists' => true, 'message' => 'Student ID already exists.', 'error' => 'student_id_exists'];
        }
    }

    return ['valid' => VAS_VALID, 'exists' => false, 'message' => 'Student ID is valid.', 'error' => null];
}
?>
