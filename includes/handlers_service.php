<?php
/**
 * Handler response and validation standardization service
 * Provides common patterns for API handlers to ensure consistency
 */

/**
 * Send standardized JSON success response
 */
function hsSuccessResponse(string $message, array $data = [], int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Send standardized JSON error response
 */
function hsErrorResponse(string $message, int $statusCode = 400): void {
    http_response_code($statusCode);
    echo json_encode([
        'status' => 'error',
        'message' => $message
    ]);
}

/**
 * Validate that all required POST fields exist and are not empty
 */
function hsValidateRequiredFields(array $requiredFields, array $postData = []): array {
    if (empty($postData)) {
        $postData = $_POST;
    }

    $missing = [];
    $values = [];

    foreach ($requiredFields as $field) {
        if (!isset($postData[$field])) {
            $missing[] = $field;
            continue;
        }

        $value = trim((string)$postData[$field]);
        if (empty($value)) {
            $missing[] = $field;
            continue;
        }

        $values[$field] = $value;
    }

    return [
        'valid' => empty($missing),
        'missing' => $missing,
        'values' => $values
    ];
}

/**
 * Validate string length constraints
 */
function hsValidateStringLength(string $value, int $minLength = 1, int $maxLength = PHP_INT_MAX, string $fieldName = 'Field'): array {
    $len = strlen($value);

    if ($len < $minLength) {
        return [
            'valid' => false,
            'message' => "$fieldName must be at least $minLength character(s) long."
        ];
    }

    if ($len > $maxLength) {
        return [
            'valid' => false,
            'message' => "$fieldName must not exceed $maxLength characters."
        ];
    }

    return ['valid' => true, 'message' => ''];
}

/**
 * Validate email format
 */
function hsValidateEmail(string $email, string $fieldName = 'Email'): array {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'valid' => false,
            'message' => "$fieldName must be a valid email address."
        ];
    }

    return ['valid' => true, 'message' => ''];
}

/**
 * Validate that a database username doesn't already exist
 */
function hsValidateUniqueUsername($conn, string $username, string $table, string $idField = 'id'): array {
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("SELECT $idField FROM $table WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
    } elseif ($conn instanceof PDO) {
        $stmt = $conn->prepare("SELECT $idField FROM $table WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $exists = $stmt->rowCount() > 0;
    } else {
        return ['valid' => false, 'message' => 'Invalid database connection type.'];
    }

    if ($exists) {
        return [
            'valid' => false,
            'message' => 'Username already exists. Please choose a different username.'
        ];
    }

    return ['valid' => true, 'message' => ''];
}

/**
 * Resolve which table variant exists (singular or plural form)
 * Useful for tables like program_coordinator vs program_coordinators
 */
function hsResolveTableVariant($conn, string $singularName): ?string {
    if ($conn instanceof mysqli) {
        // Try singular first
        $result = $conn->query("SHOW TABLES LIKE '$singularName'");
        if ($result && $result->num_rows > 0) {
            return $singularName;
        }

        // Try plural
        $pluralName = $singularName . 's';
        $result = $conn->query("SHOW TABLES LIKE '$pluralName'");
        if ($result && $result->num_rows > 0) {
            return $pluralName;
        }
    } elseif ($conn instanceof PDO) {
        $tables = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($singularName, $tables, true)) {
            return $singularName;
        }
        $pluralName = $singularName . 's';
        if (in_array($pluralName, $tables, true)) {
            return $pluralName;
        }
    }

    return null;
}

/**
 * Check if a table has a specific column
 */
function hsTableHasColumn($conn, string $table, string $column): bool {
    if ($conn instanceof mysqli) {
        $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result && $result->num_rows > 0;
    } elseif ($conn instanceof PDO) {
        $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result->rowCount() > 0;
    }

    return false;
}

/**
 * Hash password with default algorithm
 */
function hsHashPassword(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password matches hash
 */
function hsVerifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * Set JSON response header (call once at handler start)
 */
function hsSetJsonHeader(): void {
    header('Content-Type: application/json');
}

/**
 * Safe exit after sending JSON response
 */
function hsExitJson(): void {
    exit;
}
?>
