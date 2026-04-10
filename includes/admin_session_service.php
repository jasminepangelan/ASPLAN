<?php
/**
 * Single-active-session enforcement for admin accounts.
 */

if (!function_exists('assEnsureTable')) {
    function assEnsureTable($conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS admin_active_sessions (
            admin_username VARCHAR(255) NOT NULL PRIMARY KEY,
            session_id VARCHAR(255) NOT NULL,
            user_ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
}

if (!function_exists('assCurrentSessionId')) {
    function assCurrentSessionId(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return (string) session_id();
    }
}

if (!function_exists('assRegisterActiveSession')) {
    function assRegisterActiveSession($conn, string $adminUsername): bool
    {
        if ($adminUsername === '') {
            return false;
        }

        assEnsureTable($conn);

        $sessionId = assCurrentSessionId();
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);

        $stmt = $conn->prepare('INSERT INTO admin_active_sessions (admin_username, session_id, user_ip, user_agent, last_seen_at, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW()) ON DUPLICATE KEY UPDATE session_id = VALUES(session_id), user_ip = VALUES(user_ip), user_agent = VALUES(user_agent), last_seen_at = NOW(), updated_at = NOW()');
        if (!$stmt) {
            return false;
        }

        if ($stmt instanceof PDOStatement) {
            return $stmt->execute([$adminUsername, $sessionId, $ip, $userAgent]);
        }

        $stmt->bind_param('ssss', $adminUsername, $sessionId, $ip, $userAgent);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('assTouchActiveSession')) {
    function assTouchActiveSession($conn, string $adminUsername): void
    {
        if ($adminUsername === '') {
            return;
        }

        assEnsureTable($conn);
        $sessionId = assCurrentSessionId();
        $stmt = $conn->prepare('UPDATE admin_active_sessions SET last_seen_at = NOW(), updated_at = NOW() WHERE admin_username = ? AND session_id = ?');
        if (!$stmt) {
            return;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$adminUsername, $sessionId]);
            return;
        }

        $stmt->bind_param('ss', $adminUsername, $sessionId);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('assLoadActiveSession')) {
    function assLoadActiveSession($conn, string $adminUsername): ?array
    {
        if ($adminUsername === '') {
            return null;
        }

        assEnsureTable($conn);
        $stmt = $conn->prepare('SELECT admin_username, session_id, user_ip, user_agent, last_seen_at FROM admin_active_sessions WHERE admin_username = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$adminUsername]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        $stmt->bind_param('s', $adminUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('assIsCurrentAdminSession')) {
    function assIsCurrentAdminSession($conn, string $adminUsername): bool
    {
        $record = assLoadActiveSession($conn, $adminUsername);
        if (!is_array($record)) {
            return true;
        }

        return hash_equals((string) ($record['session_id'] ?? ''), assCurrentSessionId());
    }
}

if (!function_exists('assClearActiveSession')) {
    function assClearActiveSession($conn, string $adminUsername, bool $onlyCurrentSession = true): void
    {
        if ($adminUsername === '') {
            return;
        }

        assEnsureTable($conn);
        if ($onlyCurrentSession) {
            $sessionId = assCurrentSessionId();
            $stmt = $conn->prepare('DELETE FROM admin_active_sessions WHERE admin_username = ? AND session_id = ?');
            if (!$stmt) {
                return;
            }

            if ($stmt instanceof PDOStatement) {
                $stmt->execute([$adminUsername, $sessionId]);
                return;
            }

            $stmt->bind_param('ss', $adminUsername, $sessionId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare('DELETE FROM admin_active_sessions WHERE admin_username = ?');
        if (!$stmt) {
            return;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$adminUsername]);
            return;
        }

        $stmt->bind_param('s', $adminUsername);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('assMoveActiveSessionUsername')) {
    function assMoveActiveSessionUsername($conn, string $oldUsername, string $newUsername): void
    {
        if ($oldUsername === '' || $newUsername === '' || strcasecmp($oldUsername, $newUsername) === 0) {
            return;
        }

        assEnsureTable($conn);
        $stmt = $conn->prepare('UPDATE admin_active_sessions SET admin_username = ?, updated_at = NOW() WHERE admin_username = ?');
        if (!$stmt) {
            return;
        }

        if ($stmt instanceof PDOStatement) {
            $stmt->execute([$newUsername, $oldUsername]);
            return;
        }

        $stmt->bind_param('ss', $newUsername, $oldUsername);
        $stmt->execute();
        $stmt->close();
    }
}
