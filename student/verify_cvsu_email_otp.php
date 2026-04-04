<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}

$studentId = (string) ($_SESSION['student_id'] ?? '');
$email = (string) ($_SESSION['student_email_verification_email'] ?? $_SESSION['email'] ?? '');
$code = trim((string) ($_POST['code'] ?? ''));

$conn = getDBConnection();
$result = sevVerifyOtp($conn, $studentId, $email, $code);
closeDBConnection($conn);

echo json_encode([
    'success' => !empty($result['success']),
    'message' => $result['message'] ?? 'Unable to verify your CvSU email right now.',
    'redirect' => !empty($result['success']) ? 'home_page_student.php' : null,
]);
