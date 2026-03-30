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
    $hasPictureUpload = isset($_FILES['picture']) && (($_FILES['picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
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

            // Handle picture uploads locally so the saved file lives in the same
            // service that renders the image path for the coordinator page.
            if ($useLaravelBridge && !$hasPictureUpload) {
                $payloadFields = $_POST;
                $payloadFields['profile_context'] = 'program_coordinator';

                $bridgeData = postLaravelJsonBridge(
                    'http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update',
                    $payloadFields
                );

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
                    if ($hasPictureUpload) {
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
$studentDisplayName = trim($firstName . ' ' . $middleName . ' ' . $lastName);

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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #1f2937;
            overflow-x: hidden;
            padding-top: 45px;
        }

        .header {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            padding: 5px 15px;
            text-align: left;
            font-size: 18px;
            font-weight: 800;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
            height: 45px;
        }

        .header img {
            height: 32px;
            width: auto;
            margin-right: 12px;
            vertical-align: middle;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
            cursor: pointer;
        }

        .admin-info {
            font-size: 16px;
            font-weight: 600;
            color: white;
            font-family: 'Segoe UI', Arial, sans-serif;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.15);
            padding: 5px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .menu-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border-radius: 6px;
            font-size: 18px;
            cursor: pointer;
            margin-right: 10px;
            transition: all 0.2s ease;
        }

        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .sidebar {
            width: 250px;
            height: calc(100vh - 45px);
            background: linear-gradient(135deg, #1a4f16 0%, #2d8f22 100%);
            color: white;
            position: fixed;
            left: 0;
            top: 45px;
            padding: 20px 0;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 999;
        }

        .sidebar.collapsed {
            transform: translateX(-250px);
        }

        .sidebar-header {
            padding: 15px 20px;
            text-align: center;
            color: white;
            font-size: 20px;
            font-weight: 700;
            border-bottom: 2px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 5px;
        }

        .sidebar-header h3 {
            margin: 0;
        }

        .sidebar-menu {
            list-style: none;
            padding: 6px 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #ffffff;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 15px;
            line-height: 1.2;
            border-left: 4px solid transparent;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 25px;
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: #4CAF50;
        }

        .sidebar-menu img {
            width: 20px;
            height: 20px;
            margin-right: 0;
            filter: brightness(0) invert(1);
            flex: 0 0 20px;
        }

        .menu-group {
            margin: 8px 0;
        }

        .menu-group-title {
            padding: 6px 20px 2px 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            line-height: 1.2;
        }

        .main-content {
            margin-left: 250px;
            min-height: calc(100vh - 45px);
            background-color: #f5f5f5;
            width: calc(100vw - 250px);
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        .main-content.expanded {
            margin-left: 0;
            width: 100vw;
        }

        .content {
            padding: 18px 30px 30px;
        }

        .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 20px 42px;
        }

        .title {
            font-size: 30px;
            font-weight: 800;
            color: #1a4f16;
            margin-bottom: 10px;
            letter-spacing: -0.02em;
        }

        .content-wrapper {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 18px 42px rgba(25, 70, 30, 0.1);
            padding: 28px;
            border: 1px solid rgba(32, 96, 24, 0.08);
        }

        .subtitle {
            color: #64748b;
            font-size: 15px;
            margin-bottom: 18px;
        }

        .profile-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }

        .summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: #f6faf6;
            border: 1px solid #d9e6d7;
            color: #35553a;
            font-size: 13px;
            line-height: 1.4;
        }

        .summary-pill strong {
            color: #1a4f16;
        }

        .profile {
            display: grid;
            grid-template-columns: 320px minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }

        .photo {
            background: linear-gradient(180deg, #f8fcf8 0%, #f2f8f2 100%);
            border: 1px solid #dfeadf;
            border-radius: 18px;
            padding: 24px;
            text-align: center;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
        }

        .photo-container {
            width: 180px;
            height: 180px;
            margin: 0 auto 18px;
            border-radius: 28px;
            overflow: hidden;
            border: 6px solid #dce9da;
            background: #ffffff;
            box-shadow: 0 16px 28px rgba(20, 66, 24, 0.12);
        }

        .photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .photo-caption {
            display: grid;
            gap: 6px;
            margin-bottom: 18px;
        }

        .photo-caption strong {
            font-size: 20px;
            color: #1a4f16;
            line-height: 1.3;
        }

        .photo-caption span {
            color: #64748b;
            font-size: 13px;
            line-height: 1.55;
        }

        .file-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .file-label:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(32, 96, 24, 0.18);
        }

        .file-input {
            display: none;
        }

        .file-note {
            margin-top: 10px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .details {
            background: #ffffff;
            border: 1px solid #e6edf0;
            border-radius: 18px;
            padding: 24px;
        }

        .details-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid #edf2f4;
        }

        .details-heading h3 {
            margin: 0 0 6px;
            font-size: 24px;
            color: #1a4f16;
        }

        .details-heading p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            max-width: 620px;
        }

        .details-badge {
            padding: 10px 14px;
            border-radius: 999px;
            background: #edf7ed;
            border: 1px solid #d9e8d7;
            color: #206018;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .message {
            border-radius: 12px;
            padding: 13px 15px;
            margin-bottom: 18px;
            font-weight: 600;
        }
        .message.success { background: #e8f7e7; color: #166534; }
        .message.error { background: #fde8e8; color: #991b1b; }
        .message.warning { background: #fff7df; color: #92400e; }

        .fields-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }

        .field {
            position: relative;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #35553a;
            margin-bottom: 7px;
        }

        .field input,
        .field textarea {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cdd9ce;
            border-radius: 12px;
            font-size: 14px;
            background: #fcfefd;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .field input:focus,
        .field textarea:focus {
            outline: none;
            border-color: #2d8f22;
            box-shadow: 0 0 0 4px rgba(45, 143, 34, 0.1);
        }

        .field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .field-note {
            color: #64748b;
            font-size: 12px;
            line-height: 1.55;
            margin-top: 7px;
        }

        .buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
        }

        .btn {
            display: inline-block;
            border: 0;
            border-radius: 12px;
            padding: 13px 20px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #206018 0%, #2d8f22 100%);
            color: #fff;
            box-shadow: 0 10px 18px rgba(32, 96, 24, 0.18);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #e5efe4;
            color: #1a4f16;
        }

        @media (max-width: 900px) {
            .profile {
                grid-template-columns: 1fr;
            }

            .fields-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .details-heading {
                flex-direction: column;
            }
        }

        @media (max-width: 640px) {
            .header {
                padding: 5px 8px;
                font-size: 12px;
            }

            .header img {
                height: 22px;
                margin-right: 6px;
            }

            .main-content {
                margin-left: 0;
                width: 100vw;
            }

            .sidebar {
                transform: translateX(-250px);
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }

            .container {
                padding: 20px 14px 34px;
            }

            .content-wrapper,
            .details,
            .photo {
                padding: 18px;
            }

            .photo-container {
                width: 150px;
                height: 150px;
            }

            .buttons .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info"><?= $coordinatorName ?> | Program Coordinator</div>
    </div>

    <div class="sidebar collapsed" id="sidebar">
        <div class="sidebar-header">
            <h3>Program Coordinator Panel</h3>
        </div>
        <ul class="sidebar-menu">
            <div class="menu-group">
                <div class="menu-group-title">Dashboard</div>
                <li><a href="index.php"><img src="../pix/home1.png" alt="Dashboard"> Dashboard</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Modules</div>
                <li><a href="curriculum_management.php"><img src="../pix/curr.png" alt="Curriculum"> Curriculum Management</a></li>
                <li><a href="adviser_management.php"><img src="../pix/account.png" alt="Advisers"> Adviser Management</a></li>
                <li><a href="list_of_students.php" class="active"><img src="../pix/checklist.png" alt="Students"> List of Students</a></li>
                <li><a href="program_shift_requests.php"><img src="../pix/update.png" alt="Program Shift"> Program Shift Requests</a></li>
                <li><a href="profile.php"><img src="../pix/account.png" alt="Profile"> Update Profile</a></li>
            </div>

            <div class="menu-group">
                <div class="menu-group-title">Account</div>
                <li><a href="logout.php"><img src="../pix/singout.png" alt="Sign Out"> Sign Out</a></li>
            </div>
        </ul>
    </div>

    <div class="main-content expanded" id="mainContent">
        <div class="content">
            <div class="container">
                <div class="title">Student Profile</div>
                <div class="content-wrapper">
                    <div class="subtitle">Review and update this student's account details using the same profile-centered layout shown on the student side.</div>
                    <div class="profile-summary">
                        <div class="summary-pill"><strong>ID</strong> <?= htmlspecialchars($studentId) ?></div>
                        <div class="summary-pill"><strong>Email</strong> <?= $email !== '' ? $email : 'Not set' ?></div>
                        <div class="summary-pill"><strong>Admission</strong> <?= $admissionDate !== '' ? $admissionDate : 'Not set' ?></div>
                    </div>

                    <?php if ($message !== ''): ?>
                        <div class="message <?= htmlspecialchars($messageType !== '' ? $messageType : 'success') ?>"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="student_id" value="<?= htmlspecialchars($studentId) ?>">
                        <div class="profile">
                            <div class="photo">
                                <div class="photo-container">
                                    <img id="profile-pic" src="../<?= $picture !== '' ? $picture : 'pix/anonymous.jpg' ?>" alt="Profile Photo">
                                </div>
                                <div class="photo-caption">
                                    <strong><?= trim($studentDisplayName) !== '' ? $studentDisplayName : htmlspecialchars($studentId) ?></strong>
                                    <span>Update the student's records using the same profile-first experience they would expect on their own account.</span>
                                </div>
                                <label for="picture" class="file-label">Change Picture</label>
                                <input class="file-input" type="file" id="picture" name="picture" accept=".jpg,.jpeg,.png,.gif">
                                <div class="file-note">Accepted formats: JPG, JPEG, PNG, and GIF.</div>
                            </div>

                            <div class="details">
                                <div class="details-heading">
                                    <div>
                                        <h3>Account Details</h3>
                                        <p>Edit the student’s personal and contact information below. Changes are saved directly to the student profile record.</p>
                                    </div>
                                    <div class="details-badge">Program Coordinator Access</div>
                                </div>

                                <div class="fields-grid">
                                    <div class="field">
                                        <label for="last_name">Last Name</label>
                                        <input type="text" id="last_name" name="last_name" value="<?= $lastName ?>" required>
                                        <div class="field-note">Use the student’s official surname from school records.</div>
                                    </div>
                                    <div class="field">
                                        <label for="first_name">First Name</label>
                                        <input type="text" id="first_name" name="first_name" value="<?= $firstName ?>" required>
                                        <div class="field-note">This name appears across student-facing academic pages.</div>
                                    </div>
                                    <div class="field">
                                        <label for="middle_name">Middle Name</label>
                                        <input type="text" id="middle_name" name="middle_name" value="<?= $middleName ?>">
                                        <div class="field-note">Leave unchanged if no middle name is recorded.</div>
                                    </div>
                                    <div class="field">
                                        <label for="email">Email</label>
                                        <input type="email" id="email" name="email" value="<?= $email ?>" required>
                                        <div class="field-note">Use an active email for notices and account recovery.</div>
                                    </div>
                                    <div class="field">
                                        <label for="contact_no">Contact Number</label>
                                        <input type="text" id="contact_no" name="contact_no" value="<?= $contactNo ?>" required>
                                        <div class="field-note">This should be reachable for school-related contact.</div>
                                    </div>
                                    <div class="field">
                                        <label for="admission_date">Admission Date</label>
                                        <input type="date" id="admission_date" name="admission_date" value="<?= $admissionDate ?>" required>
                                        <div class="field-note">Keep this aligned with the student’s official admission record.</div>
                                    </div>
                                    <div class="field">
                                        <label for="program">Program</label>
                                        <input type="text" id="program" value="<?= $program ?>" disabled>
                                        <div class="field-note">Shown for coordinator reference and assignment context.</div>
                                    </div>
                                    <div class="field">
                                        <label for="password">New Password (Optional)</label>
                                        <input type="password" id="password" name="password" placeholder="Leave blank to keep current password">
                                        <div class="field-note">Only set this when helping the student reset credentials.</div>
                                    </div>
                                    <div class="field full">
                                        <label for="address">Address</label>
                                        <textarea id="address" name="address" required><?= $address ?></textarea>
                                        <div class="field-note">Update the current residence used for student records and communication.</div>
                                    </div>
                                </div>

                                <div class="buttons">
                                    <button type="submit" name="update_profile" value="1" class="btn btn-primary">Save Profile</button>
                                    <a href="list_of_students.php" class="btn btn-secondary">Back to Student List</a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        const pictureInput = document.getElementById('picture');
        if (pictureInput) {
            pictureInput.addEventListener('change', function (event) {
                const file = event.target.files && event.target.files[0];
                if (!file) {
                    return;
                }

                const reader = new FileReader();
                reader.onload = function (e) {
                    const preview = document.getElementById('profile-pic');
                    if (preview && e.target) {
                        preview.src = e.target.result;
                    }
                    const topPreview = document.getElementById('profile-pic-top');
                    if (topPreview && e.target) {
                        topPreview.src = e.target.result;
                    }
                };
                reader.readAsDataURL(file);
            });
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const logo = document.querySelector('.header img');
            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(event.target) && (!logo || !logo.contains(event.target))) {
                sidebar.classList.add('collapsed');
                document.getElementById('mainContent').classList.add('expanded');
            }
        });

        window.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
        });

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            } else {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
        });
    </script>
</body>
</html>
