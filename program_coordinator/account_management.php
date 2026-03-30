<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security_policy.php';
require_once __DIR__ . '/../includes/student_profile_service.php';
require_once __DIR__ . '/../includes/program_shift_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
}

$studentId = trim((string)($_GET['student_id'] ?? ''));
if ($studentId === '') {
    echo "<script>alert('No student ID provided.'); window.history.back();</script>";
    exit();
}

$conn = getDBConnection();
$coordinatorUsername = (string)($_SESSION['username'] ?? '');
$coordinatorName = htmlspecialchars((string)($_SESSION['full_name'] ?? $coordinatorUsername));
$coordinatorProgramKeys = psResolveCoordinatorProgramKeys($conn, $coordinatorUsername);

$fetchStudentRow = static function(mysqli $conn, string $studentId): ?array {
    $stmt = $conn->prepare("
        SELECT
            student_number,
            last_name,
            first_name,
            middle_name,
            email,
            contact_number,
            house_number_street,
            picture,
            date_of_admission,
            status,
            program
        FROM student_info
        WHERE student_number = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
};

$studentRow = $fetchStudentRow($conn, $studentId);
if (!$studentRow) {
    closeDBConnection($conn);
    echo "<script>alert('Student not found.'); window.history.back();</script>";
    exit();
}

if (!psProgramMatchesActorKeys((string)($studentRow['program'] ?? ''), $coordinatorProgramKeys)) {
    closeDBConnection($conn);
    echo "<script>alert('You are not assigned to edit this student profile.'); window.location.href='list_of_students.php';</script>";
    exit();
}

$message = '';
$messageType = '';
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $requiredFields = ['last_name', 'first_name', 'email', 'contact_no', 'address', 'admission_date'];
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        $message = 'Please fill in all required fields.';
        $messageType = 'error';
    } else {
        $validationResult = spsValidateProfileUpdate($conn, $_POST);
        if (!$validationResult['valid']) {
            $message = (string)$validationResult['error'];
            $messageType = 'error';
        } else {
            $bridgeHandled = false;

            if ($useLaravelBridge) {
                $payloadFields = $_POST;
                $payloadFields['profile_context'] = 'program_coordinator';

                $bridgeData = null;
                if (isset($_FILES['picture']) && ($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $bridgeData = postLaravelMultipartBridge(
                        'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                        $payloadFields,
                        [
                            'picture' => [
                                'path' => (string)$_FILES['picture']['tmp_name'],
                                'name' => (string)$_FILES['picture']['name'],
                                'mime' => (string)($_FILES['picture']['type'] ?? 'application/octet-stream'),
                            ],
                        ]
                    );
                } else {
                    $bridgeData = postLaravelJsonBridge(
                        'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                        $payloadFields
                    );
                }

                if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
                    $bridgeHandled = true;
                    if (!empty($bridgeData['success'])) {
                        $message = (string)($bridgeData['message'] ?? 'Student profile updated successfully.');
                        $messageType = !empty($bridgeData['warning']) ? 'warning' : 'success';
                    } else {
                        $message = (string)($bridgeData['message'] ?? 'Error updating profile.');
                        $messageType = 'error';
                    }
                }
            }

            if (!$bridgeHandled) {
                $updateResult = spsUpdateStudentProfile($conn, $studentId, $validationResult['validated_fields']);
                if (!$updateResult['success']) {
                    $message = 'Error updating profile: ' . (string)$updateResult['error'];
                    $messageType = 'error';
                } else {
                    if (isset($_FILES['picture']) && ($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $pictureResult = spsUpdateProfilePicture($studentId, $_FILES['picture'], $conn);
                        if (!$pictureResult['success']) {
                            $message = 'Profile updated (with warning: ' . (string)$pictureResult['error'] . ')';
                            $messageType = 'warning';
                        } else {
                            $message = 'Student profile updated successfully!';
                            $messageType = 'success';
                        }
                    } else {
                        $message = 'Student profile updated successfully!';
                        $messageType = 'success';
                    }
                }
            }

            $studentRow = $fetchStudentRow($conn, $studentId) ?? $studentRow;
        }
    }
}

