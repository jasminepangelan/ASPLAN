<?php
/**
 * CvSU program coordinator email verification helpers.
 */

require_once __DIR__ . '/rate_limit.php';

const CEV_OTP_EXPIRY_MINUTES = 10;
const CEV_RESEND_COOLDOWN_SECONDS = 60;
const CEV_VERIFY_MAX_ATTEMPTS = 5;
const CEV_VERIFY_WINDOW_SECONDS = 900;

if (!function_exists('cevNormalizeEmail')) {
    function cevNormalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}

if (!function_exists('cevIsCvsuEmail')) {
    function cevIsCvsuEmail(string $email): bool
    {
        return (bool) preg_match('/@cvsu\.edu\.ph$/i', cevNormalizeEmail($email));
    }
}

if (!function_exists('cevEnsureTable')) {
    function cevEnsureTable($conn): void
    {
        if (!$conn || !method_exists($conn, 'query')) {
            return;
        }

        $conn->query("CREATE TABLE IF NOT EXISTS program_coordinator_email_verifications (
            username VARCHAR(100) PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(255) NULL,
            otp_expires_at DATETIME NULL,
            verified_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $conn->query("ALTER TABLE program_coordinator_email_verifications MODIFY COLUMN otp_code VARCHAR(255) NULL");
    }
}

if (!function_exists('cevGetRecord')) {
    function cevGetRecord($conn, string $username): ?array
    {
        cevEnsureTable($conn);

        $stmt = $conn->prepare('SELECT username, email, otp_code, otp_expires_at, verified_at, last_sent_at FROM program_coordinator_email_verifications WHERE username = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('cevSyncRecordForEmail')) {
    function cevSyncRecordForEmail($conn, string $username, string $email): void
    {
        if (!cevIsCvsuEmail($email)) {
            cevDeleteRecord($conn, $username);
            return;
        }

        cevEnsureTable($conn);
        $normalizedEmail = cevNormalizeEmail($email);
        $existing = cevGetRecord($conn, $username);

        if ($existing === null) {
            $stmt = $conn->prepare('INSERT INTO program_coordinator_email_verifications (username, email) VALUES (?, ?)');
            if ($stmt) {
                $stmt->bind_param('ss', $username, $normalizedEmail);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        if (cevNormalizeEmail((string) ($existing['email'] ?? '')) !== $normalizedEmail) {
            $stmt = $conn->prepare('UPDATE program_coordinator_email_verifications SET email = ?, otp_code = NULL, otp_expires_at = NULL, verified_at = NULL, last_sent_at = NULL WHERE username = ?');
            if ($stmt) {
                $stmt->bind_param('ss', $normalizedEmail, $username);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

if (!function_exists('cevDeleteRecord')) {
    function cevDeleteRecord($conn, string $username): void
    {
        cevEnsureTable($conn);
        $stmt = $conn->prepare('DELETE FROM program_coordinator_email_verifications WHERE username = ?');
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('cevCoordinatorRequiresVerification')) {
    function cevCoordinatorRequiresVerification($conn, string $username, string $email): bool
    {
        if ($username === '' || !cevIsCvsuEmail($email)) {
            return false;
        }

        cevSyncRecordForEmail($conn, $username, $email);
        $record = cevGetRecord($conn, $username);
        if ($record === null) {
            return true;
        }

        $matchesEmail = cevNormalizeEmail((string) ($record['email'] ?? '')) === cevNormalizeEmail($email);
        $verifiedAt = trim((string) ($record['verified_at'] ?? ''));

        return !$matchesEmail || $verifiedAt === '';
    }
}

if (!function_exists('cevSetSessionRequirement')) {
    function cevSetSessionRequirement(string $email): void
    {
        $_SESSION['program_coordinator_email_verification_required'] = true;
        $_SESSION['program_coordinator_email_verification_email'] = $email;
        $_SESSION['program_coordinator_email_verification_autosend'] = true;
    }
}

if (!function_exists('cevClearSessionRequirement')) {
    function cevClearSessionRequirement(): void
    {
        unset(
            $_SESSION['program_coordinator_email_verification_required'],
            $_SESSION['program_coordinator_email_verification_email'],
            $_SESSION['program_coordinator_email_verification_autosend'],
            $_SESSION['program_coordinator_email_verification_notice']
        );
    }
}

if (!function_exists('cevApplySessionRequirement')) {
    function cevApplySessionRequirement($conn, string $username, string $email): bool
    {
        $requiresVerification = cevCoordinatorRequiresVerification($conn, $username, $email);
        if ($requiresVerification) {
            cevSetSessionRequirement($email);
            return true;
        }

        cevClearSessionRequirement();
        return false;
    }
}

if (!function_exists('cevVerificationRedirectUrl')) {
    function cevVerificationRedirectUrl(): string
    {
        return function_exists('buildAppRelativeUrl')
            ? buildAppRelativeUrl('/program_coordinator/verify_cvsu_email.php')
            : 'program_coordinator/verify_cvsu_email.php';
    }
}

if (!function_exists('cevIssueOtp')) {
    function cevIssueOtp($conn, string $username, string $email, bool $forceResend = false): array
    {
        if ($username === '' || !cevIsCvsuEmail($email)) {
            return ['success' => false, 'message' => 'CvSU email verification is not required for this account.'];
        }

        cevSyncRecordForEmail($conn, $username, $email);
        $record = cevGetRecord($conn, $username);
        if ($record !== null && trim((string) ($record['verified_at'] ?? '')) !== '') {
            cevClearSessionRequirement();
            return ['success' => true, 'already_verified' => true, 'message' => 'Your CvSU email is already verified.'];
        }

        if (!$forceResend && $record !== null && !empty($record['last_sent_at'])) {
            $cooldownRemaining = strtotime((string) $record['last_sent_at']) + CEV_RESEND_COOLDOWN_SECONDS - time();
            if ($cooldownRemaining > 0) {
                return [
                    'success' => true,
                    'cooldown_active' => true,
                    'message' => 'A verification code was sent recently. Please wait ' . $cooldownRemaining . ' second(s) before requesting another one.',
                ];
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expiry = date('Y-m-d H:i:s', strtotime('+' . CEV_OTP_EXPIRY_MINUTES . ' minutes'));
        $normalizedEmail = cevNormalizeEmail($email);

        $stmt = $conn->prepare('INSERT INTO program_coordinator_email_verifications (username, email, otp_code, otp_expires_at, last_sent_at, verified_at) VALUES (?, ?, ?, ?, NOW(), NULL) ON DUPLICATE KEY UPDATE email = VALUES(email), otp_code = VALUES(otp_code), otp_expires_at = VALUES(otp_expires_at), last_sent_at = NOW(), verified_at = NULL');
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare the email verification request.'];
        }

        $stmt->bind_param('ssss', $username, $normalizedEmail, $codeHash, $expiry);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Failed to save the email verification request.'];
        }
        $stmt->close();

        resetRateLimitDB($conn, scopedRateLimitAction('program_coordinator_email_otp_verify', $username . '|' . $normalizedEmail));

        $subject = 'Your ASPLAN Program Coordinator Email Verification Code';
        $textBody = "Your ASPLAN program coordinator email verification code is: {$code}\nThis code will expire in " . CEV_OTP_EXPIRY_MINUTES . " minutes.";
        $htmlBody = '<p>Your ASPLAN program coordinator email verification code is:</p><p style="font-size:24px;font-weight:700;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p><p>This code will expire in ' . CEV_OTP_EXPIRY_MINUTES . ' minutes.</p>';

        $sendResult = sendConfiguredEmail($normalizedEmail, $subject, $textBody, $htmlBody);
        if (empty($sendResult['success'])) {
            return ['success' => false, 'message' => (string) ($sendResult['error'] ?? 'Unable to send the verification code right now.')];
        }

        return ['success' => true, 'message' => 'A verification code has been sent to your CvSU email address.'];
    }
}

if (!function_exists('cevVerifyOtp')) {
    function cevVerifyOtp($conn, string $username, string $email, string $code): array
    {
        if ($username === '' || $code === '' || !preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Please enter the 6-digit verification code.'];
        }

        $normalizedEmail = cevNormalizeEmail($email);
        $throttleAction = scopedRateLimitAction('program_coordinator_email_otp_verify', $username . '|' . $normalizedEmail);
        $rateLimit = checkRateLimitDB($conn, $throttleAction, CEV_VERIFY_MAX_ATTEMPTS, CEV_VERIFY_WINDOW_SECONDS);
        if (!$rateLimit['allowed']) {
            return ['success' => false, 'message' => $rateLimit['message']];
        }

        cevSyncRecordForEmail($conn, $username, $email);
        $record = cevGetRecord($conn, $username);
        if ($record === null) {
            return ['success' => false, 'message' => 'No verification request was found for this account.'];
        }

        if (cevNormalizeEmail((string) ($record['email'] ?? '')) !== $normalizedEmail) {
            return ['success' => false, 'message' => 'Your CvSU email changed. Please request a new verification code.'];
        }

        if (trim((string) ($record['verified_at'] ?? '')) !== '') {
            cevClearSessionRequirement();
            return ['success' => true, 'message' => 'Your CvSU email is already verified.'];
        }

        $storedOtp = (string) ($record['otp_code'] ?? '');
        $otpInfo = password_get_info($storedOtp);
        $matchesOtp = !empty($otpInfo['algo'])
            ? password_verify($code, $storedOtp)
            : hash_equals($storedOtp, $code);

        if (!$matchesOtp) {
            recordAttemptDB($conn, $throttleAction);
            return ['success' => false, 'message' => 'Invalid verification code.'];
        }

        $expiresAt = trim((string) ($record['otp_expires_at'] ?? ''));
        if ($expiresAt === '' || strtotime($expiresAt) < time()) {
            recordAttemptDB($conn, $throttleAction);
            return ['success' => false, 'message' => 'Verification code expired. Please request a new one.'];
        }

        $stmt = $conn->prepare('UPDATE program_coordinator_email_verifications SET verified_at = NOW(), otp_code = NULL, otp_expires_at = NULL WHERE username = ?');
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to finalize email verification.'];
        }

        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Failed to finalize email verification.'];
        }
        $stmt->close();

        resetRateLimitDB($conn, $throttleAction);
        cevClearSessionRequirement();

        return ['success' => true, 'message' => 'Your CvSU email has been verified successfully.'];
    }
}
