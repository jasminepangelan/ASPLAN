<?php
/**
 * Security and registration policy helpers backed by system_settings.
 */

require_once __DIR__ . '/settings.php';

if (!function_exists('policySettingString')) {
    function policySettingString($conn, $key, $default = '') {
        if (function_exists('getSystemSetting')) {
            $value = getSystemSetting($key, null);
            if ($value !== null) {
                return (string)$value;
            }
        }

        if (!$conn) {
            return (string)$default;
        }

        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_name = ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) {
            return (string)$default;
        }

        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            $stmt->close();
            return (string)$default;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || !array_key_exists('setting_value', $row)) {
            return (string)$default;
        }

        return (string)$row['setting_value'];
    }
}

if (!function_exists('policySettingInt')) {
    function policySettingInt($conn, $key, $default, $min = null, $max = null) {
        $raw = policySettingString($conn, $key, (string)$default);
        $value = is_numeric($raw) ? (int)$raw : (int)$default;

        if ($min !== null && $value < (int)$min) {
            $value = (int)$min;
        }
        if ($max !== null && $value > (int)$max) {
            $value = (int)$max;
        }

        return $value;
    }
}

if (!function_exists('policySettingBool')) {
    function policySettingBool($conn, $key, $default = false) {
        $fallback = $default ? '1' : '0';
        $raw = strtolower(trim(policySettingString($conn, $key, $fallback)));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('isRegistrationDisabled')) {
    function isRegistrationDisabled($conn) {
        return policySettingBool($conn, 'disable_new_registrations', false);
    }
}

if (!function_exists('parsePolicyDateTime')) {
    function parsePolicyDateTime($raw) {
        $value = trim((string)$raw);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime(str_replace('T', ' ', $value));
        if ($timestamp === false) {
            return null;
        }

        return $timestamp;
    }
}

if (!function_exists('getRegistrationWindowStatus')) {
    function getRegistrationWindowStatus($conn) {
        $startRaw = policySettingString($conn, 'registration_open_start', '');
        $endRaw = policySettingString($conn, 'registration_open_end', '');

        $startTs = parsePolicyDateTime($startRaw);
        $endTs = parsePolicyDateTime($endRaw);
        $now = time();

        if ($startTs !== null && $now < $startTs) {
            return [
                'open' => false,
                'message' => 'Registration has not opened yet. Please try again once the registration window starts.'
            ];
        }

        if ($endTs !== null && $now > $endTs) {
            return [
                'open' => false,
                'message' => 'Registration window is closed. Please contact the administrator for assistance.'
            ];
        }

        return ['open' => true, 'message' => ''];
    }
}

if (!function_exists('isRegistrationWindowOpen')) {
    function isRegistrationWindowOpen($conn) {
        return getRegistrationWindowStatus($conn);
    }
}

if (!function_exists('isAllowedEmailDomain')) {
    function isAllowedEmailDomain($conn, $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'allowed' => false,
                'message' => 'Please provide a valid email address.'
            ];
        }

        $domainsRaw = trim(policySettingString($conn, 'allowed_email_domains', ''));
        if ($domainsRaw === '') {
            return ['allowed' => true, 'message' => ''];
        }

        $allowedDomains = [];
        foreach (explode(',', $domainsRaw) as $domain) {
            $normalized = strtolower(trim($domain));
            if ($normalized === '') {
                continue;
            }
            if (strpos($normalized, '@') === 0) {
                $normalized = substr($normalized, 1);
            }
            $allowedDomains[] = $normalized;
        }

        if (empty($allowedDomains)) {
            return ['allowed' => true, 'message' => ''];
        }

        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        if ($emailDomain !== '' && in_array($emailDomain, $allowedDomains, true)) {
            return ['allowed' => true, 'message' => ''];
        }

        return [
            'allowed' => false,
            'message' => 'Registration is currently limited to allowed school email domains.'
        ];
    }
}

if (!function_exists('normalizeContactNumber')) {
    function normalizeContactNumber($raw) {
        $value = trim((string)$raw);
        if ($value === '') {
            return '';
        }

        $hasPlusPrefix = strpos($value, '+') === 0;
        $digitsOnly = preg_replace('/\D+/', '', $value);

        if ($digitsOnly === null) {
            return '';
        }

        return $hasPlusPrefix ? ('+' . $digitsOnly) : $digitsOnly;
    }
}

