<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Require admin authentication
requireAdmin();

// Validate CSRF protection
validateCSRFTokenRequired();

$student_id = $_POST['student_id'] ?? $_GET['student_id'] ?? null;

if ($student_id === null || $student_id === '') {
    logSecurityEvent('approve_account_missing_id', [], 'warning');
    header("Location: pending_accounts.php?error=Invalid student ID");
    exit;
}

$student_id = trim((string)$student_id);

if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $student_id)) {
    logSecurityEvent('approve_account_invalid_id', ['student_id' => $student_id], 'warning');
    header("Location: pending_accounts.php?error=Invalid student ID format");
    exit;
}

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : $_SESSION['admin_username'];

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/admin/pending-accounts/approve',
        [
            'student_id' => $student_id,
            'admin_id' => $admin_id,
            'approved_by' => $admin_id,
        ]
    );

    if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
        $queryKey = ($bridgeData['query_key'] ?? 'message') === 'error' ? 'error' : 'message';
        $message = urlencode((string) ($bridgeData['message'] ?? 'Account approved successfully.'));
        header("Location: pending_accounts.php?{$queryKey}={$message}");
        exit;
    }
}

// Get database connection
$conn = getDBConnection();

// Use prepared statement to prevent SQL injection
$stmt = $conn->prepare("UPDATE student_info SET status = 'approved', approved_by = ? WHERE student_number = ?");
if (!$stmt) {
    logSecurityEvent('approve_account_prepare_failed', ['error' => $conn->error], 'error');
    header("Location: pending_accounts.php?error=Database error");
    exit;
}

$stmt->bind_param("ss", $admin_id, $student_id);

if ($stmt->execute()) {
    if ($stmt->affected_rows <= 0) {
        logSecurityEvent('approve_account_not_found', ['student_id' => $student_id], 'warning');
        $stmt->close();
        closeDBConnection($conn);
        header("Location: pending_accounts.php?error=Student account not found");
        exit;
    }

    logSecurityEvent('account_approved', ['student_id' => $student_id, 'admin_id' => $admin_id], 'info');
    // Redirect back to the pending accounts page with success message
    $stmt->close();
    closeDBConnection($conn);
    header("Location: pending_accounts.php?message=Account approved successfully.");
    exit;
} else {
    // Log failed update
    logSecurityEvent('approve_account_failed', ['student_id' => $student_id, 'error' => $stmt->error], 'error');
    // Redirect back with an error message if the update fails
    $stmt->close();
    closeDBConnection($conn);
    header("Location: pending_accounts.php?message=Error approving account.");
    exit;
}
?>
