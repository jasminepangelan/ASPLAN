<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$username = (string) ($_SESSION['username'] ?? '');
$email = (string) ($_SESSION['program_coordinator_email_verification_email'] ?? $_SESSION['program_coordinator_email'] ?? '');
$code = trim((string) ($_POST['code'] ?? ''));

$conn = getDBConnection();
$result = cevVerifyOtp($conn, $username, $email, $code);
closeDBConnection($conn);

echo json_encode([
    'success' => !empty($result['success']),
    'message' => $result['message'] ?? 'Unable to verify your CvSU email right now.',
    'redirect' => !empty($result['success']) ? 'index.php' : null,
]);
