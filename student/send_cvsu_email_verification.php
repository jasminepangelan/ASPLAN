<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$studentId = (string) ($_SESSION['student_id'] ?? '');
$email = (string) ($_SESSION['student_email_verification_email'] ?? $_SESSION['email'] ?? '');

if (!sevIsCvsuEmail($email)) {
    sevClearSessionRequirement();
    echo json_encode(['success' => false, 'message' => 'CvSU email verification is not required for this account.']);
    exit;
}

$conn = getDBConnection();
$forceResend = isset($_POST['resend']) && $_POST['resend'] === '1';
$result = sevIssueOtp($conn, $studentId, $email, $forceResend);
closeDBConnection($conn);

if (!empty($result['already_verified'])) {
    sevClearSessionRequirement();
    echo json_encode([
        'success' => true,
        'message' => $result['message'] ?? 'Your CvSU email is already verified.',
        'redirect' => 'home_page_student.php',
    ]);
    exit;
}

echo json_encode([
    'success' => !empty($result['success']),
    'message' => $result['message'] ?? 'Unable to send the verification code right now.',
]);
