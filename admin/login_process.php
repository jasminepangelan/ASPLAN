<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    echo json_encode(['status' => 'error', 'message' => 'Username or password cannot be empty.']);
    exit();
}

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

if ($useLaravelAuthBridge) {
    $bridgeUrl = laravelBridgeUrl('/api/unified-login');
    $payloadJson = json_encode([
        'username' => $username,
        'password' => $password,
        'remember_me' => false,
    ]);

    $bridgeResponse = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($bridgeUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $bridgeResponse = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payloadJson,
                'timeout' => 10,
            ],
        ]);
        $bridgeResponse = @file_get_contents($bridgeUrl, false, $context);
    }

    if ($bridgeResponse !== false) {
        $bridgeData = json_decode($bridgeResponse, true);
        if (is_array($bridgeData) && isset($bridgeData['status'])) {
            if (($bridgeData['status'] ?? '') === 'success' && ($bridgeData['user_type'] ?? '') === 'admin') {
                if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
                    session_regenerate_id(true);
                    foreach ($bridgeData['session'] as $key => $value) {
                        $_SESSION[$key] = $value;
                    }
                }

                header('Location: index.php');
                exit();
            }

            $message = $bridgeData['message'] ?? 'Invalid username or password.';
            echo json_encode(['status' => 'error', 'message' => $message]);
            exit();
        }
    }
}

// Get database connection (using PDO for this file since it was already using PDO)
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET NAMES utf8mb4");

    // Check if username exists in the `admins` table, with fallback to `admin`.
    $admin = false;
    foreach (['admins', 'admin'] as $adminTable) {
        $stmt = $pdo->prepare("SELECT * FROM `{$adminTable}` WHERE username = :username");
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            break;
        }
    }

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['username'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_full_name'] = $admin['full_name'] ?? trim(($admin['first_name'] ?? '') . ' ' . ($admin['middle_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
        header('Location: index.php');
        exit();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid username or password.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
?>
