<?php
/**
 * Admin authenticator-app 2FA helpers.
 *
 * This service implements TOTP generation/verification plus a short-lived
 * pre-auth session used between password verification and final admin login.
 */

require_once __DIR__ . '/admin_session_service.php';

if (!defined('ATF_PREAUTH_TTL_SECONDS')) {
    define('ATF_PREAUTH_TTL_SECONDS', 600);
}

if (!function_exists('atfIsEnabled')) {
    function atfIsEnabled(): bool
    {
        if (function_exists('getSystemSettingBool')) {
            return getSystemSettingBool('enable_admin_2fa', false);
        }

        return false;
    }
}

if (!function_exists('atfEnsureTable')) {
    function atfEnsureTable($conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS admin_two_factor_auth (
            admin_username VARCHAR(255) NOT NULL PRIMARY KEY,
            secret_encrypted TEXT NOT NULL,
            enabled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_verified_at DATETIME NULL,
            last_verified_time_slice BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
}

if (!function_exists('atfGetEncryptionKey')) {
    function atfGetEncryptionKey(): string
    {
        $rawKey = trim((string) (getenv('APP_KEY') ?: ''));
        if ($rawKey !== '' && str_starts_with($rawKey, 'base64:')) {
            $decoded = base64_decode(substr($rawKey, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return hash('sha256', $decoded, true);
            }
        }

        if ($rawKey !== '') {
            return hash('sha256', $rawKey, true);
        }

        return hash('sha256', __DIR__ . '|asplan-admin-2fa', true);
    }
}

if (!function_exists('atfEncryptSecret')) {
    function atfEncryptSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(16);
            $ciphertext = openssl_encrypt(
                $secret,
                'AES-256-CBC',
                atfGetEncryptionKey(),
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($ciphertext !== false && $ciphertext !== '') {
                return 'v1:' . base64_encode($iv . $ciphertext);
            }
        }

        return 'plain:' . $secret;
    }
}

if (!function_exists('atfDecryptSecret')) {
    function atfDecryptSecret(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        if (str_starts_with($payload, 'v1:') && function_exists('openssl_decrypt')) {
            $decoded = base64_decode(substr($payload, 3), true);
            if ($decoded !== false && strlen($decoded) > 16) {
                $iv = substr($decoded, 0, 16);
                $ciphertext = substr($decoded, 16);
                $secret = openssl_decrypt(
                    $ciphertext,
                    'AES-256-CBC',
                    atfGetEncryptionKey(),
                    OPENSSL_RAW_DATA,
                    $iv
                );

                if ($secret !== false && $secret !== '') {
                    return strtoupper(trim((string) $secret));
                }
            }
        }

        if (str_starts_with($payload, 'plain:')) {
            return strtoupper(trim(substr($payload, 6)));
        }

        return strtoupper($payload);
    }
}

if (!function_exists('atfBase32Alphabet')) {
    function atfBase32Alphabet(): string
    {
        return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    }
}

if (!function_exists('atfBase32Encode')) {
    function atfBase32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $alphabet = atfBase32Alphabet();
        $bits = '';
        $length = strlen($binary);

        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 5);
        $encoded = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }
}

if (!function_exists('atfBase32Decode')) {
    function atfBase32Decode(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32) ?? '');
        if ($base32 === '') {
            return '';
        }

        $alphabet = array_flip(str_split(atfBase32Alphabet()));
        $bits = '';
        $chars = str_split($base32);

        foreach ($chars as $char) {
            if (!isset($alphabet[$char])) {
                return '';
            }
            $bits .= str_pad(decbin($alphabet[$char]), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($bits, 8);
        $decoded = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}

if (!function_exists('atfGenerateSecret')) {
    function atfGenerateSecret(int $byteLength = 20): string
    {
        $byteLength = max(10, $byteLength);
        return atfBase32Encode(random_bytes($byteLength));
    }
}

if (!function_exists('atfFormatSecret')) {
    function atfFormatSecret(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        return trim(implode(' ', str_split($secret, 4)));
    }
}

if (!function_exists('atfBuildOtpAuthUri')) {
    function atfBuildOtpAuthUri(string $username, string $secret): string
    {
        $issuer = 'ASPLAN Admin';
        $label = rawurlencode($issuer . ':' . $username);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}

if (!function_exists('atfGenerateTotpCode')) {
    function atfGenerateTotpCode(string $secret, ?int $timeSlice = null): string
    {
        $secretKey = atfBase32Decode($secret);
        if ($secretKey === '') {
            return '';
        }

        $timeSlice = $timeSlice ?? (int) floor(time() / 30);
        $binaryTime = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $binaryTime, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('atfVerifyTotpCode')) {
    function atfVerifyTotpCode(string $secret, string $code, int $window = 1): array
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return ['valid' => false, 'time_slice' => null];
        }

        $currentSlice = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $slice = $currentSlice + $offset;
            if (hash_equals(atfGenerateTotpCode($secret, $slice), $code)) {
                return ['valid' => true, 'time_slice' => $slice];
            }
        }

        return ['valid' => false, 'time_slice' => null];
    }
}

