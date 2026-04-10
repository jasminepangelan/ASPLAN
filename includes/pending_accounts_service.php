<?php
// pending_accounts_service.php
// Contains business logic for loading and presenting pending student accounts for admin approval.

require_once __DIR__ . '/../config/database.php';

/**
 * Loads pending student accounts for admin approval.
 * Returns an array of associative arrays with student info.
 */
function paLoadPendingAccounts()
{
    $conn = getDBConnection();
    $pending = [];
    $stmt = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name FROM student_info WHERE status = ?");
    if ($stmt) {
        $status = 'pending';
        $stmt->bind_param('s', $status);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $pending[] = $row;
            }
        }
        $stmt->close();
    }
    closeDBConnection($conn);
    return $pending;
}

/**
 * Checks if auto-approval is enabled for students.
 * Returns true if enabled, false otherwise.
 */
function paIsAutoApproveEnabled()
{
    $conn = getDBConnection();
    $enabled = false;
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $settingName = 'auto_approve_students';
        $stmt->bind_param('s', $settingName);
        $stmt->execute();
        $autoApproveResult = $stmt->get_result();
        if ($autoApproveResult && $autoApproveResult->num_rows > 0) {
            $autoRow = $autoApproveResult->fetch_assoc();
            $enabled = (($autoRow['setting_value'] ?? '') === '1');
        }
        $stmt->close();
    }
    closeDBConnection($conn);
    return $enabled;
}
