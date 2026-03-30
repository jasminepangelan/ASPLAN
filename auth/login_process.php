<?php
/**
 * Legacy Login Process - Redirects to Unified Login
 * This file is kept for backward compatibility with cached JavaScript
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Map old 'student_id' field to 'username' if needed
if (isset($_POST['student_id']) && !isset($_POST['username'])) {
    $_POST['username'] = $_POST['student_id'];
}

// Include the unified login process
require_once __DIR__ . '/unified_login_process.php';
exit();

/* Old code below - no longer used
// Load configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DON'T set headers yet - we need to set cookie first if remember me is checked

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check rate limit (5 attempts per 5 minutes)
    $rateLimit = checkRateLimit('login');
    if (!$rateLimit['allowed']) {
        sendJsonResponse([
            'status' => 'rate_limited', 
            'message' => $rateLimit['message']
        ]);
    }
    
    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrfToken)) {
        recordAttempt('login');
        sendJsonResponse([
            'status' => 'error', 
            'message' => 'Invalid security token. Please refresh the page and try again.'
        ]);
    }
    
    $student_id = trim($_POST['student_id'] ?? $_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Check if student_id or password is empty
    if (empty($student_id) || empty($password)) {
        recordAttempt('login');
        sendJsonResponse(['status' => 'error', 'message' => 'Student ID/Username or password cannot be empty.']);
    }
    
    // Additional input validation
    if (!preg_match('/^[0-9]{1,20}$/', $student_id)) {
        recordAttempt('login');
        sendJsonResponse(['status' => 'error', 'message' => 'Invalid Student ID format.']);
    }

    // Check credentials in the database
    $query = $conn->prepare("SELECT student_id, last_name, first_name, middle_name, email, password, contact_no, address, admission_date, picture, status FROM students WHERE student_id = ?");
    $query->bind_param("s", $student_id);
    $query->execute();
    $result = $query->get_result();

    // Check if student_id exists and password matches
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        // Check if auto-approval is currently enabled
        $auto_approve_query = "SELECT setting_value FROM system_settings WHERE setting_name = 'auto_approve_students'";
        $auto_approve_result = $conn->query($auto_approve_query);
        $auto_approve_enabled = false;
        
        if ($auto_approve_result && $auto_approve_result->num_rows > 0) {
            $auto_row = $auto_approve_result->fetch_assoc();
            $auto_approve_enabled = ($auto_row['setting_value'] === '1');
        }

        // If auto-approval is enabled and account is pending, auto-approve it
        if ($auto_approve_enabled && $row['status'] === 'pending') {
            $update_query = $conn->prepare("UPDATE students SET status = 'approved', approved_by = 'auto-system', approved_at = NOW() WHERE student_id = ?");
            $update_query->bind_param("s", $student_id);
            $update_query->execute();
            $row['status'] = 'approved'; // Update the local status for this login attempt
        }

        // Check account status
        if ($row['status'] === 'pending') {
            sendJsonResponse(['status' => 'pending', 'message' => 'Your account is pending approval. Please wait for the admin to approve.']);
        } elseif ($row['status'] === 'rejected') {
            sendJsonResponse(['status' => 'rejected', 'message' => 'Your account was rejected. Please contact admin for more information.']);
        }

        // Verify the password using password_verify
        if (password_verify($password, $row['password'])) {
            // Reset rate limit on successful login
            resetRateLimit('login');
            
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            // Store necessary user data in the session
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

            // Handle "Remember Me" functionality
            if (isset($_POST['remember_me']) && $_POST['remember_me'] === '1') {
                // Generate secure remember me token
                $remember_token = bin2hex(random_bytes(32));
                $remember_token_hash = password_hash($remember_token, PASSWORD_DEFAULT);
                $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                
                // Store token in database
                $token_query = $conn->prepare("UPDATE students SET remember_token = ?, remember_token_expiry = FROM_UNIXTIME(?) WHERE student_id = ?");
                $token_query->bind_param("sis", $remember_token_hash, $expiry, $student_id);
                $token_query->execute();
                
                // Set secure cookie
                $cookie_set = setcookie(
                    'remember_me',
                    $student_id . ':' . $remember_token,
                    $expiry,
                    '/PEAS/',
                    '',
                    false, // secure - set to true on HTTPS
                    true   // HttpOnly - prevents JavaScript access for security
                );
            }

            // Redirect to the home page
            sendJsonResponse(['status' => 'success', 'redirect' => 'student/home_page_student.php']);
        } else {
            recordAttempt('login');
            sendJsonResponse(['status' => 'error', 'message' => 'Invalid password. Please try again.']);
        }
    } else {
        recordAttempt('login');
        sendJsonResponse(['status' => 'error', 'message' => 'Student ID not found. Please try again.']);
    }
}

// Close database connection
closeDBConnection($conn);
*/
?>