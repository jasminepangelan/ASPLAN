<?php
/**
 * SECURITY QUICK START GUIDE
 * =========================
 * How to secure your handler/page quickly
 */

// Example 1: Admin-only handler (e.g., approve account)
/*
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';

// Require admin access
requireAdmin();

// Validate CSRF for POST
validateCSRFTokenRequired();

// Log the action
logSecurityEvent('action_name', ['student_id' => $id]);

// Use prepared statements
$stmt = $conn->prepare("UPDATE table SET col = ? WHERE id = ?");
$stmt->bind_param("si", $value, $id);
$stmt->execute();
?>
*/

// Example 2: Adviser-only handler
/*
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';

$auth = requireAdviser();
// $auth['user_id'], $auth['role'] available
?>
*/

// Example 3: Any authenticated handler
/*
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';

$auth = requireAuthenticated();
// Works for students, advisers, admins
?>
*/

// Example 4: API endpoint with JSON response
/*
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';

header('Content-Type: application/json');

try {
    requireAdmin();
    validateCSRFTokenRequired();
    
    // Your logic here
    
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    logSecurityEvent('api_error', ['error' => $e->getMessage()], 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
*/

// Example 5: Check role before performing action
/*
$auth = checkAuthenticated();
if ($auth['role'] === 'admin') {
    // Admin-specific code
}
*/

// Example 6: Multiple roles allowed
/*
$auth = requireRole(['admin', 'adviser']);
// Will error if neither admin nor adviser
?>
*/

?>
