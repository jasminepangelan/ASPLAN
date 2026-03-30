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
    $query = "SELECT student_number AS student_id, last_name, first_name, middle_name FROM student_info WHERE status = 'pending'";
    $result = $conn->query($query);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $pending[] = $row;
        }
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
    $auto_approve_query = "SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_students'";
    $auto_approve_result = $conn->query($auto_approve_query);
    $enabled = false;
    if ($auto_approve_result && $auto_approve_result->num_rows > 0) {
        $auto_row = $auto_approve_result->fetch_assoc();
        $enabled = ($auto_row['setting_value'] === '1');
    }
    closeDBConnection($conn);
    return $enabled;
}