if (!function_exists('validateContactNumberInput')) {
    function validateContactNumberInput($raw) {
        $normalized = normalizeContactNumber($raw);

        if ($normalized === '') {
            return [
                'valid' => false,
                'normalized' => '',
                'message' => 'Contact number is required.'
            ];
        }

        if (!preg_match('/^\+?[0-9]{7,15}$/', $normalized)) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'message' => 'Please enter a valid contact number (7 to 15 digits, optional + prefix).'
            ];
        }

        return [
            'valid' => true,
            'normalized' => $normalized,
            'message' => ''
        ];
    }
}

if (!function_exists('ensureLoginLockoutsTable')) {
    function ensureLoginLockoutsTable($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS login_lockouts (
            login_identifier VARCHAR(120) PRIMARY KEY,
            failed_attempts INT NOT NULL DEFAULT 0,
            lockout_until DATETIME NULL,
            last_failed_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
}

if (!function_exists('getAccountLockoutStatus')) {
    function getAccountLockoutStatus($conn, $identifier) {
        ensureLoginLockoutsTable($conn);

        $stmt = $conn->prepare('SELECT lockout_until FROM login_lockouts WHERE login_identifier = ? LIMIT 1');
        if (!$stmt) {
            return ['locked' => false, 'remaining_seconds' => 0];
        }

        $stmt->bind_param('s', $identifier);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row || empty($row['lockout_until'])) {
            return ['locked' => false, 'remaining_seconds' => 0];
        }

        $remaining = strtotime($row['lockout_until']) - time();
        if ($remaining > 0) {
            return ['locked' => true, 'remaining_seconds' => $remaining];
        }

        clearAccountLockout($conn, $identifier);
        return ['locked' => false, 'remaining_seconds' => 0];
    }
}

if (!function_exists('registerFailedLoginAttempt')) {
    function registerFailedLoginAttempt($conn, $identifier) {
        ensureLoginLockoutsTable($conn);

        $maxAttempts = policySettingInt($conn, 'rate_limit_login_max_attempts', 5, 1, 100);
        $lockoutSeconds = policySettingInt($conn, 'account_lockout_duration_seconds', 900, 60, 86400);

        $stmt = $conn->prepare('SELECT failed_attempts FROM login_lockouts WHERE login_identifier = ? LIMIT 1');
        $existingAttempts = 0;
        if ($stmt) {
            $stmt->bind_param('s', $identifier);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row && isset($row['failed_attempts'])) {
                $existingAttempts = (int)$row['failed_attempts'];
            }
            $stmt->close();
        }

        $newAttempts = $existingAttempts + 1;
        $lockoutUntil = null;
        if ($newAttempts >= $maxAttempts) {
            $lockoutUntil = date('Y-m-d H:i:s', time() + $lockoutSeconds);
            $newAttempts = 0;
        }

        $upsert = $conn->prepare('INSERT INTO login_lockouts (login_identifier, failed_attempts, lockout_until, last_failed_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE failed_attempts = VALUES(failed_attempts), lockout_until = VALUES(lockout_until), last_failed_at = NOW()');
        if ($upsert) {
            $upsert->bind_param('sis', $identifier, $newAttempts, $lockoutUntil);
            $upsert->execute();
            $upsert->close();
        }
    }
}

if (!function_exists('clearAccountLockout')) {
    function clearAccountLockout($conn, $identifier) {
        ensureLoginLockoutsTable($conn);
        $stmt = $conn->prepare('DELETE FROM login_lockouts WHERE login_identifier = ?');
        if ($stmt) {
            $stmt->bind_param('s', $identifier);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('ensurePasswordHistoryTable')) {
    function ensurePasswordHistoryTable($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS password_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_number VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_history_student (student_number, changed_at)
        )");
    }
}

if (!function_exists('isPasswordReuseDetected')) {
    function isPasswordReuseDetected($conn, $studentId, $plainPassword, $historyLimit, $currentHash = '') {
        if ($historyLimit <= 0) {
            return false;
        }

        if (!empty($currentHash) && password_verify($plainPassword, $currentHash)) {
            return true;
        }

        ensurePasswordHistoryTable($conn);
        $limit = max(1, (int)$historyLimit);

        $stmt = $conn->prepare("SELECT password_hash FROM password_history WHERE student_number = ? ORDER BY changed_at DESC LIMIT $limit");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            if (!empty($row['password_hash']) && password_verify($plainPassword, $row['password_hash'])) {
                $stmt->close();
                return true;
            }
        }

        $stmt->close();
        return false;
    }
}

if (!function_exists('recordPasswordHistory')) {
    function recordPasswordHistory($conn, $studentId, $passwordHash) {
        ensurePasswordHistoryTable($conn);
        $stmt = $conn->prepare('INSERT INTO password_history (student_number, password_hash) VALUES (?, ?)');
        if ($stmt) {
            $stmt->bind_param('ss', $studentId, $passwordHash);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>