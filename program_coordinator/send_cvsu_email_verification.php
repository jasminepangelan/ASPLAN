<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$username = (string) ($_SESSION['username'] ?? '');
$email = (string) ($_SESSION['program_coordinator_email_verification_email'] ?? $_SESSION['program_coordinator_email'] ?? '');

if (!cevIsCvsuEmail($email)) {
    cevClearSessionRequirement();
    echo json_encode(['success' => false, 'message' => 'CvSU email verification is not required for this account.']);
    exit;
}

$conn = getDBConnection();
$forceResend = isset($_POST['resend']) && $_POST['resend'] === '1';
$result = cevIssueOtp($conn, $username, $email, $forceResend);
closeDBConnection($conn);

if (!empty($result['already_verified'])) {
    cevClearSessionRequirement();
    echo json_encode([
        'success' => true,
        'message' => $result['message'] ?? 'Your CvSU email is already verified.',
        'redirect' => 'index.php',
    ]);
    exit;
}

echo json_encode([
    'success' => !empty($result['success']),
    'message' => $result['message'] ?? 'Unable to send the verification code right now.',
]);
