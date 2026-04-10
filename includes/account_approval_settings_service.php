<?php
/**
 * Service layer for account approval settings management
 * Handles settings CRUD, validation, and audit logging
 */

require_once __DIR__ . '/admin_two_factor_service.php';

function aasNormalizeSettingValue(array $meta, string $raw): string {
    $value = trim($raw);
    
    if ($meta['type'] === 'boolean') {
        return ($value === '1' || $value === 'true') ? '1' : '0';
    }
    
    if ($meta['type'] === 'number') {
        $numValue = is_numeric($value) ? (int)$value : (int)($meta['default'] ?? 0);
        
        if (isset($meta['min']) && $numValue < $meta['min']) {
            $numValue = (int)$meta['min'];
        }
        if (isset($meta['max']) && $numValue > $meta['max']) {
            $numValue = (int)$meta['max'];
        }
        
        return (string)$numValue;
    }
    
    if ($meta['type'] === 'datetime') {
        $datetime = aasToDateTimeLocalValue($value);
        return $datetime;
    }
    
    if (isset($meta['max_length'])) {
        $value = substr($value, 0, (int)$meta['max_length']);
    }
    
    return $value;
}

function aasToDateTimeLocalValue(string $raw): string {
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime(str_replace('T', ' ', $value));
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

function aasUpsertSystemSetting(PDO $conn, string $key, string $value, string $updatedBy): void {
    $existsStmt = $conn->prepare("SELECT id FROM system_settings WHERE setting_name = ? ORDER BY id DESC LIMIT 1");
    $existsStmt->execute([$key]);
    $existingId = $existsStmt->fetchColumn();

    if ($existingId !== false) {
        $updateStmt = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$value, $updatedBy, $existingId]);
        return;
    }

    $insertStmt = $conn->prepare("INSERT INTO system_settings (setting_name, setting_value, updated_by, updated_at) VALUES (?, ?, ?, NOW())");
    $insertStmt->execute([$key, $value, $updatedBy]);
}

function aasWriteAdminAuditLog(PDO $conn, string $adminId, string $actionType, string $target, string $summary, array $metadata = []): void {
    try {
        $stmt = $conn->prepare("INSERT INTO admin_audit_logs (admin_id, action_type, action_target, summary, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $metadataJson = empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $stmt->execute([$adminId, $actionType, $target, $summary, $metadataJson]);
    } catch (Throwable $e) {
        error_log('Failed to write admin audit log: ' . $e->getMessage());
    }
}

function aasGetCurrentSettingValue(PDO $conn, string $settingName): ?string {
    $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_name = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$settingName]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : $value;
}

function aasLoadPolicySettingValues(PDO $conn, array $policySettings): array {
    $policySettingValues = [];
    foreach ($policySettings as $key => $meta) {
        $value = aasGetCurrentSettingValue($conn, $key);
        
        if ($value === null || !is_numeric($value)) {
            $policySettingValues[$key] = (int)($meta['default'] ?? 0);
        } else {
            $normalized = (int)$value;
            if (isset($meta['min']) && $normalized < $meta['min']) {
                $normalized = (int)$meta['min'];
            }
            if (isset($meta['max']) && $normalized > $meta['max']) {
                $normalized = (int)$meta['max'];
            }
            $policySettingValues[$key] = $normalized;
        }
    }
    return $policySettingValues;
}

function aasLoadAdvancedSettingValues(PDO $conn, array $advancedSettings): array {
    $advancedSettingValues = [];
    foreach ($advancedSettings as $key => $meta) {
        $value = aasGetCurrentSettingValue($conn, $key);
        
        if ($value === null) {
            $advancedSettingValues[$key] = aasNormalizeSettingValue($meta, $meta['default'] ?? '');
        } else {
            $advancedSettingValues[$key] = aasNormalizeSettingValue($meta, $value);
        }
    }
    return $advancedSettingValues;
}

function aasLoadAutoApproveSetting(PDO $conn): bool {
    $value = aasGetCurrentSettingValue($conn, 'auto_approve_students');
    return ((int)$value === 1);
}

function aasIsFreezingEnabled(PDO $conn): bool {
    $value = aasGetCurrentSettingValue($conn, 'freeze_approvals');
    return ((int)$value === 1);
}

function aasUpdatePolicySettings(PDO $conn, string $adminId, array $policySettings, array $postData): void {
    $changedSettings = [];
    
    foreach ($policySettings as $key => $meta) {
        $raw = isset($postData[$key]) ? trim((string)$postData[$key]) : '';
        $value = is_numeric($raw) ? (int)$raw : (int)($meta['default'] ?? 0);

        if (isset($meta['min']) && $value < $meta['min']) {
            $value = (int)$meta['min'];
        }
        if (isset($meta['max']) && $value > $meta['max']) {
            $value = (int)$meta['max'];
        }

        $currentValue = aasGetCurrentSettingValue($conn, $key);
        aasUpsertSystemSetting($conn, $key, (string)$value, $adminId);

        if ((string)$currentValue !== (string)$value) {
            $changedSettings[] = $key;
        }
    }

    if (!empty($changedSettings)) {
        aasWriteAdminAuditLog(
            $conn,
            $adminId,
            'settings_update',
            'policy_settings',
            'Updated security and rate-limit policy settings.',
            ['changed_settings' => $changedSettings]
        );
    }
}

