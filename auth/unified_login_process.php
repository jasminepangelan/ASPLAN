<?php
/**
 * Unified Login Process
 * Handles authentication for students, advisers, and admins
 * Detects user type based on credentials from the database
 */

// Load configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/security_policy.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get database connection
$conn = getDBConnection();

// Function to send JSON response with headers
function sendJsonResponse($data) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
    }
    echo json_encode($data);
    exit();
}

// Ensure the form was submitted using POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Invalid request method.']);
}

try {
    // Check rate limit (settings-backed defaults if configured)
    $rateLimit = checkRateLimit('login');
    if (!$rateLimit['allowed']) {
        sendJsonResponse([
            'status' => 'rate_limited', 
            'message' => $rateLimit['message']
        ]);
    }

    $useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

    // Validate the login CSRF token against the current PHP session before
    // delegating authentication to the Laravel bridge.
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        recordAttempt('login');
        closeDBConnection($conn);
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Invalid security token. Please refresh the page and try again.'
        ]);
    }

    // Get and sanitize input - accept both 'username' and 'student_id' for backward compatibility
    $username = trim($_POST['username'] ?? $_POST['student_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

    // Check if username or password is empty
    if (empty($username) || empty($password)) {
        recordAttempt('login');
        if (!empty($username)) {
            registerFailedLoginAttempt($conn, $username);
        }
        sendJsonResponse(['status' => 'error', 'message' => 'Student ID/Username or password cannot be empty.']);
    }

    $accountLockStatus = getAccountLockoutStatus($conn, $username);
    if ($accountLockStatus['locked']) {
        $minutes = (int)ceil($accountLockStatus['remaining_seconds'] / 60);
        sendJsonResponse([
            'status' => 'rate_limited',
            'message' => 'This account is temporarily locked due to repeated failed logins. Try again in ' . max(1, $minutes) . ' minute(s).'
        ]);
    }

    $bridgeUrl = laravelBridgeUrl('/api/unified-login');
    $payloadJson = json_encode([
        'username' => $username,
        'password' => $password,
        'remember_me' => $remember_me,
    ]);

    if ($useLaravelAuthBridge) {
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
                if ($bridgeData['status'] === 'success') {
                    resetRateLimit('login');
                    clearAccountLockout($conn, $username);
                    session_regenerate_id(true);

                    if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
                        foreach ($bridgeData['session'] as $key => $value) {
                            $_SESSION[$key] = $value;
                        }
                    }

                    if ($remember_me && isset($bridgeData['remember']['cookie_value'], $bridgeData['remember']['expires'])) {
                        setAppCookie('remember_me', (string)$bridgeData['remember']['cookie_value'], (int)$bridgeData['remember']['expires'], '/');
                    }

                    closeDBConnection($conn);
                    sendJsonResponse([
                        'status' => 'success',
                        'redirect' => $bridgeData['redirect'] ?? 'index.html',
                        'user_type' => $bridgeData['user_type'] ?? 'unknown',
                    ]);
                }

                if ($bridgeData['status'] === 'error') {
                    recordAttempt('login');
                    registerFailedLoginAttempt($conn, $username);
                    closeDBConnection($conn);
                    sendJsonResponse($bridgeData);
                }

                if (in_array($bridgeData['status'], ['pending', 'rejected', 'rate_limited', 'session_expired'], true)) {
                    closeDBConnection($conn);
                    sendJsonResponse($bridgeData);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Unified login runtime failure: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    closeDBConnection($conn);
    sendJsonResponse([
        'status' => 'error',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Login is temporarily unavailable. Please try again shortly.',
    ]);
}

// Function to check student credentials
function checkStudentCredentials($conn, $username, $password) {
    $query = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, email, password, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date, picture, status, program FROM student_info WHERE student_number = ?");
    if (!$query) {
        throw new RuntimeException('Student credentials query could not be prepared: ' . $conn->error);
    }
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Check if auto-approval is enabled
        $auto_approve_query = "SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_students'";
        $auto_approve_result = $conn->query($auto_approve_query);
        $auto_approve_enabled = false;
        
        if ($auto_approve_result && $auto_approve_result->num_rows > 0) {
            $auto_row = $auto_approve_result->fetch_assoc();
            $auto_approve_enabled = ($auto_row['setting_value'] === '1');
        }

        // If auto-approval is enabled and account is pending, auto-approve it
        if ($auto_approve_enabled && $row['status'] === 'pending') {
            $update_query = $conn->prepare("UPDATE student_info SET status = 'approved', approved_by = 'auto-system' WHERE student_number = ?");
            $update_query->bind_param("s", $username);
            $update_query->execute();
            $row['status'] = 'approved';
        }

        // Return result with status info
        return [
            'found' => true,
            'data' => $row,
            'type' => 'student'
        ];
    }
    
    return ['found' => false];
}

// Function to check admin credentials
function checkAdminCredentials($conn, $username, $password) {
    $query = $conn->prepare("SELECT username, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, password FROM admin WHERE username = ?");
    if (!$query) {
        throw new RuntimeException('Admin credentials query could not be prepared: ' . $conn->error);
    }
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return [
            'found' => true,
            'data' => $row,
            'type' => 'admin'
        ];
    }
    
    return ['found' => false];
}

