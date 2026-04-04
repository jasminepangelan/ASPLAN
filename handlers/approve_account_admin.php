<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';

$redirectBase = '../admin/pending_accounts.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBase . '?error=' . urlencode('Deprecated endpoint. Use the admin approval form instead.'));
    exit;
}

requireAdmin();
validateCSRFTokenRequired();

$student_id = trim((string) ($_POST['student_id'] ?? ''));

if ($student_id === '') {
    logSecurityEvent('legacy_approve_account_missing_id', [], 'warning');
    header('Location: ' . $redirectBase . '?error=' . urlencode('No account selected.'));
    exit;
}

if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $student_id)) {
    logSecurityEvent('legacy_approve_account_invalid_id', ['student_id' => $student_id], 'warning');
    header('Location: ' . $redirectBase . '?error=' . urlencode('Invalid student ID.'));
    exit;
}

$conn = getDBConnection();
$adminId = (string) ($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? '');
$stmt = $conn->prepare("UPDATE student_info SET status = 'approved', approved_by = ? WHERE student_number = ?");

if (!$stmt) {
    logSecurityEvent('legacy_approve_account_prepare_failed', ['student_id' => $student_id, 'error' => $conn->error], 'error');
    closeDBConnection($conn);
    header('Location: ' . $redirectBase . '?error=' . urlencode('Database error.'));
    exit;
}

$stmt->bind_param('ss', $adminId, $student_id);
$success = $stmt->execute();
$affectedRows = $stmt->affected_rows;
$stmt->close();
closeDBConnection($conn);

if ($success && $affectedRows > 0) {
    logSecurityEvent('legacy_approve_account_success', ['student_id' => $student_id, 'admin_id' => $adminId], 'info');
    header('Location: ' . $redirectBase . '?message=' . urlencode('Account approved successfully.'));
    exit;
}

logSecurityEvent('legacy_approve_account_failed', ['student_id' => $student_id, 'admin_id' => $adminId], 'warning');
header('Location: ' . $redirectBase . '?error=' . urlencode('Unable to approve account.'));
exit;
