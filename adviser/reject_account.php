<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

requireRole(['adviser', 'program_coordinator']);
validateCSRFTokenRequired();

$student_id = $_POST['student_id'] ?? $_GET['student_id'] ?? null;

if ($student_id === null || $student_id === '') {
	header("Location: pending_accounts.php?message=No account selected.");
	exit;
}

$student_id = trim((string)$student_id);

if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $student_id)) {
	header("Location: pending_accounts.php?message=Invalid student ID format.");
	exit;
}

if ((getenv('USE_LARAVEL_BRIDGE') === '1')) {
	$bridgeData = postLaravelJsonBridge(
		'/api/adviser/pending-accounts/reject',
		[
			'bridge_authorized' => true,
			'student_id' => $student_id,
			'adviser_id' => (int) ($_SESSION['id'] ?? 0),
		]
	);

	if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
		$message = urlencode((string) ($bridgeData['message'] ?? 'Account rejected successfully.'));
		header("Location: pending_accounts.php?message={$message}");
		exit;
	}
}

// Get database connection
$conn = getDBConnection();

if (!isset($_SESSION['id'])) {
	closeDBConnection($conn);
	header("Location: pending_accounts.php?message=Access denied. Please log in.");
	exit;
}

$adviser_id = (int)$_SESSION['id'];
$batch_query = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ?");
$batch_query->bind_param("i", $adviser_id);
$batch_query->execute();
$batch_result = $batch_query->get_result();

if ($batch_result->num_rows === 0) {
	$batch_query->close();
	closeDBConnection($conn);
	header("Location: pending_accounts.php?message=No batch assigned to this adviser.");
	exit;
}

$batches = [];
while ($row = $batch_result->fetch_assoc()) {
	$batches[] = (string)$row['batch'];
}
$batch_query->close();

$allowed = false;
foreach ($batches as $batch) {
	if (strpos($student_id, $batch) === 0) {
		$allowed = true;
		break;
	}
}

if (!$allowed) {
	closeDBConnection($conn);
	header("Location: pending_accounts.php?message=Access denied for selected student.");
	exit;
}

$query = $conn->prepare("UPDATE student_info SET status = 'rejected' WHERE student_number = ?");
$query->bind_param("s", $student_id);
$query->execute();

if ($query->affected_rows > 0) {
	$query->close();
	closeDBConnection($conn);
	header("Location: pending_accounts.php?message=Account rejected successfully.");
	exit;
}

$query->close();
closeDBConnection($conn);
header("Location: pending_accounts.php?message=Student account not found.");
exit;
?>