// Function to check adviser credentials
function checkAdviserCredentials($conn, $username, $password) {
    $query = $conn->prepare("SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, username, password, sex, pronoun FROM adviser WHERE username = ?");
    if (!$query) {
        throw new RuntimeException('Adviser credentials query could not be prepared: ' . $conn->error);
    }
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return [
            'found' => true,
            'data' => $row,
            'type' => 'adviser'
        ];
    }
    
    return ['found' => false];
}

// Function to check program coordinator credentials
function resolveProgramCoordinatorTable($conn) {
    $singular = $conn->query("SHOW TABLES LIKE 'program_coordinator'");
    if ($singular && $singular->num_rows > 0) {
        return 'program_coordinator';
    }

    $plural = $conn->query("SHOW TABLES LIKE 'program_coordinators'");
    if ($plural && $plural->num_rows > 0) {
        return 'program_coordinators';
    }

    return null;
}

function checkProgramCoordinatorCredentials($conn, $username, $password) {
    $table = resolveProgramCoordinatorTable($conn);
    if ($table === null) {
        return ['found' => false];
    }

    $query = $conn->prepare("SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, username, password, sex, pronoun FROM `$table` WHERE username = ?");
    if (!$query) {
        throw new RuntimeException('Program coordinator credentials query could not be prepared: ' . $conn->error);
    }
    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return [
            'found' => true,
            'data' => $row,
            'type' => 'program_coordinator'
        ];
    }

    return ['found' => false];
}

// Try to find user in all tables
$userFound = null;
$userType = null;

// First, check if it looks like a student ID (numeric)
if (preg_match('/^[0-9]+$/', $username)) {
    // Likely a student ID, check students first
    $result = checkStudentCredentials($conn, $username, $password);
    if ($result['found']) {
        $userFound = $result;
        $userType = 'student';
    }
}

// If not found as student, check admin
if (!$userFound) {
    $result = checkAdminCredentials($conn, $username, $password);
    if ($result['found']) {
        $userFound = $result;
        $userType = 'admin';
    }
}

// If not found as admin, check adviser
if (!$userFound) {
    $result = checkAdviserCredentials($conn, $username, $password);
    if ($result['found']) {
        $userFound = $result;
        $userType = 'adviser';
    }
}

// If not found as adviser, check program coordinator
if (!$userFound) {
    $result = checkProgramCoordinatorCredentials($conn, $username, $password);
    if ($result['found']) {
        $userFound = $result;
        $userType = 'program_coordinator';
    }
}

// If still not found and username is numeric, it might be a student that doesn't exist
if (!$userFound && preg_match('/^[0-9]+$/', $username)) {
    recordAttempt('login');
    registerFailedLoginAttempt($conn, $username);
    sendJsonResponse(['status' => 'error', 'message' => 'Student ID not found. Please check and try again.']);
}

