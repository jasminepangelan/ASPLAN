<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

requireRole(['adviser', 'program_coordinator']);
validateCSRFTokenRequired();

// Check if student_id is provided
$student_id = $_POST['student_id'] ?? $_GET['student_id'] ?? null;

if ($student_id !== null && $student_id !== '') {
    $student_id = trim((string)$student_id);

    if (!preg_match('/^[A-Za-z0-9\-]{1,30}$/', $student_id)) {
        header("Location: pending_accounts.php?message=Invalid student ID format.");
        exit;
    }

    $useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

    if ($useLaravelBridge) {
        $bridgeData = postLaravelJsonBridge(
            '/api/adviser/pending-accounts/approve',
            [
                'bridge_authorized' => true,
                'student_id' => $student_id,
                'adviser_id' => (int) ($_SESSION['id'] ?? 0),
            ]
        );

        if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
            $message = urlencode((string) ($bridgeData['message'] ?? 'Account approved successfully'));
            header("Location: pending_accounts.php?message={$message}");
            exit;
        }
    }

    // Get database connection
    $conn = getDBConnection();

    // Get adviser's batches
    if (!isset($_SESSION['id'])) {
        closeDBConnection($conn);
        header("Location: pending_accounts.php?message=Access denied. Please log in.");
        exit;
    }
    $adviser_id = $_SESSION['id'];
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
        $batches[] = $row['batch'];
    }
    $batch_query->close();

    // Check if student_id starts with any of adviser's batches
    $allowed = false;
    foreach ($batches as $batch) {
        if (strpos($student_id, (string)$batch) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        logSecurityEvent('adviser_approve_denied_batch_mismatch', [
            'student_id' => $student_id,
            'adviser_id' => $adviser_id,
        ], 'warning');
        closeDBConnection($conn);
        header("Location: pending_accounts.php?message=Access denied for selected student.");
        exit;
    }

    // Update the student's status to 'approved'
    $update_stmt = $conn->prepare("UPDATE student_info SET status = 'approved' WHERE student_number = ?");
    $update_stmt->bind_param("s", $student_id);
    if ($update_stmt->execute()) {
        $update_stmt->close();
        closeDBConnection($conn);
        header("Location: pending_accounts.php?message=Account approved successfully");
        exit;
    } else {
        $update_stmt->close();
        closeDBConnection($conn);
        header("Location: pending_accounts.php?message=Error approving account.");
        exit;
    }
} else {
    // Redirect back if student_id is not set
    header("Location: pending_accounts.php?message=No account selected.");
    exit;
}
?>

