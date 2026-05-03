<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

// Require admin authentication
requireAdmin();

// Validate CSRF token for POST requests
validateCSRFTokenRequired();

$student_id = $_POST['student_id'] ?? $_GET['student_id'] ?? null;

if ($student_id === null || $student_id === '') {
	logSecurityEvent('reject_account_missing_id', [], 'warning');
	header("Location: pending_accounts.php?error=Invalid student ID");
	exit;
}

$student_id = trim((string)$student_id);

if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $student_id)) {
	logSecurityEvent('reject_account_invalid_id', ['student_id' => $student_id], 'warning');
	header("Location: pending_accounts.php?error=Invalid student ID format");
	exit;
}

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

if ($useLaravelBridge) {
	$bridgeData = postLaravelJsonBridge(
		'/api/admin/pending-accounts/reject',
		[
			'bridge_authorized' => true,
			'student_id' => $student_id,
			'admin_id' => (string) ($_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? ''),
		]
	);

	if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
		$queryKey = ($bridgeData['query_key'] ?? 'message') === 'error' ? 'error' : 'message';
		$message = urlencode((string) ($bridgeData['message'] ?? 'Account rejected successfully.'));
		header("Location: pending_accounts.php?{$queryKey}={$message}");
		exit;
	}
}

// Get database connection
$conn = getDBConnection();

$query = $conn->prepare("UPDATE student_info SET status = 'rejected' WHERE student_number = ?");
if (!$query) {
	logSecurityEvent('reject_account_prepare_failed', ['error' => $conn->error], 'error');
	header("Location: pending_accounts.php?error=Database error");
	exit;
}

$query->bind_param("s", $student_id);
$query->execute();

if ($query->affected_rows > 0) {
	logSecurityEvent('account_rejected', [
		'student_id' => $student_id,
		'admin_id' => $_SESSION['admin_id'] ?? $_SESSION['admin_username'] ?? null,
	], 'info');
	$query->close();
	closeDBConnection($conn);
	header("Location: pending_accounts.php?message=Account rejected successfully.");
	exit;
}

$query->close();
closeDBConnection($conn);
header("Location: pending_accounts.php?error=Student account not found");
exit();
?>

