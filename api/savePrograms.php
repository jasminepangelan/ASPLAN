<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy_enforce.php';
require_once __DIR__ . '/../includes/env_loader.php';

header('Content-Type: application/json');

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

try {
    // Require admin authentication
    requireAdmin();

    // Validate request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Only POST requests allowed');
    }

    // Validate CSRF token
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    require_once __DIR__ . '/../includes/csrf.php';
    
    if (!$csrfToken || !validateCSRFToken($csrfToken)) {
        http_response_code(403);
        throw new Exception('CSRF token validation failed');
    }

    if ($useLaravelBridge) {
        $bridgeUrl = laravelBridgeUrl('/api/save-programs');
        $payload = file_get_contents('php://input');

        $response = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($bridgeUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 8,
                ],
            ]);
            $response = @file_get_contents($bridgeUrl, false, $context);
        }

        if ($response !== false) {
            echo $response;
            exit;
        }
    }

    // Get database connection
    $conn = getDBConnection();

    $conn->query("CREATE TABLE IF NOT EXISTS programs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Get and validate the programs from the request
    $payload = file_get_contents("php://input");
    $data = json_decode($payload, true);

    if (!$data || !isset($data['programs']) || !is_array($data['programs'])) {
        http_response_code(400);
        throw new Exception('Invalid request: programs array required');
    }

    $programs = array_map('trim', $data['programs']);
    $programs = array_filter($programs, function($p) { return !empty($p); });

    if (empty($programs)) {
        http_response_code(400);
        throw new Exception('Programs array cannot be empty');
    }

    // Use transaction for data integrity
    $conn->begin_transaction();

    // Delete all previous records with proper auth context
    $conn->query("DELETE FROM programs");

    // Insert new programs with prepared statements
    $stmt = $conn->prepare("INSERT INTO programs (name) VALUES (?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    foreach ($programs as $program) {
        $stmt->bind_param("s", $program);
        if (!$stmt->execute()) {
            throw new Exception('Insert failed: ' . $stmt->error);
        }
    }

    $stmt->close();

    // Commit transaction
    $conn->commit();

    // Log successful operation
    logSecurityEvent('programs_updated', [
        'admin_id' => $_SESSION['admin_id'] ?? $_SESSION['admin_username'],
        'program_count' => count($programs)
    ], 'info');

    closeDBConnection($conn);

    echo json_encode(['success' => true, 'message' => 'Programs updated successfully']);
} catch (Exception $e) {
    // Log error
    logSecurityEvent('programs_update_failed', ['error' => $e->getMessage()], 'error');
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