// User not found in any table
if (!$userFound) {
    recordAttempt('login');
    registerFailedLoginAttempt($conn, $username);
    sendJsonResponse(['status' => 'error', 'message' => 'Account not found. Please check your credentials.']);
}

// Verify password
$userData = $userFound['data'];
if (!password_verify($password, $userData['password'])) {
    recordAttempt('login');
    registerFailedLoginAttempt($conn, $username);
    sendJsonResponse(['status' => 'error', 'message' => 'Invalid password. Please try again.']);
}

clearAccountLockout($conn, $username);

// Handle user type specific logic
switch ($userType) {
    case 'student':
        // Check account status for students
        if ($userData['status'] === 'pending') {
            sendJsonResponse(['status' => 'pending', 'message' => 'Your account is pending approval. Please wait for the admin to approve.']);
        } elseif ($userData['status'] === 'rejected') {
            sendJsonResponse(['status' => 'rejected', 'message' => 'Your account was rejected. Please contact admin for more information.']);
        }
        
        // Reset rate limit on successful login
        resetRateLimit('login');
        
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Store student session data
        $_SESSION['student_id'] = $userData['student_id'];
        $_SESSION['last_name'] = $userData['last_name'];
        $_SESSION['first_name'] = $userData['first_name'];
        $_SESSION['middle_name'] = $userData['middle_name'];
        $_SESSION['email'] = $userData['email'] ?? '';
        $_SESSION['contact_no'] = $userData['contact_no'];
        $_SESSION['address'] = $userData['address'];
        $_SESSION['admission_date'] = $userData['admission_date'];
        $_SESSION['picture'] = $userData['picture'];
        $_SESSION['program'] = $userData['program'] ?? '';
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_type'] = 'student';
        
        // Handle Remember Me for students
        if ($remember_me) {
            $remember_token = bin2hex(random_bytes(32));
            $remember_token_hash = password_hash($remember_token, PASSWORD_DEFAULT);
            $expiry = time() + (30 * 24 * 60 * 60); // 30 days
            
            $token_query = $conn->prepare("UPDATE student_info SET remember_token = ?, remember_token_expiry = FROM_UNIXTIME(?) WHERE student_number = ?");
            $token_query->bind_param("sis", $remember_token_hash, $expiry, $userData['student_id']);
            $token_query->execute();
            
            setAppCookie(
                'remember_me',
                $userData['student_id'] . ':' . $remember_token . ':student',
                $expiry,
                '/'
            );
        }
        
        sendJsonResponse(['status' => 'success', 'redirect' => 'student/home_page_student.php', 'user_type' => 'student']);
        break;
        
    case 'admin':
        // Reset rate limit on successful login
        resetRateLimit('login');
        
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Store admin session data
        $_SESSION['admin_id'] = $userData['username'];
        $_SESSION['admin_username'] = $userData['username'];
        $_SESSION['admin_full_name'] = $userData['full_name'];
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_type'] = 'admin';
        
        sendJsonResponse(['status' => 'success', 'redirect' => 'admin/index.php', 'user_type' => 'admin']);
        break;
        
    case 'adviser':
        // Reset rate limit on successful login
        resetRateLimit('login');
        
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Store adviser session data
        $_SESSION['id'] = $userData['id'];
        $_SESSION['full_name'] = $userData['full_name'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['pronoun'] = $userData['pronoun'];
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_type'] = 'adviser';
        
        sendJsonResponse(['status' => 'success', 'redirect' => 'adviser/index.php', 'user_type' => 'adviser']);
        break;

    case 'program_coordinator':
        // Reset rate limit on successful login
        resetRateLimit('login');

        // Regenerate session ID
        session_regenerate_id(true);

        // Store program coordinator session data
        $_SESSION['id'] = $userData['id'];
        $_SESSION['full_name'] = $userData['full_name'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['pronoun'] = $userData['pronoun'];
        $_SESSION['login_time'] = time();
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_type'] = 'program_coordinator';

        sendJsonResponse(['status' => 'success', 'redirect' => 'program_coordinator/index.php', 'user_type' => 'program_coordinator']);
        break;
}

// Close database connection
closeDBConnection($conn);
?>