if (!function_exists('atfLoadRecord')) {
    function atfLoadRecord($conn, string $username): ?array
    {
        atfEnsureTable($conn);
        $stmt = $conn->prepare('SELECT admin_username, secret_encrypted, enabled_at, last_verified_at, last_verified_time_slice FROM admin_two_factor_auth WHERE admin_username = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('atfStoreSecret')) {
    function atfStoreSecret($conn, string $username, string $secret, ?int $timeSlice = null): bool
    {
        atfEnsureTable($conn);
        $encrypted = atfEncryptSecret($secret);
        $timeSliceValue = $timeSlice !== null ? (string) $timeSlice : null;
        $stmt = $conn->prepare('INSERT INTO admin_two_factor_auth (admin_username, secret_encrypted, enabled_at, last_verified_at, last_verified_time_slice, created_at, updated_at) VALUES (?, ?, NOW(), NOW(), ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE secret_encrypted = VALUES(secret_encrypted), enabled_at = NOW(), last_verified_at = NOW(), last_verified_time_slice = VALUES(last_verified_time_slice), updated_at = NOW()');
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sss', $username, $encrypted, $timeSliceValue);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('atfRecordSuccessfulVerification')) {
    function atfRecordSuccessfulVerification($conn, string $username, int $timeSlice): void
    {
        atfEnsureTable($conn);
        $timeSliceValue = (string) $timeSlice;
        $stmt = $conn->prepare('UPDATE admin_two_factor_auth SET last_verified_at = NOW(), last_verified_time_slice = ?, updated_at = NOW() WHERE admin_username = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $timeSliceValue, $username);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('atfMoveEnrollment')) {
    function atfMoveEnrollment($conn, string $oldUsername, string $newUsername): void
    {
        if ($oldUsername === '' || $newUsername === '' || strcasecmp($oldUsername, $newUsername) === 0) {
            return;
        }

        atfEnsureTable($conn);
        $stmt = $conn->prepare('UPDATE admin_two_factor_auth SET admin_username = ?, updated_at = NOW() WHERE admin_username = ?');
        if (!$stmt) {
            return;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$newUsername, $oldUsername]);
            return;
        }

        if (method_exists($stmt, 'bind_param')) {
            $stmt->bind_param('ss', $newUsername, $oldUsername);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('atfClearPendingSession')) {
    function atfClearPendingSession(): void
    {
        unset(
            $_SESSION['admin_2fa_pending'],
            $_SESSION['admin_2fa_pending_username'],
            $_SESSION['admin_2fa_pending_full_name'],
            $_SESSION['admin_2fa_pending_ip'],
            $_SESSION['admin_2fa_pending_started_at'],
            $_SESSION['admin_2fa_setup_secret']
        );
    }
}

if (!function_exists('atfStartPendingSession')) {
    function atfStartPendingSession(string $username, string $fullName, string $ip): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);
        atfClearPendingSession();

        unset(
            $_SESSION['admin_id'],
            $_SESSION['admin_username'],
            $_SESSION['admin_full_name']
        );

        $_SESSION['admin_2fa_pending'] = '1';
        $_SESSION['admin_2fa_pending_username'] = $username;
        $_SESSION['admin_2fa_pending_full_name'] = $fullName;
        $_SESSION['admin_2fa_pending_ip'] = $ip;
        $_SESSION['admin_2fa_pending_started_at'] = time();
    }
}

if (!function_exists('atfGetPendingSession')) {
    function atfGetPendingSession(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ((string) ($_SESSION['admin_2fa_pending'] ?? '') !== '1') {
            return null;
        }

        $startedAt = (int) ($_SESSION['admin_2fa_pending_started_at'] ?? 0);

        if ($startedAt <= 0 || (time() - $startedAt) > ATF_PREAUTH_TTL_SECONDS) {
            atfClearPendingSession();
            return null;
        }

        $username = trim((string) ($_SESSION['admin_2fa_pending_username'] ?? ''));
        if ($username === '') {
            atfClearPendingSession();
            return null;
        }

        return [
            'username' => $username,
            'full_name' => trim((string) ($_SESSION['admin_2fa_pending_full_name'] ?? $username)),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'started_at' => $startedAt,
        ];
    }
}

if (!function_exists('atfFinalizeAdminLogin')) {
    function atfFinalizeAdminLogin(string $username, string $fullName): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);
        atfClearPendingSession();

        $_SESSION['admin_id'] = $username;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_full_name'] = $fullName;
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_type'] = 'admin';

        if (function_exists('getDBConnection')) {
            $conn = getDBConnection();
            assRegisterActiveSession($conn, $username);
        }
    }
}