$lastName = htmlspecialchars((string)($studentRow['last_name'] ?? ''));
$firstName = htmlspecialchars((string)($studentRow['first_name'] ?? ''));
$middleName = htmlspecialchars((string)($studentRow['middle_name'] ?? ''));
$email = htmlspecialchars((string)($studentRow['email'] ?? ''));
$picture = htmlspecialchars((string)($studentRow['picture'] ?? 'pix/anonymous.jpg'));
$contactNo = htmlspecialchars((string)($studentRow['contact_number'] ?? ''));
$address = htmlspecialchars((string)($studentRow['house_number_street'] ?? ''));
$admissionDate = htmlspecialchars((string)($studentRow['date_of_admission'] ?? ''));
$program = htmlspecialchars((string)($studentRow['program'] ?? ''));

closeDBConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student Profile - Program Coordinator</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #eef5ee 0%, #dce9dd 100%);
            color: #1f2937;
        }
        .header {
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: #fff;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header a {
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }
        .container {
            max-width: 960px;
            margin: 28px auto;
            padding: 0 18px 36px;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 14px 32px rgba(26, 79, 22, 0.12);
            padding: 24px;
            margin-bottom: 18px;
        }
        .hero {
            display: flex;
            gap: 22px;
            align-items: center;
            flex-wrap: wrap;
        }
        .hero img {
            width: 120px;
            height: 120px;
            border-radius: 18px;
            object-fit: cover;
            border: 4px solid #d7e6d3;
            background: #f8fafc;
        }
        .hero h1 {
            margin: 0 0 6px;
            color: #1a4f16;
            font-size: 28px;
        }
        .hero p {
            margin: 4px 0;
            color: #475569;
        }
        .message {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        .message.success { background: #e8f7e7; color: #166534; }
        .message.error { background: #fde8e8; color: #991b1b; }
        .message.warning { background: #fff7df; color: #92400e; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #35553a;
            margin-bottom: 6px;
        }
        input, textarea {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #cdd9ce;
            border-radius: 10px;
            font-size: 14px;
            background: #fcfefd;
        }
        textarea {
            min-height: 110px;
            resize: vertical;
        }
        .full {
            grid-column: 1 / -1;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        .btn {
            display: inline-block;
            border: 0;
            border-radius: 10px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary {
            background: #206018;
            color: #fff;
        }
        .btn-secondary {
            background: #e5efe4;
            color: #1a4f16;
        }
    </style>
</head>
<body>
    <div class="header">
        <div><?= $coordinatorName ?> | Program Coordinator</div>
        <a href="list_of_students.php">Back to Student List</a>
    </div>

    <div class="container">
        <div class="card hero">
            <img src="../<?= $picture !== '' ? $picture : 'pix/anonymous.jpg' ?>" alt="Student Picture">
            <div>
                <h1><?= $firstName ?> <?= $middleName ?> <?= $lastName ?></h1>
                <p><strong>Student ID:</strong> <?= htmlspecialchars($studentId) ?></p>
                <p><strong>Program:</strong> <?= $program ?></p>
            </div>
        </div>

        <div class="card">
            <?php if ($message !== ''): ?>
                <div class="message <?= htmlspecialchars($messageType !== '' ? $messageType : 'success') ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="student_id" value="<?= htmlspecialchars($studentId) ?>">

                <div class="grid">
                    <div>
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="<?= $lastName ?>" required>
                    </div>
                    <div>
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="<?= $firstName ?>" required>
                    </div>
                    <div>
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" value="<?= $middleName ?>">
                    </div>
                    <div>
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= $email ?>" required>
                    </div>
                    <div>
                        <label for="contact_no">Contact Number</label>
                        <input type="text" id="contact_no" name="contact_no" value="<?= $contactNo ?>" required>
                    </div>
                    <div>
                        <label for="admission_date">Admission Date</label>
                        <input type="date" id="admission_date" name="admission_date" value="<?= $admissionDate ?>" required>
                    </div>
                    <div class="full">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" required><?= $address ?></textarea>
                    </div>
                    <div>
                        <label for="password">New Password (Optional)</label>
                        <input type="password" id="password" name="password" placeholder="Leave blank to keep current password">
                    </div>
                    <div>
                        <label for="picture">Profile Picture (Optional)</label>
                        <input type="file" id="picture" name="picture" accept=".jpg,.jpeg,.png,.gif">
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" name="update_profile" value="1" class="btn btn-primary">Save Profile</button>
                    <a href="list_of_students.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
