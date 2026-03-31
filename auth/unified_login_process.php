<?php
/**
 * Unified Login Process
 * Handles authentication for students, advisers, admins, and program coordinators.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/security_policy.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function sendJsonResponse($data)
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
    }

    echo json_encode($data);
    exit();
}

function checkStudentCredentials($conn, $username, $password)
{
    $query = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, email, password, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date, picture, status, program FROM student_info WHERE student_number = ?");
    if (!$query) {
        throw new RuntimeException('Student credentials query could not be prepared: ' . ($conn->error ?? 'unknown database error'));
    }

    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        return [
            'found' => true,
            'data' => $row,
            'type' => 'student',
        ];
    }

    return ['found' => false];
}

function checkAdminCredentials($conn, $username, $password)
{
    $query = $conn->prepare("SELECT username, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, password FROM admin WHERE username = ?");
    if (!$query) {
        throw new RuntimeException('Admin credentials query could not be prepared: ' . ($conn->error ?? 'unknown database error'));
    }

    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        return [
            'found' => true,
            'data' => $result->fetch_assoc(),
            'type' => 'admin',
        ];
    }

    return ['found' => false];
}

function checkAdviserCredentials($conn, $username, $password)
{
    $query = $conn->prepare("SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, username, password, sex, pronoun FROM adviser WHERE username = ?");
    if (!$query) {
        throw new RuntimeException('Adviser credentials query could not be prepared: ' . ($conn->error ?? 'unknown database error'));
    }

    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        return [
            'found' => true,
            'data' => $result->fetch_assoc(),
            'type' => 'adviser',
        ];
    }

    return ['found' => false];
}

function resolveProgramCoordinatorTable($conn)
{
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

function checkProgramCoordinatorCredentials($conn, $username, $password)
{
    $table = resolveProgramCoordinatorTable($conn);
    if ($table === null) {
        return ['found' => false];
    }

    $query = $conn->prepare("SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, username, password, sex, pronoun FROM `$table` WHERE username = ?");
    if (!$query) {
        throw new RuntimeException('Program coordinator credentials query could not be prepared: ' . ($conn->error ?? 'unknown database error'));
    }

    $query->bind_param("s", $username);
    $query->execute();
    $result = $query->get_result();

    if ($result->num_rows > 0) {
        return [
            'found' => true,
            'data' => $result->fetch_assoc(),
            'type' => 'program_coordinator',
        ];
    }

    return ['found' => false];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Invalid request method.']);
}

$conn = null;

try {
    $rateLimit = checkRateLimit('login');
    if (!$rateLimit['allowed']) {
        sendJsonResponse([
            'status' => 'rate_limited',
            'message' => $rateLimit['message'],
        ]);
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        recordAttempt('login');
        sendJsonResponse([
            'status' => 'error',
            'message' => 'Invalid security token. Please refresh the page and try again.',
        ]);
    }

    $username = trim($_POST['username'] ?? $_POST['student_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
    $useLaravelAuthBridge = (getenv('USE_LARAVEL_AUTH_BRIDGE') ?: '') === '1';

    if ($username === '' || $password === '') {
        recordAttempt('login');
        sendJsonResponse(['status' => 'error', 'message' => 'Student ID/Username or password cannot be empty.']);
    }

    if ($useLaravelAuthBridge) {
        $bridgeData = postLaravelJsonBridge('/api/unified-login', [
            'username' => $username,
            'password' => $password,
            'remember_me' => $rememberMe,
        ], 10);

        if (is_array($bridgeData) && isset($bridgeData['status'])) {
            if ($bridgeData['status'] === 'success') {
                resetRateLimit('login');
                session_regenerate_id(true);

                if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
                    foreach ($bridgeData['session'] as $key => $value) {
                        $_SESSION[$key] = $value;
                    }
                }

                if ($rememberMe && isset($bridgeData['remember']['cookie_value'], $bridgeData['remember']['expires'])) {
                    setAppCookie('remember_me', (string) $bridgeData['remember']['cookie_value'], (int) $bridgeData['remember']['expires'], '/');
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
                sendJsonResponse($bridgeData);
            }

            if (in_array($bridgeData['status'], ['pending', 'rejected', 'rate_limited', 'session_expired'], true)) {
                sendJsonResponse($bridgeData);
            }
        }
    }

    $conn = getDBConnection();

    $accountLockStatus = getAccountLockoutStatus($conn, $username);
    if (!empty($accountLockStatus['locked'])) {
        $minutes = (int) ceil(((int) ($accountLockStatus['remaining_seconds'] ?? 0)) / 60);
        sendJsonResponse([
            'status' => 'rate_limited',
            'message' => 'This account is temporarily locked due to repeated failed logins. Try again in ' . max(1, $minutes) . ' minute(s).',
        ]);
    }

    $userFound = null;
    $userType = null;

    if (preg_match('/^[0-9]+$/', $username)) {
        $result = checkStudentCredentials($conn, $username, $password);
        if (!empty($result['found'])) {
            $userFound = $result;
            $userType = 'student';
        }
    }

    if (!$userFound) {
        $result = checkAdminCredentials($conn, $username, $password);
        if (!empty($result['found'])) {
            $userFound = $result;
            $userType = 'admin';
        }
    }

    if (!$userFound) {
        $result = checkAdviserCredentials($conn, $username, $password);
        if (!empty($result['found'])) {
            $userFound = $result;
            $userType = 'adviser';
        }
    }

    if (!$userFound) {
        $result = checkProgramCoordinatorCredentials($conn, $username, $password);
        if (!empty($result['found'])) {
            $userFound = $result;
            $userType = 'program_coordinator';
        }
    }

    if (!$userFound && preg_match('/^[0-9]+$/', $username)) {
        recordAttempt('login');
        registerFailedLoginAttempt($conn, $username);
        sendJsonResponse(['status' => 'error', 'message' => 'Student ID not found. Please check and try again.']);
    }

    if (!$userFound) {
        recordAttempt('login');
        registerFailedLoginAttempt($conn, $username);
        sendJsonResponse(['status' => 'error', 'message' => 'Account not found. Please check your credentials.']);
    }

    $userData = $userFound['data'];
    if (!password_verify($password, $userData['password'])) {
        recordAttempt('login');
        registerFailedLoginAttempt($conn, $username);
        sendJsonResponse(['status' => 'error', 'message' => 'Invalid password. Please try again.']);
    }

    clearAccountLockout($conn, $username);

    switch ($userType) {
        case 'student':
            if (($userData['status'] ?? '') === 'pending') {
                sendJsonResponse(['status' => 'pending', 'message' => 'Your account is pending approval. Please wait for the admin to approve.']);
            }

            if (($userData['status'] ?? '') === 'rejected') {
                sendJsonResponse(['status' => 'rejected', 'message' => 'Your account was rejected. Please contact admin for more information.']);
            }

            resetRateLimit('login');
            session_regenerate_id(true);

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

            if ($rememberMe) {
                $rememberToken = bin2hex(random_bytes(32));
                $rememberTokenHash = password_hash($rememberToken, PASSWORD_DEFAULT);
                $expiry = time() + (30 * 24 * 60 * 60);

                $tokenQuery = $conn->prepare("UPDATE student_info SET remember_token = ?, remember_token_expiry = FROM_UNIXTIME(?) WHERE student_number = ?");
                if ($tokenQuery) {
                    $tokenQuery->bind_param("sis", $rememberTokenHash, $expiry, $userData['student_id']);
                    $tokenQuery->execute();
                }

                setAppCookie('remember_me', $userData['student_id'] . ':' . $rememberToken . ':student', $expiry, '/');
            }

            closeDBConnection($conn);
            sendJsonResponse(['status' => 'success', 'redirect' => 'student/home_page_student.php', 'user_type' => 'student']);
            break;

        case 'admin':
            resetRateLimit('login');
            session_regenerate_id(true);

            $_SESSION['admin_id'] = $userData['username'];
            $_SESSION['admin_username'] = $userData['username'];
            $_SESSION['admin_full_name'] = $userData['full_name'];
            $_SESSION['login_time'] = time();
            $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $_SESSION['user_type'] = 'admin';

            closeDBConnection($conn);
            sendJsonResponse(['status' => 'success', 'redirect' => 'admin/index.php', 'user_type' => 'admin']);
            break;

        case 'adviser':
            resetRateLimit('login');
            session_regenerate_id(true);

            $_SESSION['id'] = $userData['id'];
            $_SESSION['full_name'] = $userData['full_name'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['pronoun'] = $userData['pronoun'];
            $_SESSION['login_time'] = time();
            $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $_SESSION['user_type'] = 'adviser';

            closeDBConnection($conn);
            sendJsonResponse(['status' => 'success', 'redirect' => 'adviser/index.php', 'user_type' => 'adviser']);
            break;

        case 'program_coordinator':
            resetRateLimit('login');
            session_regenerate_id(true);

            $_SESSION['id'] = $userData['id'];
            $_SESSION['full_name'] = $userData['full_name'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['pronoun'] = $userData['pronoun'];
            $_SESSION['login_time'] = time();
            $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $_SESSION['user_type'] = 'program_coordinator';

            closeDBConnection($conn);
            sendJsonResponse(['status' => 'success', 'redirect' => 'program_coordinator/index.php', 'user_type' => 'program_coordinator']);
            break;
    }

    closeDBConnection($conn);
    sendJsonResponse(['status' => 'error', 'message' => 'Unsupported account type.']);
} catch (Throwable $e) {
    error_log('Unified login runtime failure: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    closeDBConnection($conn);
    sendJsonResponse([
        'status' => 'error',
        'message' => defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : 'Login is temporarily unavailable. Please try again shortly.',
    ]);
}
