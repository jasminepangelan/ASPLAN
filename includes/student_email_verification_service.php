<?php
/**
 * CvSU student email verification helpers.
 *
 * Students may register using their CvSU email address, but they must verify
 * it with an OTP after a successful login before accessing the student area.
 */

const SEV_OTP_EXPIRY_MINUTES = 10;
const SEV_RESEND_COOLDOWN_SECONDS = 60;

if (!function_exists('sevNormalizeEmail')) {
    function sevNormalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}

if (!function_exists('sevIsCvsuEmail')) {
    function sevIsCvsuEmail(string $email): bool
    {
        return (bool) preg_match('/@cvsu\.edu\.ph$/i', sevNormalizeEmail($email));
    }
}

if (!function_exists('sevEnsureTable')) {
    function sevEnsureTable($conn): void
    {
        if (!$conn || !method_exists($conn, 'query')) {
            return;
        }

        $conn->query("CREATE TABLE IF NOT EXISTS student_email_verifications (
            student_number VARCHAR(50) PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            otp_code VARCHAR(10) NULL,
            otp_expires_at DATETIME NULL,
            verified_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
}

if (!function_exists('sevGetRecord')) {
    function sevGetRecord($conn, string $studentId): ?array
    {
        sevEnsureTable($conn);

        $stmt = $conn->prepare('SELECT student_number, email, otp_code, otp_expires_at, verified_at, last_sent_at FROM student_email_verifications WHERE student_number = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $studentId);
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

if (!function_exists('sevSyncRecordForEmail')) {
    function sevSyncRecordForEmail($conn, string $studentId, string $email): void
    {
        if (!sevIsCvsuEmail($email)) {
            sevDeleteRecord($conn, $studentId);
            return;
        }

        sevEnsureTable($conn);
        $normalizedEmail = sevNormalizeEmail($email);
        $existing = sevGetRecord($conn, $studentId);

        if ($existing === null) {
            $stmt = $conn->prepare('INSERT INTO student_email_verifications (student_number, email) VALUES (?, ?)');
            if ($stmt) {
                $stmt->bind_param('ss', $studentId, $normalizedEmail);
                $stmt->execute();
                $stmt->close();
            }
            return;
        }

        if (sevNormalizeEmail((string) ($existing['email'] ?? '')) !== $normalizedEmail) {
            $stmt = $conn->prepare('UPDATE student_email_verifications SET email = ?, otp_code = NULL, otp_expires_at = NULL, verified_at = NULL, last_sent_at = NULL WHERE student_number = ?');
            if ($stmt) {
                $stmt->bind_param('ss', $normalizedEmail, $studentId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

if (!function_exists('sevDeleteRecord')) {
    function sevDeleteRecord($conn, string $studentId): void
    {
        sevEnsureTable($conn);
        $stmt = $conn->prepare('DELETE FROM student_email_verifications WHERE student_number = ?');
        if ($stmt) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('sevStudentRequiresVerification')) {
    function sevStudentRequiresVerification($conn, string $studentId, string $email): bool
    {
        if ($studentId === '' || !sevIsCvsuEmail($email)) {
            return false;
        }

        sevSyncRecordForEmail($conn, $studentId, $email);
        $record = sevGetRecord($conn, $studentId);
        if ($record === null) {
            return true;
        }

        $matchesEmail = sevNormalizeEmail((string) ($record['email'] ?? '')) === sevNormalizeEmail($email);
        $verifiedAt = trim((string) ($record['verified_at'] ?? ''));

        return !$matchesEmail || $verifiedAt === '';
    }
}

if (!function_exists('sevGetStatusMeta')) {
    function sevGetStatusMeta($conn, string $studentId, string $email): array
    {
        $normalizedEmail = sevNormalizeEmail($email);

        if ($studentId === '' || $normalizedEmail === '') {
            return [
                'variant' => 'neutral',
                'label' => 'Email not set',
                'headline' => 'No email available',
                'description' => 'Add an active email address so the system can send recovery and account notices.',
            ];
        }

        if (!sevIsCvsuEmail($normalizedEmail)) {
            return [
                'variant' => 'neutral',
                'label' => 'Personal email',
                'headline' => 'Verification not required',
                'description' => 'This profile is using a non-CvSU email address, so CvSU OTP verification is not required.',
            ];
        }

        sevSyncRecordForEmail($conn, $studentId, $normalizedEmail);
        $record = sevGetRecord($conn, $studentId);
        $verifiedAt = trim((string) ($record['verified_at'] ?? ''));

        if ($verifiedAt !== '') {
            return [
                'variant' => 'verified',
                'label' => 'CvSU email verified',
                'headline' => 'Verified for recovery',
                'description' => 'Your official CvSU email has been confirmed and can be used for account recovery and notices.',
            ];
        }

        return [
            'variant' => 'pending',
            'label' => 'CvSU email pending',
            'headline' => 'Verification still required',
            'description' => 'This CvSU email is on file, but it still needs OTP verification before it is fully trusted by the system.',
        ];
    }
}

if (!function_exists('sevSetSessionRequirement')) {
    function sevSetSessionRequirement(string $email): void
    {
        $_SESSION['student_email_verification_required'] = true;
        $_SESSION['student_email_verification_email'] = $email;
        $_SESSION['student_email_verification_autosend'] = true;
    }
}

if (!function_exists('sevClearSessionRequirement')) {
    function sevClearSessionRequirement(): void
    {
        unset(
            $_SESSION['student_email_verification_required'],
            $_SESSION['student_email_verification_email'],
            $_SESSION['student_email_verification_autosend'],
            $_SESSION['student_email_verification_notice']
        );
    }
}

if (!function_exists('sevApplySessionRequirement')) {
    function sevApplySessionRequirement($conn, string $studentId, string $email): bool
    {
        $requiresVerification = sevStudentRequiresVerification($conn, $studentId, $email);
        if ($requiresVerification) {
            sevSetSessionRequirement($email);
            return true;
        }

        sevClearSessionRequirement();
        return false;
    }
}

if (!function_exists('sevVerificationRedirectUrl')) {
    function sevVerificationRedirectUrl(): string
    {
        return function_exists('buildAppRelativeUrl')
            ? buildAppRelativeUrl('/student/verify_cvsu_email.php')
            : 'student/verify_cvsu_email.php';
    }
}

if (!function_exists('sevIssueOtp')) {
    function sevIssueOtp($conn, string $studentId, string $email, bool $forceResend = false): array
    {
        if ($studentId === '' || !sevIsCvsuEmail($email)) {
            return ['success' => false, 'message' => 'CvSU email verification is not required for this account.'];
        }

        sevSyncRecordForEmail($conn, $studentId, $email);
        $record = sevGetRecord($conn, $studentId);
        if ($record !== null && trim((string) ($record['verified_at'] ?? '')) !== '') {
            sevClearSessionRequirement();
            return ['success' => true, 'already_verified' => true, 'message' => 'Your CvSU email is already verified.'];
        }

        if (!$forceResend && $record !== null && !empty($record['last_sent_at'])) {
            $cooldownRemaining = strtotime((string) $record['last_sent_at']) + SEV_RESEND_COOLDOWN_SECONDS - time();
            if ($cooldownRemaining > 0) {
                return [
                    'success' => true,
                    'cooldown_active' => true,
                    'message' => 'A verification code was sent recently. Please wait ' . $cooldownRemaining . ' second(s) before requesting another one.',
                ];
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiry = date('Y-m-d H:i:s', strtotime('+' . SEV_OTP_EXPIRY_MINUTES . ' minutes'));
        $normalizedEmail = sevNormalizeEmail($email);

        $stmt = $conn->prepare('INSERT INTO student_email_verifications (student_number, email, otp_code, otp_expires_at, last_sent_at, verified_at) VALUES (?, ?, ?, ?, NOW(), NULL) ON DUPLICATE KEY UPDATE email = VALUES(email), otp_code = VALUES(otp_code), otp_expires_at = VALUES(otp_expires_at), last_sent_at = NOW(), verified_at = NULL');
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare the email verification request.'];
        }

        $stmt->bind_param('ssss', $studentId, $normalizedEmail, $code, $expiry);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Failed to save the email verification request.'];
        }
        $stmt->close();

        $subject = 'Your ASPLAN CvSU Email Verification Code';
        $textBody = "Your ASPLAN CvSU email verification code is: {$code}\nThis code will expire in " . SEV_OTP_EXPIRY_MINUTES . " minutes.";
        $htmlBody = '<p>Your ASPLAN CvSU email verification code is:</p><p style="font-size:24px;font-weight:700;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p><p>This code will expire in ' . SEV_OTP_EXPIRY_MINUTES . ' minutes.</p>';

        $sendResult = sendConfiguredEmail($normalizedEmail, $subject, $textBody, $htmlBody);
        if (empty($sendResult['success'])) {
            return ['success' => false, 'message' => (string) ($sendResult['error'] ?? 'Unable to send the verification code right now.')];
        }

        return ['success' => true, 'message' => 'A verification code has been sent to your CvSU email address.'];
    }
}

if (!function_exists('sevVerifyOtp')) {
    function sevVerifyOtp($conn, string $studentId, string $email, string $code): array
    {
        if ($studentId === '' || $code === '' || !preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Please enter the 6-digit verification code.'];
        }

        sevSyncRecordForEmail($conn, $studentId, $email);
        $record = sevGetRecord($conn, $studentId);
        if ($record === null) {
            return ['success' => false, 'message' => 'No verification request was found for this account.'];
        }

        if (sevNormalizeEmail((string) ($record['email'] ?? '')) !== sevNormalizeEmail($email)) {
            return ['success' => false, 'message' => 'Your CvSU email changed. Please request a new verification code.'];
        }

        if (trim((string) ($record['verified_at'] ?? '')) !== '') {
            sevClearSessionRequirement();
            return ['success' => true, 'message' => 'Your CvSU email is already verified.'];
        }

        if ((string) ($record['otp_code'] ?? '') !== $code) {
            return ['success' => false, 'message' => 'Invalid verification code.'];
        }

        $expiresAt = trim((string) ($record['otp_expires_at'] ?? ''));
        if ($expiresAt === '' || strtotime($expiresAt) < time()) {
            return ['success' => false, 'message' => 'Verification code expired. Please request a new one.'];
        }

        $stmt = $conn->prepare('UPDATE student_email_verifications SET verified_at = NOW(), otp_code = NULL, otp_expires_at = NULL WHERE student_number = ?');
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to finalize email verification.'];
        }

        $stmt->bind_param('s', $studentId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Failed to finalize email verification.'];
        }
        $stmt->close();

        sevClearSessionRequirement();

        return ['success' => true, 'message' => 'Your CvSU email has been verified successfully.'];
    }
}

