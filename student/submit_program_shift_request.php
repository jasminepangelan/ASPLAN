<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: program_shift_request.php');
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    header('Location: program_shift_request.php?error=' . urlencode('Invalid security token. Please refresh the page and try again.'));
    exit();
}

$requestedProgram = trim((string)($_POST['requested_program'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

$conn = getDBConnection();
psEnsureProgramShiftTables($conn);
$result = null;

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        '/api/program-shift/student/submit',
        [
            'bridge_authorized' => true,
            'student_id' => (string) $_SESSION['student_id'],
            'requested_program' => $requestedProgram,
            'reason' => $reason,
        ]
    );

    if (is_array($bridgeData)) {
        $result = [
            'ok' => !empty($bridgeData['success']),
            'message' => (string) ($bridgeData['message'] ?? ''),
            'request_id' => $bridgeData['request_id'] ?? null,
            'request_code' => $bridgeData['request_code'] ?? null,
        ];
    }
}

if ($result === null) {
    $result = psCreateStudentRequest($conn, (string)$_SESSION['student_id'], $requestedProgram, $reason);
}
closeDBConnection($conn);

if (!empty($result['ok'])) {
    header('Location: program_shift_request.php?success=' . urlencode((string)$result['message']));
    exit();
}

header('Location: program_shift_request.php?error=' . urlencode((string)($result['message'] ?? 'Unable to submit request.')));
exit();

