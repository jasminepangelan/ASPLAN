<?php
/**
 * Check Remember Me Cookie
 * Automatically log in user if valid remember me cookie exists
 */

require_once __DIR__ . '/../config/config.php';

$useLaravelAuthBridge = getenv('USE_LARAVEL_AUTH_BRIDGE') === '1';

// Set flag for checking
$autoLoginAttempted = false;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is already logged in, skip remember me check
if (isset($_SESSION['student_id'])) {
    return;
}

// Check if remember me cookie exists
if (isset($_COOKIE['remember_me'])) {
    if (!$useLaravelAuthBridge) {
        return;
    }

    $autoLoginAttempted = true;
    $cookie_data = $_COOKIE['remember_me'];
    $parts = explode(':', $cookie_data);
    $student_id = '';
    $remember_token = '';
    $isStudentToken = true;
    
    if (count($parts) === 3) {
        list($student_id, $remember_token, $account_type) = $parts;
        $isStudentToken = ($account_type === 'student');
    } elseif (count($parts) === 2) {
        // Backward compatibility for older remember-me cookie format.
        list($student_id, $remember_token) = $parts;
    }

    if ($isStudentToken && $student_id !== '' && $remember_token !== '') {
        $bridgeUrl = laravelBridgeUrl('/api/check-auto-login');
        $payloadJson = json_encode([
            'remember_me' => $cookie_data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        $bridgeResponse = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($bridgeUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payloadJson,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
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
                    'timeout' => 8,
                ],
            ]);
            $bridgeResponse = @file_get_contents($bridgeUrl, false, $context);
        }

        if ($bridgeResponse !== false) {
            $bridgeData = json_decode($bridgeResponse, true);
            if (is_array($bridgeData)) {
                if (isset($bridgeData['session']) && is_array($bridgeData['session'])) {
                    session_regenerate_id(true);
                    foreach ($bridgeData['session'] as $key => $value) {
                        $_SESSION[$key] = $value;
                    }
                }

                if (!empty($bridgeData['clear_cookie'])) {
                    clearAppCookie('remember_me', '/');
                    clearAppCookie('remember_me', '/PEAS/');
                }

                if (!empty($bridgeData['redirect'])) {
                    header('Location: ' . $bridgeData['redirect']);
                    exit();
                }

                return;
            }
        }

        return;

        
        // Validate student_id format
        if (preg_match('/^[0-9]{1,20}$/', $student_id)) {
            $conn = getDBConnection();
            
            // Get user data and remember token from database
            $query = $conn->prepare("SELECT student_number AS student_id, last_name, first_name, middle_name, email, contact_number AS contact_no, CONCAT_WS(', ', house_number_street, brgy, town, province) AS address, date_of_admission AS admission_date, picture, remember_token FROM student_info WHERE student_number = ? AND remember_token IS NOT NULL AND remember_token_expiry > NOW()");
            $query->bind_param("s", $student_id);
            $query->execute();
            $result = $query->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                
                // Verify the remember token
                if (password_verify($remember_token, $row['remember_token'])) {
                    // Token is valid - auto login
                    session_regenerate_id(true);
                    
                    // Store user data in session
                    $_SESSION['student_id'] = $student_id;
                    $_SESSION['last_name'] = $row['last_name'];
                    $_SESSION['first_name'] = $row['first_name'];
                    $_SESSION['middle_name'] = $row['middle_name'];
                    $_SESSION['email'] = $row['email'] ?? '';
                    $_SESSION['contact_no'] = $row['contact_no'];
                    $_SESSION['address'] = $row['address'];
                    $_SESSION['admission_date'] = $row['admission_date'];
                    $_SESSION['picture'] = $row['picture'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $_SESSION['auto_login'] = true; // Flag to indicate auto-login
                    
                    // Redirect to student home page
                    header('Location: student/home_page_student.php');
                    exit();
                }
            }
            
            // If token is invalid or expired, clear the cookie
            clearAppCookie('remember_me', '/');
            clearAppCookie('remember_me', '/PEAS/');
            
            closeDBConnection($conn);
        }
    }
}
?>
