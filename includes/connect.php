<?php
/**
 * Legacy compatibility wrapper.
 *
 * Historical callers used this file for both a raw DB connection and as the
 * student registration POST endpoint. Registration is now handled through the
 * dedicated service-backed handler so there is a single maintained code path.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/security_policy.php';

$isLegacyRegistrationRequest = (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' &&
    isset($_POST['student_id'], $_POST['last_name'], $_POST['first_name'], $_POST['password'])
);

if ($isLegacyRegistrationRequest) {
    require __DIR__ . '/../handlers/student_input_process.php';
    return;
}

// Preserve the historical side effect for any legacy includes that still
// expect `$conn` to exist after requiring this file.
$conn = getDBConnection();