function aasUpdateAutoApproveSetting(PDO $conn, string $adminId, bool $autoApprove): int {
    $autoApproveInt = $autoApprove ? 1 : 0;
    
    $stmt = $conn->prepare("
        UPDATE system_settings
        SET setting_value = ?, updated_by = ?, updated_at = NOW()
        WHERE setting_name = 'auto_approve_students'
    ");
    $stmt->execute([$autoApproveInt, $adminId]);
    
    if ($stmt->rowCount() === 0) {
        $insertStmt = $conn->prepare("
            INSERT INTO system_settings (setting_name, setting_value, updated_by, updated_at)
            VALUES ('auto_approve_students', ?, ?, NOW())
        ");
        $insertStmt->execute([$autoApproveInt, $adminId]);
    }
    
    $approvedCount = 0;
    if ($autoApprove) {
        $approvalStmt = $conn->prepare("UPDATE student_info SET status = 'approved' WHERE status = 'pending'");
        $approvalStmt->execute();
        $approvedCount = $approvalStmt->rowCount();
        
        aasWriteAdminAuditLog(
            $conn,
            $adminId,
            'approval_mode_update',
            'auto_approve_students',
            'Enabled auto-approval for student registrations.',
            ['auto_approve' => 1, 'auto_approved_pending_count' => $approvedCount]
        );
    } else {
        aasWriteAdminAuditLog(
            $conn,
            $adminId,
            'approval_mode_update',
            'auto_approve_students',
            'Disabled auto-approval for student registrations.',
            ['auto_approve' => 0]
        );
    }
    
    return $approvedCount;
}

function aasUpdateAdvancedSettings(PDO $conn, string $adminId, array $advancedSettings, array $postData): void {
    $changedAdvancedSettings = [];
    
    foreach ($advancedSettings as $key => $meta) {
        $raw = $meta['type'] === 'boolean'
            ? (isset($postData[$key]) ? '1' : '0')
            : ($postData[$key] ?? '');
        $normalized = aasNormalizeSettingValue($meta, $raw);

        $currentValue = aasGetCurrentSettingValue($conn, $key);
        aasUpsertSystemSetting($conn, $key, $normalized, $adminId);

        if ((string)$currentValue !== $normalized) {
            $changedAdvancedSettings[] = $key;
        }
    }

    if (!empty($changedAdvancedSettings)) {
        aasWriteAdminAuditLog(
            $conn,
            $adminId,
            'settings_update',
            'advanced_controls',
            'Updated advanced admin controls.',
            ['changed_settings' => $changedAdvancedSettings]
        );
    }
}

function aasLoadAdminAccountProfile(PDO $conn, string $adminId): array {
    $stmt = $conn->prepare("
        SELECT
            username,
            CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name,
            password
        FROM admin
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        throw new RuntimeException('Admin account profile could not be found.');
    }

    return $row;
}

function aasResolveMinPasswordLength(PDO $conn): int {
    $value = aasGetCurrentSettingValue($conn, 'min_password_length');
    $minLength = is_numeric($value) ? (int)$value : 8;
    return max(6, min(64, $minLength));
}

function aasUpdateAdminAccountCredentials(PDO $conn, string $adminId, string $currentPassword, string $newUsername, string $newPassword): array {
    $profile = aasLoadAdminAccountProfile($conn, $adminId);
    $existingUsername = trim((string)($profile['username'] ?? ''));
    $storedHash = (string)($profile['password'] ?? '');

    if ($existingUsername === '' || $storedHash === '') {
        throw new RuntimeException('Admin account record is incomplete.');
    }

    if (!password_verify($currentPassword, $storedHash)) {
        throw new RuntimeException('Current password is incorrect.');
    }

    $targetUsername = trim($newUsername) !== '' ? trim($newUsername) : $existingUsername;
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $targetUsername)) {
        throw new RuntimeException('Username must be 3 to 64 characters and may only use letters, numbers, periods, underscores, and hyphens.');
    }

    if (strcasecmp($targetUsername, $existingUsername) !== 0) {
        $duplicateStmt = $conn->prepare('SELECT COUNT(*) FROM admin WHERE username = ? AND username <> ?');
        $duplicateStmt->execute([$targetUsername, $existingUsername]);
        if ((int)$duplicateStmt->fetchColumn() > 0) {
            throw new RuntimeException('That username is already in use by another admin account.');
        }
    }

    $passwordChanged = trim($newPassword) !== '';
    $targetHash = $passwordChanged ? password_hash($newPassword, PASSWORD_BCRYPT) : $storedHash;

    $updateStmt = $conn->prepare('UPDATE admin SET username = ?, password = ? WHERE username = ?');
    $updateStmt->execute([$targetUsername, $targetHash, $existingUsername]);

    atfMoveEnrollment($conn, $existingUsername, $targetUsername);

    $changedFields = [];
    if (strcasecmp($targetUsername, $existingUsername) !== 0) {
        $changedFields[] = 'username';
    }
    if ($passwordChanged) {
        $changedFields[] = 'password';
    }

    if (!empty($changedFields)) {
        aasWriteAdminAuditLog(
            $conn,
            $targetUsername,
            'account_security_update',
            'admin_account',
            'Updated admin account credentials.',
            ['changed_fields' => $changedFields]
        );
    }

    return [
        'username' => $targetUsername,
        'full_name' => (string)($profile['full_name'] ?? ''),
        'changed_fields' => $changedFields,
    ];
}
?>
