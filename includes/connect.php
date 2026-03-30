<?php
// Load environment variables from .env file
require_once __DIR__ . '/env_loader.php';

// Database connection parameters - loaded from environment variables
$host = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$database = getenv('DB_NAME') ?: 'osas_db';

// Create connection
$conn = new mysqli($host, $username, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/security_policy.php';

// Only handle registration if all required fields are present
if (
    $_SERVER["REQUEST_METHOD"] == "POST" &&
    isset($_POST['student_id'], $_POST['last_name'], $_POST['first_name'], $_POST['password'])
) {
    if (isRegistrationDisabled($conn)) {
        echo json_encode(['status' => 'error', 'message' => 'Registration is temporarily disabled by the administrator. Please try again later.']);
        exit;
    }

    $registrationWindow = isRegistrationWindowOpen($conn);
    if (!$registrationWindow['open']) {
        echo json_encode(['status' => 'error', 'message' => $registrationWindow['message']]);
        exit;
    }

    // Get form data
    $student_id = trim($_POST['student_id']);
    $last_name = trim($_POST['last_name']);
    $first_name = trim($_POST['first_name']);
    $middle_name = isset($_POST['middle_name']) ? trim($_POST['middle_name']) : '';
    $password = trim($_POST['password']);
    $contact_no = isset($_POST['contact_no']) ? trim($_POST['contact_no']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $admission_date = isset($_POST['admission_date']) ? trim($_POST['admission_date']) : '';

    if (isset($_POST['email']) && trim((string)$_POST['email']) !== '') {
        $emailPolicy = isAllowedEmailDomain($conn, trim((string)$_POST['email']));
        if (!$emailPolicy['allowed']) {
            echo json_encode(['status' => 'error', 'message' => $emailPolicy['message']]);
            exit;
        }
    }

    $minimumPasswordLength = policySettingInt($conn, 'min_password_length', 8, 6, 64);
    if (strlen($password) < $minimumPasswordLength) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least ' . $minimumPasswordLength . ' characters long.']);
        exit;
    }

    $contactValidation = validateContactNumberInput($contact_no);
    if (!$contactValidation['valid']) {
        echo json_encode(['status' => 'error', 'message' => $contactValidation['message']]);
        exit;
    }
    $contact_no = $contactValidation['normalized'];

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Handle picture upload
    $target_dir = "uploads/"; // Directory to save uploaded files
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true); // Create directory if it does not exist
    }

    $target_file = $target_dir . basename(isset($_FILES["picture"]["name"]) ? $_FILES["picture"]["name"] : '');
    $upload_ok = 1;
    $image_file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Check if image file is valid
    if (isset($_FILES["picture"]) && $_FILES["picture"]["size"] > 0) {
        $check = getimagesize($_FILES["picture"]["tmp_name"]);
        if ($check === false) {
            echo json_encode(['status' => 'error', 'message' => 'File is not an image.']);
            $upload_ok = 0;
        }

        // Check file size (limit to 5MB)
        if ($_FILES["picture"]["size"] > 5000000) {
            echo json_encode(['status' => 'error', 'message' => 'Sorry, your file is too large.']);
            $upload_ok = 0;
        }

        // Allow only certain file formats
        if (!in_array($image_file_type, ["jpg", "png", "jpeg", "gif"])) {
            echo json_encode(['status' => 'error', 'message' => 'Sorry, only JPG, JPEG, PNG & GIF files are allowed.']);
            $upload_ok = 0;
        }

        // Attempt to upload file if no errors
        if ($upload_ok && !move_uploaded_file($_FILES["picture"]["tmp_name"], $target_file)) {
            echo json_encode(['status' => 'error', 'message' => 'Sorry, there was an error uploading your file.']);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No file uploaded.']);
        exit;
    }

    // Insert data into the database
    $stmt = $conn->prepare("INSERT INTO student_info (student_number, last_name, first_name, middle_name, password, contact_number, house_number_street, date_of_admission) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $student_id, $last_name, $first_name, $middle_name, $hashed_password, $contact_no, $address, $admission_date);


    if ($stmt->execute()) {
        recordPasswordHistory($conn, $student_id, $hashed_password);
        echo json_encode(['status' => 'success', 'message' => 'Student data saved successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error saving data: ' . $stmt->error]);
    }

    $stmt->close();
}

// Only close the connection after registration, not for all includes
if (
    $_SERVER["REQUEST_METHOD"] == "POST" &&
    isset($_POST['student_id'], $_POST['last_name'], $_POST['first_name'], $_POST['password'])
) {
    $conn->close();
}
