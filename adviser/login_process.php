<?php
require_once __DIR__ . '/../config/config.php';

function resolveProgramCoordinatorTable(PDO $pdo): ?string {
    $singular = $pdo->query("SHOW TABLES LIKE 'program_coordinator'");
    if ($singular && $singular->rowCount() > 0) {
        return 'program_coordinator';
    }

    $plural = $pdo->query("SHOW TABLES LIKE 'program_coordinators'");
    if ($plural && $plural->rowCount() > 0) {
        return 'program_coordinators';
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>alert('Invalid request method.'); window.location.href = 'login.php';</script>";
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    echo "<script>alert('Username or password cannot be empty.'); window.location.href = 'login.php';</script>";
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
            if (($bridgeData['status'] ?? '') === 'success') {
                $userType = $bridgeData['user_type'] ?? '';
                if ($userType === 'adviser' || $userType === 'program_coordinator') {
                    if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
                        session_regenerate_id(true);
                        foreach ($bridgeData['session'] as $key => $value) {
                            $_SESSION[$key] = $value;
                        }
                    }

                    if ($userType === 'program_coordinator') {
                        header('Location: ../program_coordinator/index.php');
                    } else {
                        header('Location: index.php');
                    }
                    exit();
                }

                echo "<script>alert('Invalid username or password.'); window.location.href = 'login.php';</script>";
                exit();
            }

            $message = addslashes((string)($bridgeData['message'] ?? 'Invalid username or password.'));
            echo "<script>alert('{$message}'); window.location.href = 'login.php';</script>";
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

    $staffType = 'adviser';

    // Check if username exists in the `adviser` table
    $stmt = $pdo->prepare("SELECT * FROM adviser WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: allow program coordinator credentials on the same login form
    if (!$staff) {
        $programCoordinatorTable = resolveProgramCoordinatorTable($pdo);
        if ($programCoordinatorTable !== null) {
            $pcStmt = $pdo->prepare("SELECT * FROM `$programCoordinatorTable` WHERE username = :username");
            $pcStmt->execute([':username' => $username]);
            $staff = $pcStmt->fetch(PDO::FETCH_ASSOC);
            if ($staff) {
                $staffType = 'program_coordinator';
            }
        }
    }

    // Check if staff user was found and password matches
    if ($staff && password_verify($password, $staff['password'])) {
        // Store necessary user data in the session
        $_SESSION['id'] = $staff['id'];
        $_SESSION['full_name'] = trim(($staff['first_name'] ?? '') . ' ' . ($staff['middle_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
        $_SESSION['username'] = $staff['username'];
        $_SESSION['pronoun'] = $staff['pronoun'] ?? '';
        $_SESSION['user_type'] = $staffType;

        // Redirect based on authenticated staff type
        if ($staffType === 'program_coordinator') {
            header("Location: ../program_coordinator/index.php");
        } else {
            header("Location: index.php");
        }
        exit();
    }

    // Incorrect credentials
    echo "<script>alert('Invalid username or password.'); window.location.href = 'login.php';</script>";
} catch (PDOException $e) {
    // Database connection or query error
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    // General error
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
?>
