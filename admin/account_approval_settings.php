<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || empty($_SESSION['admin_id'])) {
    echo '<div style="color:red; text-align:center; font-size:1.2em; margin-top:40px;">Access denied. Please log in as admin.</div>';
    exit();
}

// Database connection & services
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/account_approval_settings_service.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';
require_once __DIR__ . '/../includes/student_masterlist_service.php';
require_once __DIR__ . '/../includes/student_registration_service.php';

$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';
$bridgeUpdateEndpoint = '/api/account-approval-settings/update';
$bridgeOverviewEndpoint = '/api/account-approval-settings/overview';
$error_message = '';
$conn = null;

try {
    $legacyConnection = createPdoFallbackConnection();
    if (method_exists($legacyConnection, 'getPdo')) {
        $conn = $legacyConnection->getPdo();
    } elseif ($legacyConnection instanceof PDO) {
        $conn = $legacyConnection;
    }
} catch (Throwable $e) {
    error_log('Admin settings DB bootstrap failed: ' . $e->getMessage());
    if (!$useLaravelBridge) {
        $error_message = 'Unable to load admin settings right now. Please try again later.';
    }
}

$policySettings = [
    'session_timeout_seconds' => [
        'label' => 'Session Timeout (seconds)',
        'default' => 3600,
        'min' => 60,
        'max' => 86400,
        'help' => 'Automatically expires inactive logged-in sessions after this duration.'
    ],
    'min_password_length' => [
        'label' => 'Minimum Password Length',
        'default' => 8,
        'min' => 6,
        'max' => 64,
        'help' => 'Minimum characters required when setting new passwords.'
    ],
    'password_reset_expiry_seconds' => [
        'label' => 'Password Reset Code Expiry (seconds)',
        'default' => 600,
        'min' => 60,
        'max' => 7200,
        'help' => 'How long reset verification codes remain valid.'
    ],
    'rate_limit_login_max_attempts' => [
        'label' => 'Login Max Attempts',
        'default' => 5,
        'min' => 1,
        'max' => 100,
        'help' => 'Maximum login attempts before temporary lockout.'
    ],
    'rate_limit_login_window_seconds' => [
        'label' => 'Login Rate Window (seconds)',
        'default' => 300,
        'min' => 30,
        'max' => 86400,
        'help' => 'Time window used to count login attempts.'
    ],
    'rate_limit_forgot_password_max_attempts' => [
        'label' => 'Forgot Password Max Attempts',
        'default' => 3,
        'min' => 1,
        'max' => 100,
        'help' => 'Maximum forgot-password requests before temporary lockout.'
    ],
    'rate_limit_forgot_password_window_seconds' => [
        'label' => 'Forgot Password Rate Window (seconds)',
        'default' => 600,
        'min' => 30,
        'max' => 86400,
        'help' => 'Time window used to count forgot-password requests.'
    ],
];

$advancedSettings = [
    'enable_admin_2fa' => [
        'label' => 'Enable 2FA for Admin Login',
        'type' => 'boolean',
        'default' => 0,
        'help' => 'Require a 6-digit authenticator-app code after the admin password step.'
    ],
    'enforce_student_cvsu_email_verification' => [
        'label' => 'Require Student CvSU Email OTP Verification',
        'type' => 'boolean',
        'default' => 1,
        'help' => 'When enabled, students using a @cvsu.edu.ph email must verify it with an OTP before entering the student workspace. Disable this to let legacy students continue without the OTP gate.'
    ],
    'enforce_shift_strand_alignment' => [
        'label' => 'Require Strand Alignment For Program Shift',
        'type' => 'boolean',
        'default' => 0,
        'help' => 'When enabled, students can only request a program shift if their saved strand aligns with the selected destination program.'
    ],
    'password_history_count' => [
        'label' => 'Password History Block Count',
        'type' => 'number',
        'default' => 5,
        'min' => 0,
        'max' => 24,
        'help' => 'Disallow reuse of the latest N admin passwords. Set 0 to disable.'
    ],
    'account_lockout_duration_seconds' => [
        'label' => 'Account Lockout Duration (seconds)',
        'type' => 'number',
        'default' => 900,
        'min' => 60,
        'max' => 86400,
        'help' => 'Duration before locked accounts can attempt login again.'
    ],
    'allowed_email_domains' => [
        'label' => 'Allowed Student Email Domains',
        'type' => 'text',
        'default' => 'cvsu.edu.ph',
        'max_length' => 500,
        'placeholder' => 'example.edu, students.example.edu',
        'help' => 'Comma-separated domains. Leave blank to default to cvsu.edu.ph only.'
    ],
    'rejection_cooldown_days' => [
        'label' => 'Re-application Cooldown After Rejection (days)',
        'type' => 'number',
        'default' => 0,
        'min' => 0,
        'max' => 365,
        'help' => 'Minimum waiting period before a rejected account can re-apply.'
    ],
    'pending_alert_threshold' => [
        'label' => 'Pending Alert Threshold',
        'type' => 'number',
        'default' => 50,
        'min' => 1,
        'max' => 5000,
        'help' => 'Alert threshold when pending accounts exceed this count.'
    ],
    'freeze_approvals' => [
        'label' => 'Emergency Freeze All Approval Actions',
        'type' => 'boolean',
        'default' => 0,
        'help' => 'Temporarily disables approve/reject/revert actions and bulk actions.'
    ],
    'disable_new_registrations' => [
        'label' => 'Temporarily Disable New Registrations',
        'type' => 'boolean',
        'default' => 0,
        'help' => 'Can be checked by registration flow to pause new signups.'
    ],
    'default_records_per_page' => [
        'label' => 'Default Records Per Page',
        'type' => 'number',
        'default' => 10,
        'min' => 5,
        'max' => 100,
        'help' => 'Default number of rows shown in account table pagination.'
    ],
    'registration_open_start' => [
        'label' => 'Registration Window Start',
        'type' => 'datetime',
        'default' => '',
        'help' => 'Optional start date/time for student registration window.'
    ],
    'registration_open_end' => [
        'label' => 'Registration Window End',
        'type' => 'datetime',
        'default' => '',
        'help' => 'Optional end date/time for student registration window.'
    ],
];

$adminAccount = [
    'username' => (string)($_SESSION['admin_username'] ?? $_SESSION['admin_id'] ?? ''),
    'full_name' => (string)($_SESSION['admin_full_name'] ?? ''),
];
$adminCredentialMinLength = 8;
$selectedMasterlistProgram = '';

// Business logic functions are now in account_approval_settings_service.php

function aasBuildPaginationUrl(array $overrides = []): string
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $query = http_build_query($params);
    return 'account_approval_settings.php' . ($query !== '' ? ('?' . $query) : '');
}

function aasRenderMiniPagination(string $pageParam, int $currentPage, int $totalPages): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $currentPage = max(1, min($currentPage, $totalPages));
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);

    $html = '<div class="pagination-container">';

    if ($currentPage > 1) {
        $html .= '<a class="pagination-btn" href="' . htmlspecialchars(aasBuildPaginationUrl([$pageParam => 1])) . '">First</a>';
        $html .= '<a class="pagination-btn" href="' . htmlspecialchars(aasBuildPaginationUrl([$pageParam => $currentPage - 1])) . '">Previous</a>';
    } else {
        $html .= '<span class="pagination-btn disabled">First</span>';
        $html .= '<span class="pagination-btn disabled">Previous</span>';
    }

    for ($page = $startPage; $page <= $endPage; $page++) {
        $class = 'pagination-btn' . ($page === $currentPage ? ' active' : '');
        $html .= '<a class="' . $class . '" href="' . htmlspecialchars(aasBuildPaginationUrl([$pageParam => $page])) . '">' . $page . '</a>';
    }

    if ($currentPage < $totalPages) {
        $html .= '<a class="pagination-btn" href="' . htmlspecialchars(aasBuildPaginationUrl([$pageParam => $currentPage + 1])) . '">Next</a>';
        $html .= '<a class="pagination-btn" href="' . htmlspecialchars(aasBuildPaginationUrl([$pageParam => $totalPages])) . '">Last</a>';
    } else {
        $html .= '<span class="pagination-btn disabled">Next</span>';
        $html .= '<span class="pagination-btn disabled">Last</span>';
    }

    $html .= '<span class="pagination-info">Page ' . $currentPage . ' of ' . $totalPages . '</span>';
    $html .= '</div>';

    return $html;
}

function aasDescribeUserAgent(string $userAgent): string
{
    $ua = strtolower(trim($userAgent));
    if ($ua === '' || $ua === 'unknown') {
        return 'Unknown device';
    }

    $device = 'Desktop';
    if (preg_match('/mobile|iphone|windows phone|android.*mobile/', $ua)) {
        $device = 'Mobile';
    } elseif (preg_match('/ipad|tablet|android(?!.*mobile)/', $ua)) {
        $device = 'Tablet';
    }

    $browser = 'Unknown Browser';
    if (strpos($ua, 'edg/') !== false) {
        $browser = 'Edge';
    } elseif (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) {
        $browser = 'Opera';
    } elseif (strpos($ua, 'chrome/') !== false) {
        $browser = 'Chrome';
    } elseif (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false) {
        $browser = 'Safari';
    } elseif (strpos($ua, 'firefox/') !== false) {
        $browser = 'Firefox';
    }

    $os = 'Unknown OS';
    if (strpos($ua, 'windows') !== false) {
        $os = 'Windows';
    } elseif (strpos($ua, 'mac os x') !== false || strpos($ua, 'macintosh') !== false) {
        $os = 'macOS';
    } elseif (strpos($ua, 'android') !== false) {
        $os = 'Android';
    } elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false || strpos($ua, 'ios') !== false) {
        $os = 'iOS';
    } elseif (strpos($ua, 'linux') !== false) {
        $os = 'Linux';
    }

    return $device . ' / ' . $browser . ' / ' . $os;
}

function aasBridgeToBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }

    if (is_string($value)) {
        $token = strtolower(trim($value));
        if ($token === '1' || $token === 'true' || $token === 'yes' || $token === 'on') {
            return true;
        }
        if ($token === '0' || $token === 'false' || $token === 'no' || $token === 'off' || $token === '') {
            return false;
        }
    }

    return !empty($value);
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['upload_student_masterlist'])) {
        if (!$conn instanceof PDO) {
            $error_message = 'Unable to upload the masterlist right now.';
        } else {
            $selectedMasterlistProgram = trim((string) ($_POST['masterlist_program'] ?? ''));
            $csvFile = $_FILES['masterlist_csv'] ?? null;

            if ($selectedMasterlistProgram === '') {
                $error_message = 'Please select the target program for the masterlist upload.';
            } elseif (!is_array($csvFile) || (int) ($csvFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $error_message = 'Please choose a CSV file before uploading the official masterlist.';
            } elseif ((int) ($csvFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                $error_message = 'The uploaded masterlist file could not be processed. Please try again.';
            } else {
                $parsedMasterlist = smlParseCsvUpload((string) ($csvFile['tmp_name'] ?? ''));
                if (!empty($parsedMasterlist['success'])) {
                    $saveMasterlist = smlReplaceProgramMasterlist(
                        $conn,
                        $selectedMasterlistProgram,
                        (array) ($parsedMasterlist['rows'] ?? []),
                        (string) ($csvFile['name'] ?? 'masterlist.csv'),
                        (string) ($_SESSION['admin_id'] ?? 'admin')
                    );

                    if (!empty($saveMasterlist['success'])) {
                        header('Location: account_approval_settings.php?message=' . urlencode((string) ($saveMasterlist['message'] ?? 'Official student masterlist uploaded successfully.')));
                        exit();
                    }

                    $error_message = (string) ($saveMasterlist['message'] ?? 'Failed to save the official masterlist.');
                } else {
                    $error_message = (string) ($parsedMasterlist['message'] ?? 'The uploaded CSV file is invalid.');
                }
            }
        }
    }

    if (isset($_POST['update_policy_settings'])) {
        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                $bridgeUpdateEndpoint,
                array_merge($_POST, [
                    'bridge_authorized' => true,
                    'admin_id' => (string)($_SESSION['admin_id'] ?? ''),
                    'action' => 'update_policy_settings',
                ])
            );

            if (is_array($bridgeData) && !empty($bridgeData['success'])) {
                header("Location: account_approval_settings.php?message=" . urlencode((string)($bridgeData['message'] ?? 'Security and rate-limit settings updated.')));
                exit();
            }

            $error_message = (string)($bridgeData['message'] ?? 'Error updating policy settings.');
        } else {
            if (!$conn instanceof PDO) {
                $error_message = 'Unable to update policy settings right now.';
            } else {
            try {
                aasUpdatePolicySettings($conn, $_SESSION['admin_id'], $policySettings, $_POST);
                header("Location: account_approval_settings.php?message=" . urlencode("Security and rate-limit settings updated."));
                exit();
            } catch (PDOException $e) {
                error_log("Database error updating policy settings: " . $e->getMessage());
                $error_message = "Error updating policy settings: " . $e->getMessage();
            }
            }
        }
    }

    if (isset($_POST['update_setting'])) {
        $rawAutoApprove = $_POST['auto_approve'] ?? '0';
        if (is_array($rawAutoApprove)) {
            $lastAutoApprove = end($rawAutoApprove);
            $rawAutoApprove = $lastAutoApprove === false ? '0' : $lastAutoApprove;
        }
        $autoApproveToken = strtolower(trim((string)$rawAutoApprove));
        $auto_approve = in_array($autoApproveToken, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;

        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                $bridgeUpdateEndpoint,
                [
                    'bridge_authorized' => true,
                    'admin_id' => (string)($_SESSION['admin_id'] ?? ''),
                    'auto_approve' => $auto_approve,
                    'action' => 'update_setting',
                    'update_setting' => 1,
                ]
            );

            if (is_array($bridgeData) && !empty($bridgeData['success'])) {
                if ($conn instanceof PDO) {
                    try {
                        $localAutoApproveEnabled = aasLoadAutoApproveSetting($conn);
                        if ((bool)$localAutoApproveEnabled !== (bool)$auto_approve) {
                            aasUpdateAutoApproveSetting($conn, (string)$_SESSION['admin_id'], (bool)$auto_approve);
                        }
                    } catch (Throwable $e) {
                        error_log('Bridge/local auto-approve sync failed: ' . $e->getMessage());
                    }
                }

                header("Location: account_approval_settings.php?message=" . urlencode((string)($bridgeData['message'] ?? 'Approval setting updated.')));
                exit();
            }

            if ($conn instanceof PDO) {
                try {
                    if (aasIsFreezingEnabled($conn)) {
                        header("Location: account_approval_settings.php?message=" . urlencode("Approval actions are currently frozen. Disable freeze first to change auto-approval mode."));
                        exit();
                    }

                    $approvedCount = aasUpdateAutoApproveSetting($conn, $_SESSION['admin_id'], (bool)$auto_approve);
                    if ($auto_approve) {
                        $message = "Auto-approval enabled. " . $approvedCount . " pending accounts have been automatically approved.";
                    } else {
                        $message = "Auto-approval disabled. New accounts will require manual approval.";
                    }

                    header("Location: account_approval_settings.php?message=" . urlencode($message));
                    exit();
                } catch (PDOException $e) {
                    error_log("Bridge fallback error updating setting: " . $e->getMessage());
                }
            }

            $error_message = (string)($bridgeData['message'] ?? 'Error updating setting.');
        } else {
            if (!$conn instanceof PDO) {
                $error_message = 'Unable to update approval settings right now.';
            } else {
            if (aasIsFreezingEnabled($conn)) {
                header("Location: account_approval_settings.php?message=" . urlencode("Approval actions are currently frozen. Disable freeze first to change auto-approval mode."));
                exit();
            }

            try {
                $approvedCount = aasUpdateAutoApproveSetting($conn, $_SESSION['admin_id'], (bool)$auto_approve);
                
                if ($auto_approve) {
                    $message = "Auto-approval enabled. " . $approvedCount . " pending accounts have been automatically approved.";
                } else {
                    $message = "Auto-approval disabled. New accounts will require manual approval.";
                }
                
                header("Location: account_approval_settings.php?message=" . urlencode($message));
                exit();
                
            } catch (PDOException $e) {
                error_log("Database error updating setting: " . $e->getMessage());
                $error_message = "Error updating setting: " . $e->getMessage();
            }
            }
        }
    }

    if (isset($_POST['update_advanced_settings'])) {
        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                $bridgeUpdateEndpoint,
                array_merge($_POST, [
                    'bridge_authorized' => true,
                    'admin_id' => (string)($_SESSION['admin_id'] ?? ''),
                    'action' => 'update_advanced_settings',
                ])
            );

            if (is_array($bridgeData) && !empty($bridgeData['success'])) {
                header("Location: account_approval_settings.php?message=" . urlencode((string)($bridgeData['message'] ?? 'Advanced admin controls updated.')));
                exit();
            }

            if ($conn instanceof PDO) {
                try {
                    aasUpdateAdvancedSettings($conn, $_SESSION['admin_id'], $advancedSettings, $_POST);
                    header("Location: account_approval_settings.php?message=" . urlencode("Advanced admin controls updated."));
                    exit();
                } catch (PDOException $e) {
                    error_log("Bridge fallback error updating advanced settings: " . $e->getMessage());
                }
            }

            $error_message = (string)($bridgeData['message'] ?? 'Error updating advanced settings.');
        } else {
            if (!$conn instanceof PDO) {
                $error_message = 'Unable to update advanced settings right now.';
            } else {
                try {
                    aasUpdateAdvancedSettings($conn, $_SESSION['admin_id'], $advancedSettings, $_POST);
                    header("Location: account_approval_settings.php?message=" . urlencode("Advanced admin controls updated."));
                    exit();
                } catch (PDOException $e) {
                    error_log("Database error updating advanced settings: " . $e->getMessage());
                    $error_message = "Error updating advanced settings: " . $e->getMessage();
                }
            }
        }
    }

    if (isset($_POST['update_admin_account'])) {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newUsername = trim((string)($_POST['new_username'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                $bridgeUpdateEndpoint,
                [
                    'bridge_authorized' => true,
                    'admin_id' => (string)($_SESSION['admin_id'] ?? ''),
                    'action' => 'update_admin_account',
                    'update_admin_account' => 1,
                    'current_password' => $currentPassword,
                    'new_username' => $newUsername,
                    'new_password' => $newPassword,
                    'confirm_password' => $confirmPassword,
                ]
            );

            if (is_array($bridgeData) && !empty($bridgeData['success'])) {
                $updatedUsername = trim((string)($bridgeData['admin_account']['username'] ?? $newUsername ?? $_SESSION['admin_id'] ?? ''));
                if ($updatedUsername !== '') {
                    $_SESSION['admin_id'] = $updatedUsername;
                    $_SESSION['admin_username'] = $updatedUsername;
                }
                if (isset($bridgeData['admin_account']['full_name'])) {
                    $_SESSION['admin_full_name'] = (string)$bridgeData['admin_account']['full_name'];
                }

                header("Location: account_approval_settings.php?message=" . urlencode((string)($bridgeData['message'] ?? 'Admin account credentials updated successfully.')));
                exit();
            }

            $error_message = (string)($bridgeData['message'] ?? 'Unable to update admin account credentials right now.');
        } else {
            if (!$conn instanceof PDO) {
                $error_message = 'Unable to update admin account credentials right now.';
            } else {
                try {
                    $minPasswordLength = aasResolveMinPasswordLength($conn);

                    if (trim($currentPassword) === '') {
                        throw new RuntimeException('Current password is required to update admin credentials.');
                    }

                    if ($newUsername === '' && trim($newPassword) === '') {
                        throw new RuntimeException('Provide a new username or a new password before saving changes.');
                    }

                    if ($newPassword !== '' && strlen($newPassword) < $minPasswordLength) {
                        throw new RuntimeException('New password must be at least ' . $minPasswordLength . ' characters long.');
                    }

                    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
                        throw new RuntimeException('New password and confirmation password do not match.');
                    }

                    $updatedAdminAccount = aasUpdateAdminAccountCredentials(
                        $conn,
                        (string)$_SESSION['admin_id'],
                        $currentPassword,
                        $newUsername,
                        $newPassword
                    );

                    if (!empty($updatedAdminAccount['username'])) {
                        $_SESSION['admin_id'] = (string)$updatedAdminAccount['username'];
                        $_SESSION['admin_username'] = (string)$updatedAdminAccount['username'];
                    }
                    if (isset($updatedAdminAccount['full_name'])) {
                        $_SESSION['admin_full_name'] = (string)$updatedAdminAccount['full_name'];
                    }

                    header("Location: account_approval_settings.php?message=" . urlencode('Admin account credentials updated successfully.'));
                    exit();
                } catch (Throwable $e) {
                    error_log('Failed to update admin account credentials: ' . $e->getMessage());
                    $error_message = $e->getMessage();
                }
            }
        }
    }
    
    // Handle individual account actions
    if (isset($_POST['account_action'])) {
        $student_id = $_POST['student_id'];
        $action = $_POST['action'];

        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                $bridgeUpdateEndpoint,
                [
                    'bridge_authorized' => true,
                    'admin_id' => (string)($_SESSION['admin_id'] ?? ''),
                    'student_id' => $student_id,
                    'action' => $action,
                    'account_action' => 1,
                ]
            );

            if (is_array($bridgeData) && !empty($bridgeData['success'])) {
                header("Location: account_approval_settings.php?message=" . urlencode((string)($bridgeData['message'] ?? 'Account updated successfully.')));
                exit();
            }

            $error_message = (string)($bridgeData['message'] ?? 'Error updating account.');
        } else {
            if (!$conn instanceof PDO) {
                $error_message = 'Unable to process the account action right now.';
            } else {
            $freezeCheckStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'freeze_approvals' ORDER BY id DESC LIMIT 1");
            $freezeCheckStmt->execute();
            $freezeApprovalsEnabled = ((int)$freezeCheckStmt->fetchColumn() === 1);

            if ($freezeApprovalsEnabled) {
                header("Location: account_approval_settings.php?message=" . urlencode("Approval actions are currently frozen by admin settings."));
                exit();
            }
            
            if ($action === 'approve') {
                $stmt = $conn->prepare("UPDATE student_info SET status = 'approved', approved_by = ? WHERE student_number = ?");
                $stmt->execute([$_SESSION['admin_id'], $student_id]);
                srsClearStudentRejectionLog($conn, (string)$student_id);
                $message = "Account approved successfully.";
            } elseif ($action === 'reject') {
                $stmt = $conn->prepare("UPDATE student_info SET status = 'rejected', approved_by = ? WHERE student_number = ?");
                $stmt->execute([$_SESSION['admin_id'], $student_id]);
                srsRecordStudentRejection($conn, (string)$student_id, (string)$_SESSION['admin_id']);
                $message = "Account rejected.";
            } elseif ($action === 'revert_pending') {
                $stmt = $conn->prepare("UPDATE student_info SET status = 'pending', approved_by = NULL WHERE student_number = ?");
                $stmt->execute([$student_id]);
                srsClearStudentRejectionLog($conn, (string)$student_id);
                $message = "Account reverted to pending status.";
            }

            aasWriteAdminAuditLog(
                $conn,
                (string)$_SESSION['admin_id'],
                'account_action',
                'student_account',
                'Performed account action on student account.',
                ['student_id' => (string)$student_id, 'action' => (string)$action]
            );
            
            header("Location: account_approval_settings.php?message=" . urlencode($message));
            exit();
            }
        }
    }
    
    // Handle bulk actions
    if (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $selected_students = $_POST['selected_students'] ?? [];

        if ($useLaravelBridge) {
            $bridgeData = postLaravelJsonBridge(
                $bridgeUpdateEndpoint,
                [
                    'bridge_authorized' => true,
                    'admin_id' => (string)($_SESSION['admin_id'] ?? ''),
                    'bulk_action' => $action,
                    'selected_students' => array_values((array)$selected_students),
                    'action' => 'bulk_action',
                ]
            );

            if (is_array($bridgeData) && !empty($bridgeData['success'])) {
                header("Location: account_approval_settings.php?message=" . urlencode((string)($bridgeData['message'] ?? 'Bulk action completed.')));
                exit();
            }

            $error_message = (string)($bridgeData['message'] ?? 'Error applying bulk action.');
        } else {
            if (!$conn instanceof PDO) {
                $error_message = 'Unable to process bulk actions right now.';
            } else {
            $freezeCheckStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_name = 'freeze_approvals' ORDER BY id DESC LIMIT 1");
            $freezeCheckStmt->execute();
            $freezeApprovalsEnabled = ((int)$freezeCheckStmt->fetchColumn() === 1);

            if ($freezeApprovalsEnabled) {
                header("Location: account_approval_settings.php?message=" . urlencode("Bulk actions are disabled while approval freeze is enabled."));
                exit();
            }
            
            if (!empty($selected_students)) {
                $placeholders = str_repeat('?,', count($selected_students) - 1) . '?';
                
                if ($action === 'approve_selected') {
                    $stmt = $conn->prepare("UPDATE student_info SET status = 'approved', approved_by = ? WHERE student_number IN ($placeholders)");
                    $params = array_merge([$_SESSION['admin_id']], $selected_students);
                    $stmt->execute($params);
                    foreach ($selected_students as $selectedStudentId) {
                        srsClearStudentRejectionLog($conn, (string)$selectedStudentId);
                    }
                    $message = count($selected_students) . " accounts approved.";
                } elseif ($action === 'reject_selected') {
                    $stmt = $conn->prepare("UPDATE student_info SET status = 'rejected', approved_by = ? WHERE student_number IN ($placeholders)");
                    $params = array_merge([$_SESSION['admin_id']], $selected_students);
                    $stmt->execute($params);
                    foreach ($selected_students as $selectedStudentId) {
                        srsRecordStudentRejection($conn, (string)$selectedStudentId, (string)$_SESSION['admin_id']);
                    }
                    $message = count($selected_students) . " accounts rejected.";
                }

                aasWriteAdminAuditLog(
                    $conn,
                    (string)$_SESSION['admin_id'],
                    'bulk_account_action',
                    'student_accounts',
                    'Performed bulk account action on selected student accounts.',
                    [
                        'action' => (string)$action,
                        'selected_count' => count($selected_students),
                        'selected_student_ids_preview' => array_slice($selected_students, 0, 25)
                    ]
                );
                
                header("Location: account_approval_settings.php?message=" . urlencode($message));
                exit();
            }
            }
        }
    }
}

$masterlistProgramOptions = [];
$masterlistSummary = [];
$masterlistRows = [];
if ($conn instanceof PDO) {
    try {
        $masterlistProgramOptions = smlLoadProgramOptions($conn);
        $masterlistSummary = smlLoadMasterlistSummary($conn);
        $masterlistRows = smlLoadAuthorizedMasterlistRows($conn);
    } catch (Throwable $e) {
        error_log('Failed to load student masterlist settings: ' . $e->getMessage());
    }
}

// Create system_settings table if it doesn't exist
if ($conn instanceof PDO) {
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_name VARCHAR(255) UNIQUE NOT NULL,
            setting_value TEXT NOT NULL,
            updated_by VARCHAR(255),
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Normalize legacy schemas where setting_value was created as an integer.
    // Advanced settings such as allowed_email_domains require text storage.
    $columnTypeStmt = $conn->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings' AND COLUMN_NAME = 'setting_value' LIMIT 1");
    $columnTypeStmt->execute();
    $settingValueDataType = strtolower((string) ($columnTypeStmt->fetchColumn() ?: ''));
    if ($settingValueDataType !== '' && !in_array($settingValueDataType, ['text', 'varchar', 'char', 'mediumtext', 'longtext'], true)) {
        $conn->exec("ALTER TABLE system_settings MODIFY COLUMN setting_value TEXT NOT NULL");
    }
} catch (PDOException $e) {
    // Table might already exist, that's ok
}

try {
    $conn->exec(" 
        CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id VARCHAR(255) NOT NULL,
            action_type VARCHAR(120) NOT NULL,
            action_target VARCHAR(120) NOT NULL,
            summary VARCHAR(500) NOT NULL,
            metadata_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_audit_created_at (created_at),
            INDEX idx_admin_audit_action_type (action_type)
        )
    ");
} catch (PDOException $e) {
    error_log('Failed to ensure admin_audit_logs table: ' . $e->getMessage());
}

try {
    $adminAccount = aasLoadAdminAccountProfile($conn, (string)($_SESSION['admin_id'] ?? ''));
    $adminCredentialMinLength = aasResolveMinPasswordLength($conn);
} catch (Throwable $e) {
    error_log('Failed to load admin account settings profile: ' . $e->getMessage());
}
}

// Load current settings using service layer
$auto_approve_enabled = false;
$policySettingValues = [];
$advancedSettingValues = [];
$freezeApprovalsEnabled = false;
if ($conn instanceof PDO) {
    $auto_approve_enabled = aasLoadAutoApproveSetting($conn);
    $policySettingValues = aasLoadPolicySettingValues($conn, $policySettings);
    $advancedSettingValues = aasLoadAdvancedSettingValues($conn, $advancedSettings);
    $freezeApprovalsEnabled = aasIsFreezingEnabled($conn);
}
$defaultRecordsPerPage = (int)($advancedSettingValues['default_records_per_page'] ?? 10);

// Get account statistics
$stats_query = "
    SELECT 
        status,
        COUNT(*) as count 
    FROM student_info 
    GROUP BY status
";
$stats = [];
if ($conn instanceof PDO) {
    $stats_result = $conn->query($stats_query);
    if ($stats_result instanceof PDOStatement) {
        while ($row = $stats_result->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = $row['count'];
        }
    }
}

$pendingAlertThreshold = (int)($advancedSettingValues['pending_alert_threshold'] ?? 50);
$currentPendingCount = (int)($stats['pending'] ?? 0);
$pendingThresholdReached = ($pendingAlertThreshold > 0 && $currentPendingCount >= $pendingAlertThreshold);

$registrationStartRaw = (string)($advancedSettingValues['registration_open_start'] ?? '');
$registrationEndRaw = (string)($advancedSettingValues['registration_open_end'] ?? '');
$registrationStartTs = strtotime(str_replace('T', ' ', $registrationStartRaw));
$registrationEndTs = strtotime(str_replace('T', ' ', $registrationEndRaw));
$registrationNow = time();
$registrationTimezoneLabel = (string) (defined('APP_TIMEZONE') ? APP_TIMEZONE : date_default_timezone_get());
$registrationServerNowLabel = date('M d, Y h:i:s A', $registrationNow);

$registrationStatusLabel = 'OPEN';
$registrationStatusClass = 'enabled';
$registrationStatusDetail = 'Registration is currently accepting new student signups.';

if ((int)($advancedSettingValues['disable_new_registrations'] ?? '0') === 1) {
    $registrationStatusLabel = 'CLOSED (MANUAL OVERRIDE)';
    $registrationStatusClass = 'disabled';
    $registrationStatusDetail = 'Temporarily disabled by administrator setting.';
} elseif ($registrationStartTs !== false && $registrationNow < $registrationStartTs) {
    $registrationStatusLabel = 'SCHEDULED';
    $registrationStatusClass = 'scheduled';
    $registrationStatusDetail = 'Opens on ' . date('M d, Y h:i A', $registrationStartTs) . '.';
} elseif ($registrationEndTs !== false && $registrationNow > $registrationEndTs) {
    $registrationStatusLabel = 'CLOSED';
    $registrationStatusClass = 'disabled';
    $registrationStatusDetail = 'Window ended on ' . date('M d, Y h:i A', $registrationEndTs) . '.';
} elseif ($registrationEndTs !== false) {
    $registrationStatusDetail = 'Open until ' . date('M d, Y h:i A', $registrationEndTs) . '.';
}

$auditLogs = [];
if ($conn instanceof PDO) {
try {
    $auditStmt = $conn->prepare('SELECT id, admin_id, action_type, action_target, summary, metadata_json, created_at FROM admin_audit_logs ORDER BY created_at DESC LIMIT 30');
    $auditStmt->execute();
    $auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Failed to load admin audit logs: ' . $e->getMessage());
}
}

$programShiftStats = [
    'pending_adviser' => 0,
    'pending_current_coordinator' => 0,
    'pending_destination_coordinator' => 0,
    'pending_coordinator' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total' => 0,
];
$programShiftRecent = [];

if ($conn instanceof PDO) {
try {
    $shiftTableExistsStmt = $conn->query("SHOW TABLES LIKE 'program_shift_requests'");
    $shiftTableExists = $shiftTableExistsStmt && $shiftTableExistsStmt->fetchColumn();

    if ($shiftTableExists) {
        $shiftCountStmt = $conn->query('SELECT status, COUNT(*) AS count_value FROM program_shift_requests GROUP BY status');
        while ($shiftCountStmt && ($row = $shiftCountStmt->fetch(PDO::FETCH_ASSOC))) {
            $statusKey = (string)($row['status'] ?? '');
            if (array_key_exists($statusKey, $programShiftStats)) {
                $programShiftStats[$statusKey] = (int)($row['count_value'] ?? 0);
            }
        }

        $programShiftStats['total'] =
            (int)$programShiftStats['pending_adviser'] +
            (int)$programShiftStats['pending_current_coordinator'] +
            (int)$programShiftStats['pending_destination_coordinator'] +
            (int)$programShiftStats['pending_coordinator'] +
            (int)$programShiftStats['approved'] +
            (int)$programShiftStats['rejected'];

        $shiftRecentStmt = $conn->prepare(
            'SELECT
                request_code,
                student_number,
                student_name,
                current_program,
                requested_program,
                status,
                requested_at,
                adviser_action_at,
                coordinator_action_at,
                executed_at
             FROM program_shift_requests
             ORDER BY requested_at DESC, id DESC
             LIMIT 25'
        );
        $shiftRecentStmt->execute();
        $programShiftRecent = $shiftRecentStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Failed to load program shift oversight data: ' . $e->getMessage());
}
}

if ($useLaravelBridge) {
    $bridgeData = postLaravelJsonBridge(
        $bridgeOverviewEndpoint,
        [
            'bridge_authorized' => true,
            'admin_id' => (string) ($_SESSION['admin_id'] ?? ''),
        ]
    );

    if (is_array($bridgeData) && !empty($bridgeData['success'])) {
        if (!($conn instanceof PDO) && array_key_exists('auto_approve_enabled', $bridgeData)) {
            $auto_approve_enabled = aasBridgeToBool($bridgeData['auto_approve_enabled']);
        }
        if (isset($bridgeData['policy_setting_values']) && is_array($bridgeData['policy_setting_values'])) {
            $policySettingValues = $bridgeData['policy_setting_values'];
        }
        if (isset($bridgeData['advanced_setting_values']) && is_array($bridgeData['advanced_setting_values'])) {
            $advancedSettingValues = $bridgeData['advanced_setting_values'];
        }
        if (!($conn instanceof PDO) && array_key_exists('freeze_approvals_enabled', $bridgeData)) {
            $freezeApprovalsEnabled = aasBridgeToBool($bridgeData['freeze_approvals_enabled']);
        }
        $defaultRecordsPerPage = (int) ($bridgeData['default_records_per_page'] ?? $defaultRecordsPerPage);

        if (isset($bridgeData['stats']) && is_array($bridgeData['stats'])) {
            $stats = $bridgeData['stats'];
        }

        $pendingAlertThreshold = (int) ($bridgeData['pending_alert_threshold'] ?? $pendingAlertThreshold);
        $currentPendingCount = (int) ($bridgeData['current_pending_count'] ?? $currentPendingCount);
        if (!($conn instanceof PDO) && array_key_exists('pending_threshold_reached', $bridgeData)) {
            $pendingThresholdReached = aasBridgeToBool($bridgeData['pending_threshold_reached']);
        }

        if (isset($bridgeData['registration']) && is_array($bridgeData['registration'])) {
            $registrationStartRaw = (string) ($bridgeData['registration']['start_raw'] ?? $registrationStartRaw);
            $registrationEndRaw = (string) ($bridgeData['registration']['end_raw'] ?? $registrationEndRaw);
            $registrationStartTs = isset($bridgeData['registration']['start_ts']) && $bridgeData['registration']['start_ts'] !== null
                ? (int) $bridgeData['registration']['start_ts']
                : $registrationStartTs;
            $registrationEndTs = isset($bridgeData['registration']['end_ts']) && $bridgeData['registration']['end_ts'] !== null
                ? (int) $bridgeData['registration']['end_ts']
                : $registrationEndTs;
            $registrationNow = (int) ($bridgeData['registration']['now'] ?? $registrationNow);
            $registrationStatusLabel = (string) ($bridgeData['registration']['status_label'] ?? $registrationStatusLabel);
            $registrationStatusClass = (string) ($bridgeData['registration']['status_class'] ?? $registrationStatusClass);
            $registrationStatusDetail = (string) ($bridgeData['registration']['status_detail'] ?? $registrationStatusDetail);
        }

        if (isset($bridgeData['audit_logs']) && is_array($bridgeData['audit_logs'])) {
            $auditLogs = $bridgeData['audit_logs'];
        }
        if (isset($bridgeData['program_shift_stats']) && is_array($bridgeData['program_shift_stats'])) {
            $programShiftStats = $bridgeData['program_shift_stats'];
        }
        if (isset($bridgeData['program_shift_recent']) && is_array($bridgeData['program_shift_recent'])) {
            $programShiftRecent = $bridgeData['program_shift_recent'];
        }
        if (isset($bridgeData['admin_account']) && is_array($bridgeData['admin_account'])) {
            $adminAccount = array_merge($adminAccount, $bridgeData['admin_account']);
        }
    }
}

$adminSessionDeviceLabel = 'No active session record yet';
$adminSessionIpLabel = 'Unavailable';
$adminSessionLastSeenLabel = 'Unavailable';
$adminSessionCurrentLabel = 'Unknown';

$activeAdminUsername = trim((string)($adminAccount['username'] ?? $_SESSION['admin_username'] ?? $_SESSION['admin_id'] ?? ''));
if ($conn instanceof PDO && $activeAdminUsername !== '') {
    try {
        $activeSession = assLoadActiveSession($conn, $activeAdminUsername);
        if (is_array($activeSession)) {
            $adminSessionDeviceLabel = aasDescribeUserAgent((string)($activeSession['user_agent'] ?? ''));

            $activeIp = trim((string)($activeSession['user_ip'] ?? ''));
            $adminSessionIpLabel = $activeIp !== '' ? $activeIp : 'Unknown';

            $lastSeenRaw = (string)($activeSession['last_seen_at'] ?? '');
            $lastSeenTs = $lastSeenRaw !== '' ? strtotime($lastSeenRaw) : false;
            if ($lastSeenTs !== false) {
                $adminSessionLastSeenLabel = date('M d, Y h:i A', $lastSeenTs);
            }

            $adminSessionCurrentLabel = assIsCurrentAdminSession($conn, $activeAdminUsername)
                ? 'This session is active on this device.'
                : 'Another device is currently the active session.';
        } else {
            $adminSessionCurrentLabel = 'No active session currently recorded.';
        }
    } catch (Throwable $e) {
        error_log('Failed to load admin active session snapshot: ' . $e->getMessage());
    }
}

$adminCredentialMinLength = isset($policySettingValues['min_password_length'])
    ? max(6, min(64, (int)$policySettingValues['min_password_length']))
    : $adminCredentialMinLength;

// Pagination settings
$records_per_page = max(5, min(100, $defaultRecordsPerPage));
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page);
$offset = ($current_page - 1) * $records_per_page;

// Get search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// Build WHERE clause
$where_conditions = [];
$where_conditions[] = "status IN ('pending', 'approved', 'rejected')";

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $where_conditions[] = "(student_number LIKE :search OR first_name LIKE :search OR last_name LIKE :search OR email LIKE :search)";
}

if (!empty($filter_status) && in_array($filter_status, ['pending', 'approved', 'rejected'])) {
    $where_conditions[] = "status = :filter_status";
}

$where_clause = implode(' AND ', $where_conditions);

// Get total records for pagination
$count_query = "SELECT COUNT(*) as total FROM student_info WHERE $where_clause";
$total_records = 0;
$total_pages = 1;
if ($conn instanceof PDO) {
    $count_stmt = $conn->prepare($count_query);
    if (!empty($search)) {
        $count_stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    if (!empty($filter_status)) {
        $count_stmt->bindValue(':filter_status', $filter_status, PDO::PARAM_STR);
    }
    $count_stmt->execute();
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_records / $records_per_page));
}

// Get paginated accounts
$pending_query = "
    SELECT 
        student_number AS student_id, 
        first_name, 
        last_name, 
        middle_name, 
        email, 
        contact_number AS contact_no, 
        date_of_admission AS admission_date,
        created_at,
        status
    FROM student_info 
    WHERE $where_clause
    ORDER BY 
        CASE status 
            WHEN 'pending' THEN 1 
            WHEN 'approved' THEN 2 
            WHEN 'rejected' THEN 3 
        END,
        created_at DESC
    LIMIT :records_per_page OFFSET :offset
";
$accounts = [];
if ($conn instanceof PDO) {
    $pending_stmt = $conn->prepare($pending_query);
    if (!empty($search)) {
        $pending_stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    if (!empty($filter_status)) {
        $pending_stmt->bindValue(':filter_status', $filter_status, PDO::PARAM_STR);
    }
    $pending_stmt->bindValue(':records_per_page', (int)$records_per_page, PDO::PARAM_INT);
    $pending_stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $pending_stmt->execute();
    $accounts = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$shiftRecordsPerPage = 5;
$shiftCurrentPage = isset($_GET['shift_page']) && is_numeric($_GET['shift_page']) ? max(1, (int)$_GET['shift_page']) : 1;
$shiftTotalRecords = count($programShiftRecent);
$shiftTotalPages = max(1, (int)ceil($shiftTotalRecords / $shiftRecordsPerPage));
$shiftCurrentPage = min($shiftCurrentPage, $shiftTotalPages);
$programShiftRecentPage = array_slice($programShiftRecent, ($shiftCurrentPage - 1) * $shiftRecordsPerPage, $shiftRecordsPerPage);

$auditRecordsPerPage = 8;
$auditCurrentPage = isset($_GET['audit_page']) && is_numeric($_GET['audit_page']) ? max(1, (int)$_GET['audit_page']) : 1;
$auditTotalRecords = count($auditLogs);
$auditTotalPages = max(1, (int)ceil($auditTotalRecords / $auditRecordsPerPage));
$auditCurrentPage = min($auditCurrentPage, $auditTotalPages);
$auditLogsPage = array_slice($auditLogs, ($auditCurrentPage - 1) * $auditRecordsPerPage, $auditRecordsPerPage);

$masterlistRecordsPerPage = max(5, min(100, $defaultRecordsPerPage));
$masterlistCurrentPage = isset($_GET['masterlist_page']) && is_numeric($_GET['masterlist_page']) ? max(1, (int)$_GET['masterlist_page']) : 1;
$masterlistTotalRecords = count($masterlistSummary);
$masterlistTotalPages = max(1, (int)ceil($masterlistTotalRecords / $masterlistRecordsPerPage));
$masterlistCurrentPage = min($masterlistCurrentPage, $masterlistTotalPages);
$masterlistSummaryPage = array_slice($masterlistSummary, ($masterlistCurrentPage - 1) * $masterlistRecordsPerPage, $masterlistRecordsPerPage);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings</title>
    <link rel="icon" type="image/png" href="../img/cav.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --brand-700: #1f5f1b;
            --brand-600: #2a7a20;
            --brand-500: #3f9a3b;
            --surface-0: #f2f5f1;
            --surface-1: #ffffff;
            --surface-2: #f7faf7;
            --text-900: #223029;
            --text-700: #4d5a52;
            --line-soft: #dce7dc;
            --shadow-soft: 0 3px 10px rgba(24, 58, 22, 0.08);
        }

        body {
            background: var(--surface-0);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text-900);
        }

        /* Enhanced Modal styles for confirmation dialogs */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 2000;
            animation: fadeIn 0.4s ease;
        }
        
        .modal-container {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.7);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.8);
            text-align: center;
            z-index: 2001;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            min-width: 300px;
            max-width: 500px;
        }
        
        .modal-container.active {
            transform: translate(-50%, -50%) scale(1);
            opacity: 1;
        }
        
        .modal-icon {
            font-size: 48px;
            color: #4CAF50;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            filter: drop-shadow(0 0 10px rgba(76, 175, 80, 0.3));
        }
        
        .modal-title {
            color: #206018;
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        @keyframes fadeIn {
            from { 
                opacity: 0; 
                backdrop-filter: blur(0px);
            }
            to { 
                opacity: 1; 
                backdrop-filter: blur(10px);
            }
        }

        @keyframes modalSlideIn {
            from {
                transform: translate(-50%, -50%) scale(0.7) rotate(-5deg);
                opacity: 0;
            }
            to {
                transform: translate(-50%, -50%) scale(1) rotate(0deg);
                opacity: 1;
            }
        }

        @keyframes modalSlideOut {
            from {
                transform: translate(-50%, -50%) scale(1) rotate(0deg);
                opacity: 1;
            }
            to {
                transform: translate(-50%, -50%) scale(0.8) rotate(5deg);
                opacity: 0;
            }
        }

        .header {
            background: linear-gradient(135deg, #206018 0%, #2a7a20 100%);
            color: #fff;
            padding: 6px 15px;
            font-size: 18px;
            font-weight: bold;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(32, 96, 24, 0.2);
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .menu-toggle {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
        }

        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .admin-info {
            font-size: 14px;
            font-weight: 600;
            color: white;
            background: rgba(255, 255, 255, 0.15);
            padding: 4px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .header img {
            height: 32px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
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
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 25px;
            border-left-color: #4CAF50;
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            border-left: 4px solid #4CAF50;
        }

        .sidebar-menu img {
            width: 20px;
            height: 20px;
            margin-right: 0;
            filter: brightness(0) invert(1);
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
        }

        .container {
            margin: 65px auto 15px;
            width: min(1500px, calc(100vw - 280px));
            padding: 0 6px;
            transform: translateX(125px);
            transition: transform 0.3s ease, width 0.3s ease;
        }

        .sidebar.collapsed ~ .container {
            width: min(1600px, calc(100vw - 32px));
            transform: translateX(0);
        }

        .page-title {
            text-align: center;
            margin-bottom: 15px;
        }

        .page-title h1 {
            background: linear-gradient(135deg, #206018 0%, #2a7a20 100%);
            color: white;
            padding: 10px 18px;
            border-radius: 6px;
            display: inline-block;
            font-size: 17px;
            box-shadow: 0 2px 8px rgba(32, 96, 24, 0.24);
        }

        .page-subtitle {
            text-align: center;
            color: var(--text-700);
            font-size: 13px;
            margin: -4px auto 14px;
            max-width: 860px;
            line-height: 1.45;
        }

        .settings-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr);
            gap: 12px;
            margin-bottom: 14px;
            align-items: start;
        }

        .settings-column {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .settings-column .settings-card {
            margin-bottom: 0;
        }

        .settings-card {
            background: var(--surface-1);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 15px;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--line-soft);
        }

        .settings-card h2 {
            font-size: 14px;
            color: var(--brand-700);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .settings-card p.card-note {
            font-size: 12px;
            color: var(--text-700);
            margin-bottom: 10px;
            line-height: 1.45;
        }

        .policy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 10px;
        }

        .policy-item {
            background: #fff;
            border: 1px solid var(--line-soft);
            border-radius: 6px;
            padding: 10px;
        }

        .policy-item label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--brand-700);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .policy-item input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid #cfd9cf;
            border-radius: 6px;
            font-size: 13px;
        }

        .policy-item textarea {
            width: 100%;
            min-height: 52px;
            padding: 7px 10px;
            border: 1px solid #cfd9cf;
            border-radius: 6px;
            font-size: 13px;
            resize: vertical;
            font-family: inherit;
        }

        .policy-item input[type="checkbox"] {
            width: auto;
            transform: scale(1.15);
            margin-right: 8px;
        }

        .toggle-inline {
            display: flex;
            align-items: center;
            color: var(--brand-700);
            font-weight: 600;
            font-size: 12px;
            margin: 2px 0;
        }

        .policy-item small {
            display: block;
            margin-top: 6px;
            font-size: 11px;
            color: #5f6d62;
            line-height: 1.4;
        }

        .policy-item .server-time-note {
            margin-top: 8px;
            padding: 6px 8px;
            border-radius: 6px;
            background: #f5faf4;
            border: 1px solid #d9e7d9;
            color: #3f5a42;
            font-weight: 600;
        }

        .masterlist-shell {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .masterlist-upload-panel {
            background: linear-gradient(135deg, #fbfefb 0%, #f2f8f2 100%);
            border: 1px solid #d8e6d8;
            border-radius: 12px;
            padding: 14px;
        }

        .masterlist-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(220px, 0.9fr);
            gap: 12px;
            align-items: end;
        }

        .masterlist-input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .masterlist-input-group label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--brand-700);
        }

        .masterlist-input-group select,
        .masterlist-input-group input[type="file"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cfd9cf;
            border-radius: 10px;
            background: #fff;
            font-size: 13px;
            color: #17311a;
        }

        .masterlist-help {
            font-size: 11px;
            color: var(--text-700);
            line-height: 1.45;
        }

        .masterlist-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .masterlist-template-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 10px;
            border: 1px solid #cfe0cf;
            background: #f7fbf7;
            color: var(--brand-700);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 6px 14px rgba(24, 71, 18, 0.08);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .masterlist-template-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(24, 71, 18, 0.12);
            border-color: #b8d3b8;
        }

        .masterlist-view-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 10px;
            border: 1px solid #d4dde8;
            background: #f6f9fd;
            color: #1f4967;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 0 6px 14px rgba(20, 58, 98, 0.08);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            cursor: pointer;
        }

        .masterlist-view-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 18px rgba(20, 58, 98, 0.12);
            border-color: #bfd1e4;
        }

        .masterlist-format-note {
            padding: 10px 12px;
            border-radius: 10px;
            background: #f7fbf6;
            border: 1px dashed #c9dcc9;
            color: #3e5d41;
            font-size: 12px;
            line-height: 1.55;
        }

        .masterlist-format-note strong {
            color: var(--brand-700);
        }

        .masterlist-summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            background: #fff;
        }

        .masterlist-summary-table th,
        .masterlist-summary-table td {
            padding: 11px 12px;
            font-size: 12px;
            text-align: left;
            border-bottom: 1px solid #edf2ed;
            vertical-align: top;
        }

        .masterlist-summary-table th {
            background: #f3f8f3;
            color: var(--brand-700);
            font-size: 11px;
            letter-spacing: 0.07em;
            text-transform: uppercase;
        }

        .masterlist-summary-table tr:last-child td {
            border-bottom: none;
        }

        .masterlist-program-pill {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            background: #ebf5e9;
            color: #225c1f;
            font-weight: 700;
            font-size: 11px;
        }

        .masterlist-modal[hidden] {
            display: none;
        }

        .masterlist-modal {
            position: fixed;
            inset: 0;
            z-index: 2200;
            background: rgba(16, 34, 18, 0.62);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            padding: 72px 24px 24px;
        }

        .masterlist-modal-dialog {
            width: min(1180px, calc(100vw - 48px));
            max-height: calc(100vh - 108px);
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 28px 60px rgba(0, 0, 0, 0.22);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin: 0 auto;
        }

        .masterlist-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 22px 16px;
            border-bottom: 1px solid #e7efe7;
            background: linear-gradient(135deg, #fbfefb 0%, #f1f7f1 100%);
        }

        .masterlist-modal-header h3 {
            margin: 0 0 4px;
            color: var(--brand-700);
            font-size: 1.18rem;
        }

        .masterlist-modal-header p {
            margin: 0;
            color: #4b6250;
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .masterlist-modal-close {
            flex: 0 0 auto;
            width: 40px;
            height: 40px;
            border: 0;
            border-radius: 999px;
            background: #e7efe7;
            color: #1d4620;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
            transition: background 0.18s ease, transform 0.18s ease;
        }

        .masterlist-modal-close:hover {
            background: #d8e7d8;
            transform: scale(1.03);
        }

        .masterlist-modal-body {
            padding: 18px 22px 22px;
            overflow: auto;
            background: #fcfdfc;
        }

        .masterlist-modal-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .masterlist-modal-stats {
            display: flex;
            align-items: stretch;
            gap: 12px;
            flex-wrap: wrap;
        }

        .masterlist-modal-stat {
            min-width: 150px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #dce8dc;
            background: #fff;
            box-shadow: 0 6px 18px rgba(24, 71, 18, 0.04);
        }

        .masterlist-modal-stat strong {
            display: block;
            margin-bottom: 4px;
            color: #5e735f;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .masterlist-modal-stat span {
            color: var(--brand-700);
            font-size: 1.35rem;
            font-weight: 800;
        }

        .masterlist-modal-search {
            width: min(100%, 360px);
            padding: 10px 12px;
            border: 1px solid #ced8ce;
            border-radius: 10px;
            background: #fff;
            color: #17311a;
            font-size: 13px;
        }

        .masterlist-modal-search:focus {
            outline: none;
            border-color: #95bb96;
            box-shadow: 0 0 0 3px rgba(94, 152, 92, 0.12);
        }

        .masterlist-modal-table-wrap {
            border: 1px solid #dde7dd;
            border-radius: 14px;
            overflow: hidden;
            background: #fff;
        }

        .masterlist-modal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .masterlist-modal-table th,
        .masterlist-modal-table td {
            padding: 11px 12px;
            border-bottom: 1px solid #edf2ed;
            text-align: left;
            vertical-align: top;
        }

        .masterlist-modal-table th {
            position: sticky;
            top: 0;
            background: #f2f7f2;
            color: var(--brand-700);
            font-size: 11px;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            z-index: 1;
        }

        .masterlist-modal-table tbody tr:nth-child(even) {
            background: #fbfdfb;
        }

        .masterlist-modal-empty {
            padding: 18px;
            color: #4c6150;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .settings-actions {
            margin-top: 12px;
            display: flex;
            justify-content: flex-end;
        }

        .status-chip {
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 12px;
        }

        .status-chip.enabled {
            background: #d4edda;
            color: #155724;
        }

        .status-chip.disabled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-chip.scheduled {
            background: #dceafc;
            color: #0b4b9b;
        }

        .registration-window-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 2px 0 12px;
            flex-wrap: wrap;
        }

        .registration-window-status .detail {
            font-size: 12px;
            color: var(--text-700);
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 2px 0 8px;
        }

        .section-heading h2 {
            color: var(--brand-700);
            font-size: 14px;
        }

        .section-heading span {
            color: var(--text-700);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .toggle-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: var(--surface-2);
            border-radius: 6px;
            border: 1px solid var(--line-soft);
        }

        .toggle-info {
            flex: 1;
        }

        .toggle-info h3 {
            color: var(--brand-700);
            margin-bottom: 5px;
            font-size: 15px;
        }

        .toggle-info p {
            color: var(--text-700);
            font-size: 13px;
            line-height: 1.5;
        }

        .toggle-switch {
            position: relative;
            width: 60px;
            height: 30px;
            margin-left: 15px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .admin-account-shell {
            display: grid;
            gap: 12px;
        }

        .admin-account-summary {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .admin-account-pill {
            background: var(--surface-2);
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            padding: 12px 14px;
        }

        .admin-account-pill span {
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.45px;
            text-transform: uppercase;
            color: var(--text-700);
            margin-bottom: 4px;
        }

        .admin-account-pill strong {
            display: block;
            font-size: 14px;
            line-height: 1.35;
            color: var(--brand-700);
            word-break: break-word;
        }

        .admin-account-pill small {
            display: block;
            margin-top: 6px;
            font-size: 11px;
            line-height: 1.45;
            color: var(--text-700);
            word-break: break-word;
        }

        .admin-account-pill.session-pill {
            grid-column: 1 / -1;
        }

        .admin-account-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .admin-account-field {
            background: #fff;
            border: 1px solid var(--line-soft);
            border-radius: 8px;
            padding: 10px;
        }

        .admin-account-field.full-span {
            grid-column: 1 / -1;
        }

        .admin-account-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--brand-700);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.35px;
        }

        .admin-account-field input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #cfd9cf;
            border-radius: 6px;
            font-size: 13px;
        }

        .admin-account-field input[readonly] {
            background: #f7faf7;
            color: var(--text-700);
        }

        .admin-account-field small {
            display: block;
            margin-top: 6px;
            font-size: 11px;
            color: #5f6d62;
            line-height: 1.4;
        }

        .admin-account-note {
            padding: 10px 12px;
            background: #f7faf7;
            border: 1px dashed #c9d7c9;
            border-radius: 8px;
            font-size: 12px;
            color: var(--text-700);
            line-height: 1.45;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 30px;
            border: 2px solid #ddd;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        input:checked + .slider {
            background-color: #4CAF50;
            border-color: #45a049;
        }

        input:checked + .slider:before {
            transform: translateX(30px);
        }

        .slider:after {
            content: '';
            position: absolute;
            top: 7px;
            left: 7px;
            width: 14px;
            height: 14px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>') no-repeat center;
            background-size: 10px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        input:checked + .slider:after {
            content: '';
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>') no-repeat center;
            background-size: 10px;
            left: 38px;
            opacity: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-card {
            background: var(--surface-1);
            padding: 12px;
            border-radius: 6px;
            text-align: center;
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--line-soft);
        }

        .stat-number {
            font-size: 20px;
            font-weight: bold;
            color: #206018;
            margin-bottom: 4px;
        }

        .stat-label {
            color: #666;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .accounts-table {
            background: var(--surface-1);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(24, 58, 22, 0.08);
            border: 1px solid var(--line-soft);
            --sticky-header-height: 62px;
            --sticky-filter-height: 56px;
        }

        .table-header {
            background: linear-gradient(135deg, #206018 0%, #2a7a20 100%);
            color: white;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            position: sticky;
            top: 0;
            z-index: 5;
            box-shadow: 0 3px 10px rgba(20, 54, 18, 0.18);
        }

        .table-header h3 {
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.2px;
        }

        .search-filter-bar {
            background: #f6faf6;
            padding: 10px 14px;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--line-soft);
            position: sticky;
            top: var(--sticky-header-height);
            z-index: 4;
        }

        .search-input-compact {
            padding: 8px 14px;
            border: 1px solid #c9d7c9;
            border-radius: 18px;
            font-size: 13px;
            outline: none;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 180px;
            background: #fff;
        }

        .search-input-compact:focus {
            border-color: #206018;
            box-shadow: 0 3px 12px rgba(32, 96, 24, 0.14);
        }

        .search-input-compact::placeholder {
            color: #76837a;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #c9d7c9;
            border-radius: 18px;
            font-size: 13px;
            outline: none;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            border-color: #206018;
        }

        .bulk-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }

        .bulk-actions .btn {
            border-radius: 20px;
            padding: 7px 11px;
            font-size: 10px;
            letter-spacing: 0.35px;
            box-shadow: 0 2px 8px rgba(22, 48, 19, 0.18);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .table-scroll {
            max-height: 62vh;
            overflow: auto;
            position: relative;
        }

        th, td {
            padding: 10px 10px;
            text-align: left;
            border-bottom: 1px solid #e4ece4;
            vertical-align: middle;
        }

        th {
            background: #f1f6f1;
            font-weight: 600;
            color: #285f24;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            position: sticky;
            top: calc(var(--sticky-header-height) + var(--sticky-filter-height));
            z-index: 2;
            box-shadow: inset 0 -1px 0 #dbe7db;
        }
        
        /* Column width optimization */
        th:nth-child(1), td:nth-child(1) { width: 3%; } /* Checkbox */
        th:nth-child(2), td:nth-child(2) { width: 10%; } /* Student ID */
        th:nth-child(3), td:nth-child(3) { width: 14%; max-width: 120px; } /* Last Name */
        th:nth-child(4), td:nth-child(4) { width: 14%; max-width: 120px; } /* First Name */
        th:nth-child(5), td:nth-child(5) { width: 10%; max-width: 100px; } /* Middle Name */
        th:nth-child(6), td:nth-child(6) { width: 16%; max-width: 130px; } /* Email */
        th:nth-child(7), td:nth-child(7) { width: 10%; } /* Contact */
        th:nth-child(8), td:nth-child(8) { width: 10%; } /* Admission Date */
        th:nth-child(9), td:nth-child(9) { width: 8%; } /* Status */
        th:nth-child(10), td:nth-child(10) { width: 15%; white-space: normal; } /* Actions */

        tbody tr:nth-child(even) {
            background: #fbfdfb;
        }

        tr:hover {
            background: rgba(39, 103, 31, 0.055);
        }

        td:nth-child(3), td:nth-child(4), td:nth-child(5), td:nth-child(6) {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
            word-break: break-word;
        }

        .status-badge {
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.45px;
            display: inline-block;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
            border: 1px solid #a3cfbb;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b0b7;
        }

        .btn {
            padding: 5px 8px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            transition: all 0.3s ease;
            margin: 1px;
            display: inline-block;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .btn-approve {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
        }

        .btn-approve:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(76, 175, 80, 0.3);
        }

        .btn-reject {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }

        .btn-reject:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }

        .btn-revert {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }

        .btn-revert:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }

        .btn-bulk {
            background: linear-gradient(135deg, #206018 0%, #2a7a20 100%);
            color: white;
            padding: 5px 10px;
            font-size: 10px;
        }

        .btn-bulk:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(32, 96, 24, 0.3);
        }

        .row-action-form {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #f6faf6;
            border: 1px solid #dce7dc;
            border-radius: 999px;
            padding: 3px 5px;
        }

        .row-action-form .btn {
            margin: 0;
            border-radius: 999px;
            min-width: 28px;
            min-height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 7px;
        }

        .back-btn {
            position: fixed;
            top: 60px;
            right: 15px;
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 18px;
            text-decoration: none;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 3px 8px rgba(76, 175, 80, 0.3);
            z-index: 999;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 14px rgba(76, 175, 80, 0.4);
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 14px;
            gap: 6px;
            flex-wrap: wrap;
            background: #f6faf6;
            border-top: 1px solid var(--line-soft);
        }
        
        .pagination-btn {
            padding: 6px 11px;
            background: #fff;
            color: #206018;
            border: 1px solid #d3ded3;
            border-radius: 8px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 32px;
            text-align: center;
        }
        
        .pagination-btn:hover:not(.active):not(.disabled) {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border-color: #206018;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(32, 96, 24, 0.3);
        }
        
        .pagination-btn.active {
            background: linear-gradient(135deg, #206018 0%, #4CAF50 100%);
            color: white;
            border-color: #206018;
            box-shadow: 0 4px 10px rgba(32, 96, 24, 0.3);
            cursor: default;
        }
        
        .pagination-btn.disabled {
            background: #f0f0f0;
            color: #ccc;
            border-color: #e0e0e0;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .pagination-info {
            color: #666;
            font-size: 11px;
            font-weight: 500;
            padding: 0 10px;
            background: #fff;
            border: 1px solid #d7e2d7;
            border-radius: 999px;
            line-height: 28px;
        }

        .message {
            background: #d4edda;
            color: #155724;
            padding: 10px 12px;
            border-radius: 5px;
            margin-bottom: 12px;
            border: 1px solid #a3cfbb;
            font-size: 13px;
        }

        .message.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffe69c;
        }

        .message.critical {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b0b7;
        }

        .settings-kpi {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #2e6b28;
            background: #eef7ee;
            border: 1px solid #d3e6d3;
            border-radius: 999px;
            padding: 4px 10px;
        }

        .audit-table-wrap {
            overflow: auto;
            border: 1px solid var(--line-soft);
            border-radius: 8px;
            max-height: 420px;
            overscroll-behavior: contain;
            background: #fff;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 760px;
        }

        .audit-table th,
        .audit-table td {
            border-bottom: 1px solid #e4ece4;
            padding: 9px 10px;
            vertical-align: top;
        }

        .audit-table th {
            background: #f1f6f1;
            color: #285f24;
            position: sticky;
            top: 0;
            z-index: 1;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
        }

        .audit-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            background: #eef7ee;
            color: #225f1f;
            border: 1px solid #d0e6cf;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.35px;
        }

        .audit-meta {
            color: #4f5d54;
            font-size: 11px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .shift-status-pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.35px;
        }

        .shift-status-pill.pending-adviser {
            background: #fff6e6;
            color: #8a5a00;
            border-color: #f2d59e;
        }

        .shift-status-pill.pending-coordinator {
            background: #e8f4ff;
            color: #0b4f85;
            border-color: #b8d8f2;
        }

        .shift-status-pill.approved {
            background: #eaf7ea;
            color: #1f6d2b;
            border-color: #c7e7cb;
        }

        .shift-status-pill.rejected {
            background: #fdecec;
            color: #8d2f2f;
            border-color: #f2c3c3;
        }

        .checkbox {
            transform: scale(1.2);
            margin-right: 8px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-250px);
            }

            .sidebar:not(.collapsed) {
                transform: translateX(0);
            }

            .container {
                margin: 80px 10px 20px;
                width: calc(100vw - 20px);
                transform: none;
            }

            .admin-info {
                font-size: 12px;
                padding: 3px 10px;
            }

            .container {
                margin: 80px 10px 20px;
                width: calc(100vw - 20px);
                transform: none;
            }

            .settings-layout {
                grid-template-columns: 1fr;
            }

            .masterlist-grid {
                grid-template-columns: 1fr;
            }

            .masterlist-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .masterlist-actions > div:first-child {
                width: 100%;
                justify-content: stretch;
            }

            .masterlist-template-link,
            .masterlist-view-btn,
            .masterlist-actions .settings-actions,
            .masterlist-actions .settings-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .masterlist-modal {
                align-items: stretch;
                padding: 58px 12px 12px;
            }

            .masterlist-modal-dialog {
                width: min(100%, calc(100vw - 24px));
                max-height: calc(100vh - 70px);
            }

            .masterlist-modal-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .masterlist-modal-stats {
                width: 100%;
            }

            .masterlist-modal-stat {
                flex: 1 1 140px;
            }

            .masterlist-modal-search {
                width: 100%;
            }

            .admin-account-summary,
            .admin-account-grid {
                grid-template-columns: 1fr;
            }
            
            .toggle-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .table-header {
                flex-direction: column;
                gap: 15px;
            }

            .table-scroll {
                max-height: 55vh;
            }

            .accounts-table {
                --sticky-header-height: 104px;
                --sticky-filter-height: 94px;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px 10px;
            }
        }
    
        /* Sidebar normalization: consistent spacing and interaction across admin pages */
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
            line-height: 1.2;
            font-size: 15px;
            border-left: 4px solid transparent;
            transition: all 0.25s ease;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.10);
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
            flex: 0 0 20px;
            filter: brightness(0) invert(1);
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
            line-height: 1.2;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <button class="menu-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">&#9776;</button>
            <img src="../img/cav.png" alt="CvSU Logo" onclick="toggleSidebar()" style="cursor:pointer;">
            <span style="color: #d9e441;">ASPLAN</span>
        </div>
        <div class="admin-info">Admin Panel</div>
    </div>

    <?php
    $activeAdminPage = 'account_approval_settings';
    $adminSidebarCollapsed = true;
    require __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <div class="container" id="mainContainer">
        <div class="page-title">
            <h1><i class="fas fa-user-cog"></i> Settings</h1>
        </div>
        <p class="page-subtitle">Manage account policies, approval behavior, and student account actions from one organized workspace.</p>

        <?php if (isset($_GET['message'])): ?>
            <div class="message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['message']); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message !== ''): ?>
            <div class="message" style="background: #f8d7da; color: #721c24; border-color: #f1b0b7;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($freezeApprovalsEnabled): ?>
            <div class="message warning">
                <i class="fas fa-pause-circle"></i> Emergency freeze is active. Approve/reject/revert and bulk actions are temporarily disabled.
            </div>
        <?php endif; ?>

        <?php if ($pendingThresholdReached): ?>
            <div class="message critical">
                <i class="fas fa-exclamation-circle"></i>
                Pending accounts reached <?php echo $currentPendingCount; ?>, which meets/exceeds the configured alert threshold of <?php echo $pendingAlertThreshold; ?>.
            </div>
        <?php endif; ?>

        <div class="registration-window-status">
            <span class="status-chip <?php echo htmlspecialchars($registrationStatusClass); ?>">
                <i class="fas fa-calendar-alt"></i> Registration: <?php echo htmlspecialchars($registrationStatusLabel); ?>
            </span>
            <span class="detail"><?php echo htmlspecialchars($registrationStatusDetail); ?></span>
        </div>

        <div class="settings-layout">
            <div class="settings-column">
                <div class="settings-card">
                    <form method="POST">
                        <h2><i class="fas fa-shield-alt"></i> Security and Rate Limit Policies</h2>
                        <p class="card-note">Control session expiry, password policy, and anti-bruteforce thresholds.</p>

                        <div class="policy-grid">
                            <?php foreach ($policySettings as $key => $meta): ?>
                                <div class="policy-item">
                                    <label for="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($meta['label']); ?>
                                    </label>
                                    <input
                                        type="number"
                                        id="<?php echo htmlspecialchars($key); ?>"
                                        name="<?php echo htmlspecialchars($key); ?>"
                                        value="<?php echo (int)$policySettingValues[$key]; ?>"
                                        min="<?php echo (int)$meta['min']; ?>"
                                        max="<?php echo (int)$meta['max']; ?>"
                                    >
                                    <small><?php echo htmlspecialchars($meta['help']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="settings-actions">
                            <button type="submit" name="update_policy_settings" value="1" class="btn btn-bulk" style="padding:8px 14px; font-size:11px;">
                                <i class="fas fa-save"></i> Save Policy Settings
                            </button>
                        </div>
                    </form>
                </div>

                <div class="settings-card">
                    <form method="POST">
                        <h2><i class="fas fa-cogs"></i> Advanced Admin Controls</h2>
                        <p class="card-note">Configure authentication hardening, workflow safeguards, governance controls, and operational safety flags.</p>

                        <div class="policy-grid">
                            <?php foreach ($advancedSettings as $key => $meta): ?>
                                <div class="policy-item">
                                    <label for="<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($meta['label']); ?>
                                    </label>

                                    <?php if (($meta['type'] ?? 'text') === 'boolean'): ?>
                                        <label class="toggle-inline" for="<?php echo htmlspecialchars($key); ?>">
                                            <input
                                                type="checkbox"
                                                id="<?php echo htmlspecialchars($key); ?>"
                                                name="<?php echo htmlspecialchars($key); ?>"
                                                value="1"
                                                <?php echo ((int)$advancedSettingValues[$key] === 1) ? 'checked' : ''; ?>
                                            >
                                            Enabled
                                        </label>
                                    <?php elseif (($meta['type'] ?? 'text') === 'number'): ?>
                                        <input
                                            type="number"
                                            id="<?php echo htmlspecialchars($key); ?>"
                                            name="<?php echo htmlspecialchars($key); ?>"
                                            value="<?php echo (int)$advancedSettingValues[$key]; ?>"
                                            min="<?php echo (int)($meta['min'] ?? 0); ?>"
                                            max="<?php echo (int)($meta['max'] ?? 999999); ?>"
                                        >
                                    <?php elseif (($meta['type'] ?? 'text') === 'datetime'): ?>
                                        <input
                                            type="datetime-local"
                                            id="<?php echo htmlspecialchars($key); ?>"
                                            name="<?php echo htmlspecialchars($key); ?>"
                                            value="<?php echo htmlspecialchars(aasToDateTimeLocalValue((string)$advancedSettingValues[$key])); ?>"
                                        >
                                        <?php if ($key === 'registration_open_start' || $key === 'registration_open_end'): ?>
                                            <small class="server-time-note">
                                                Current server time (<?php echo htmlspecialchars($registrationTimezoneLabel); ?>):
                                                <?php echo htmlspecialchars($registrationServerNowLabel); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <textarea
                                            id="<?php echo htmlspecialchars($key); ?>"
                                            name="<?php echo htmlspecialchars($key); ?>"
                                            placeholder="<?php echo htmlspecialchars($meta['placeholder'] ?? ''); ?>"
                                        ><?php echo htmlspecialchars($advancedSettingValues[$key]); ?></textarea>
                                    <?php endif; ?>

                                    <small><?php echo htmlspecialchars($meta['help']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="settings-actions">
                            <button type="submit" name="update_advanced_settings" value="1" class="btn btn-bulk" style="padding:8px 14px; font-size:11px;">
                                <i class="fas fa-save"></i> Save Advanced Controls
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="settings-column">
                <div class="settings-card">
                    <form method="POST" id="settingsForm">
                        <h2><i class="fas fa-toggle-on"></i> Account Approval Control</h2>
                        <p class="card-note">Choose whether student registrations are automatically approved or queued for manual review.</p>
                        <div class="toggle-container">
                            <div class="toggle-info">
                                <h3>Auto-Approval Mode</h3>
                                <p>When enabled, new student accounts can login immediately after registration.</p>
                                <div class="status-chip <?php echo $auto_approve_enabled ? 'enabled' : 'disabled'; ?>">
                                    Status: <?php echo $auto_approve_enabled ? 'AUTO-APPROVAL ENABLED' : 'AUTO-APPROVAL DISABLED'; ?>
                                </div>
                            </div>
                            <div class="toggle-switch">
                                <input type="hidden" name="auto_approve" value="0">
                                <input type="checkbox" name="auto_approve" value="1" id="auto_approve" <?php echo $auto_approve_enabled ? 'checked' : ''; ?> <?php echo $freezeApprovalsEnabled ? 'disabled' : ''; ?>>
                                <label for="auto_approve" class="slider"></label>
                            </div>
                        </div>
                        <input type="hidden" name="update_setting" value="1">
                    </form>
                </div>

                <div class="settings-card">
                    <form method="POST" enctype="multipart/form-data">
                        <h2><i class="fas fa-file-upload"></i> Authorized Student Masterlist</h2>
                        <p class="card-note">Upload the official CSV authorization list per program. This masterlist controls which students are allowed to create a student account and access the system. It is different from the registered students directory.</p>

                        <div class="masterlist-shell">
                            <div class="masterlist-upload-panel">
                                <div class="masterlist-grid">
                                    <div class="masterlist-input-group">
                                        <label for="masterlist_program">Target Program</label>
                                        <select id="masterlist_program" name="masterlist_program" required>
                                            <option value="">-- Select Program --</option>
                                            <?php foreach ($masterlistProgramOptions as $programLabel): ?>
                                                <option value="<?php echo htmlspecialchars((string) $programLabel); ?>" <?php echo ((string) $selectedMasterlistProgram === (string) $programLabel) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string) $programLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="masterlist-help">Uploading a new CSV replaces the current authorization list for the selected program.</div>
                                    </div>

                                    <div class="masterlist-input-group">
                                        <label for="masterlist_csv">CSV File</label>
                                        <input type="file" id="masterlist_csv" name="masterlist_csv" accept=".csv,text/csv" required>
                                        <div class="masterlist-help">Required columns: <strong>Student Number</strong>, <strong>Last name</strong>, and <strong>First name</strong>. <strong>Middle Initial</strong> is optional.</div>
                                    </div>
                                </div>

                                <div class="masterlist-format-note">
                                    <strong>Security rule:</strong> student registration and student login rely on this authorization masterlist. Keep each upload current so only approved student IDs can create and use student accounts.
                                </div>
                            </div>

                            <div class="masterlist-actions">
                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <a href="download_student_masterlist_template.php" class="masterlist-template-link">
                                        <i class="fas fa-file-download"></i> Download CSV Template
                                    </a>
                                    <button type="button" class="masterlist-view-btn" id="openMasterlistModal">
                                        <i class="fas fa-list-ul"></i> View Authorized List
                                    </button>
                                </div>

                                <div class="settings-actions" style="margin-top:0;">
                                    <button type="submit" name="upload_student_masterlist" value="1" class="btn btn-bulk" style="padding:8px 14px; font-size:11px;">
                                        <i class="fas fa-upload"></i> Upload Authorization Masterlist
                                    </button>
                                </div>
                            </div>

                            <?php if (!empty($masterlistSummary)): ?>
                                <table class="masterlist-summary-table">
                                    <thead>
                                        <tr>
                                            <th>Program</th>
                                            <th>Authorized Students</th>
                                            <th>Latest Upload</th>
                                            <th>Updated By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($masterlistSummaryPage as $summaryRow): ?>
                                            <tr>
                                                <td><span class="masterlist-program-pill"><?php echo htmlspecialchars((string) ($summaryRow['program'] ?? '')); ?></span></td>
                                                <td><?php echo (int) ($summaryRow['total_students'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($summaryRow['last_uploaded_at'] ?? '')); ?></td>
                                                <td><?php echo htmlspecialchars((string) ($summaryRow['uploaded_by'] ?? '')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php echo aasRenderMiniPagination('masterlist_page', $masterlistCurrentPage, $masterlistTotalPages); ?>
                            <?php else: ?>
                                <div class="masterlist-format-note">
                                    No official student masterlist has been uploaded yet. Student registration and student login will stay blocked until the first masterlist is uploaded.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="masterlist-modal" id="masterlistModal" hidden aria-hidden="true">
                            <div class="masterlist-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="masterlistModalTitle">
                                <div class="masterlist-modal-header">
                                    <div>
                                        <h3 id="masterlistModalTitle">Authorized Student Masterlist</h3>
                                        <p>Review the student IDs currently authorized for account creation and login access. This list is separate from the registered students directory.</p>
                                    </div>
                                    <button type="button" class="masterlist-modal-close" id="closeMasterlistModal" aria-label="Close authorized masterlist view">&times;</button>
                                </div>
                                <div class="masterlist-modal-body">
                                    <div class="masterlist-modal-toolbar">
                                        <div class="masterlist-modal-stats">
                                            <div class="masterlist-modal-stat">
                                                <strong>Authorized Rows</strong>
                                                <span><?php echo count($masterlistRows); ?></span>
                                            </div>
                                            <div class="masterlist-modal-stat">
                                                <strong>Programs</strong>
                                                <span><?php echo count($masterlistSummary); ?></span>
                                            </div>
                                        </div>
                                        <input
                                            type="search"
                                            id="masterlistModalSearch"
                                            class="masterlist-modal-search"
                                            placeholder="Search by student number, name, or program..."
                                        >
                                    </div>

                                    <div class="masterlist-modal-table-wrap">
                                        <table class="masterlist-modal-table">
                                            <thead>
                                                <tr>
                                                    <th>Student Number</th>
                                                    <th>Last Name</th>
                                                    <th>First Name</th>
                                                    <th>MI</th>
                                                    <th>Program</th>
                                                    <th>Uploaded By</th>
                                                    <th>Uploaded At</th>
                                                </tr>
                                            </thead>
                                            <tbody id="masterlistModalTableBody">
                                                <?php if (!empty($masterlistRows)): ?>
                                                    <?php foreach ($masterlistRows as $masterlistRow): ?>
                                                        <?php
                                                        $studentNumber = (string) ($masterlistRow['student_number'] ?? '');
                                                        $lastName = (string) ($masterlistRow['last_name'] ?? '');
                                                        $firstName = (string) ($masterlistRow['first_name'] ?? '');
                                                        $middleInitial = (string) ($masterlistRow['middle_initial'] ?? '');
                                                        $programName = (string) ($masterlistRow['program'] ?? '');
                                                        $uploadedBy = (string) ($masterlistRow['uploaded_by'] ?? '');
                                                        $uploadedAt = (string) ($masterlistRow['uploaded_at'] ?? '');
                                                        $searchIndex = strtolower(trim($studentNumber . ' ' . $lastName . ' ' . $firstName . ' ' . $middleInitial . ' ' . $programName . ' ' . $uploadedBy));
                                                        ?>
                                                        <tr data-masterlist-row data-search="<?php echo htmlspecialchars($searchIndex, ENT_QUOTES); ?>">
                                                            <td><?php echo htmlspecialchars($studentNumber); ?></td>
                                                            <td><?php echo htmlspecialchars($lastName); ?></td>
                                                            <td><?php echo htmlspecialchars($firstName); ?></td>
                                                            <td><?php echo htmlspecialchars($middleInitial); ?></td>
                                                            <td><span class="masterlist-program-pill"><?php echo htmlspecialchars($programName); ?></span></td>
                                                            <td><?php echo htmlspecialchars($uploadedBy); ?></td>
                                                            <td><?php echo htmlspecialchars($uploadedAt); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr id="masterlistModalEmptyRow">
                                                        <td colspan="7" class="masterlist-modal-empty">No authorized students have been uploaded yet.</td>
                                                    </tr>
                                                <?php endif; ?>
                                                <tr id="masterlistModalNoResults" hidden>
                                                    <td colspan="7" class="masterlist-modal-empty">No authorized student rows match your search.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="settings-card">
                    <form method="POST">
                        <h2><i class="fas fa-user-shield"></i> Admin Account Security</h2>
                        <p class="card-note">Update your admin username or password from the same settings workspace. Use your current password to confirm the change.</p>

                        <div class="admin-account-shell">
                            <div class="admin-account-summary">
                                <div class="admin-account-pill">
                                    <span>Current Username</span>
                                    <strong><?php echo htmlspecialchars((string)($adminAccount['username'] ?? $_SESSION['admin_id'] ?? '')); ?></strong>
                                </div>
                                <div class="admin-account-pill">
                                    <span>Account Holder</span>
                                    <strong><?php echo htmlspecialchars((string)($adminAccount['full_name'] ?? $_SESSION['admin_full_name'] ?? 'Administrator')); ?></strong>
                                </div>
                                <div class="admin-account-pill session-pill">
                                    <span>Last Active Session Device/IP</span>
                                    <strong><?php echo htmlspecialchars($adminSessionDeviceLabel); ?></strong>
                                    <small>
                                        IP: <?php echo htmlspecialchars($adminSessionIpLabel); ?><br>
                                        Last seen: <?php echo htmlspecialchars($adminSessionLastSeenLabel); ?><br>
                                        <?php echo htmlspecialchars($adminSessionCurrentLabel); ?>
                                    </small>
                                </div>
                            </div>

                            <div class="admin-account-grid">
                                <div class="admin-account-field full-span">
                                    <label for="current_admin_username">Current Username</label>
                                    <input
                                        type="text"
                                        id="current_admin_username"
                                        value="<?php echo htmlspecialchars((string)($adminAccount['username'] ?? $_SESSION['admin_id'] ?? '')); ?>"
                                        readonly
                                    >
                                    <small>This reflects the username currently tied to your admin session.</small>
                                </div>

                                <div class="admin-account-field full-span">
                                    <label for="current_password">Current Password</label>
                                    <input
                                        type="password"
                                        id="current_password"
                                        name="current_password"
                                        autocomplete="current-password"
                                        required
                                    >
                                    <small>Enter your current password to authorize username or password updates.</small>
                                </div>

                                <div class="admin-account-field">
                                    <label for="new_username">New Username</label>
                                    <input
                                        type="text"
                                        id="new_username"
                                        name="new_username"
                                        autocomplete="username"
                                        placeholder="Leave blank to keep current username"
                                    >
                                    <small>Allowed: letters, numbers, periods, underscores, and hyphens.</small>
                                </div>

                                <div class="admin-account-field">
                                    <label for="new_password">New Password</label>
                                    <input
                                        type="password"
                                        id="new_password"
                                        name="new_password"
                                        autocomplete="new-password"
                                        placeholder="Leave blank to keep current password"
                                    >
                                    <small>Use at least <?php echo (int)$adminCredentialMinLength; ?> characters for stronger protection.</small>
                                </div>

                                <div class="admin-account-field full-span">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input
                                        type="password"
                                        id="confirm_password"
                                        name="confirm_password"
                                        autocomplete="new-password"
                                        placeholder="Repeat the new password only if you are changing it"
                                    >
                                    <small>Only required when you enter a new password.</small>
                                </div>
                            </div>

                            <div class="admin-account-note">
                                Changing the username updates the current admin session too, so you can continue working without logging in again.
                            </div>
                        </div>

                        <div class="settings-actions">
                            <button type="submit" name="update_admin_account" value="1" class="btn btn-bulk" style="padding:8px 14px; font-size:11px;">
                                <i class="fas fa-user-edit"></i> Save Admin Credentials
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="section-heading">
            <h2><i class="fas fa-chart-bar"></i> Account Overview</h2>
            <span>Realtime Snapshot</span>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label">Pending Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">Approved Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['rejected'] ?? 0; ?></div>
                <div class="stat-label">Rejected Accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo array_sum($stats); ?></div>
                <div class="stat-label">Total Accounts</div>
            </div>
        </div>

        <div class="section-heading">
            <h2><i class="fas fa-random"></i> Program Shift Oversight</h2>
            <span><?php echo (int)$shiftTotalRecords; ?> request<?php echo (int)$shiftTotalRecords === 1 ? '' : 's'; ?></span>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$programShiftStats['pending_adviser']; ?></div>
                <div class="stat-label">Pending Adviser</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$programShiftStats['pending_current_coordinator'] + (int)$programShiftStats['pending_destination_coordinator'] + (int)$programShiftStats['pending_coordinator']; ?></div>
                <div class="stat-label">Pending Coordinator</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$programShiftStats['approved']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$programShiftStats['rejected']; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo (int)$programShiftStats['total']; ?></div>
                <div class="stat-label">Total Shift Requests</div>
            </div>
        </div>
        <div class="settings-card">
            <?php if (empty($programShiftRecent)): ?>
                <p class="card-note" style="margin-bottom:0;">No program shift requests found yet.</p>
            <?php else: ?>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>Request</th>
                                <th>Student</th>
                                <th>From Program</th>
                                <th>To Program</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Last Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($programShiftRecentPage as $request): ?>
                                <?php
                                    $status = (string)($request['status'] ?? '');
                                    $statusClass = str_replace('_', '-', strtolower($status));
                                    $statusLabel = ucwords(str_replace('_', ' ', $status));
                                    $lastActionAt = (string)($request['executed_at'] ?: ($request['coordinator_action_at'] ?: ($request['adviser_action_at'] ?: '')));
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$request['request_code']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars((string)$request['student_name']); ?><br>
                                        <span class="audit-meta"><?php echo htmlspecialchars((string)$request['student_number']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$request['current_program']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$request['requested_program']); ?></td>
                                    <td><span class="shift-status-pill <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string)$request['requested_at']))); ?></td>
                                    <td>
                                        <?php if ($lastActionAt !== ''): ?>
                                            <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($lastActionAt))); ?>
                                        <?php else: ?>
                                            <span class="audit-meta">Awaiting action</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo aasRenderMiniPagination('shift_page', $shiftCurrentPage, $shiftTotalPages); ?>
            <?php endif; ?>
        </div>

        <div class="section-heading">
            <h2><i class="fas fa-clipboard-list"></i> Admin Audit Trail</h2>
            <span><?php echo (int)$auditTotalRecords; ?> actions</span>
        </div>
        <div class="settings-card">
            <?php if (empty($auditLogs)): ?>
                <p class="card-note" style="margin-bottom:0;">No audit entries yet. Setting changes and account actions will appear here.</p>
            <?php else: ?>
                <div class="audit-table-wrap">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Admin</th>
                                <th>Type</th>
                                <th>Target</th>
                                <th>Summary</th>
                                <th>Metadata</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogsPage as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string)$log['created_at']))); ?></td>
                                    <td><?php echo htmlspecialchars((string)$log['admin_id']); ?></td>
                                    <td><span class="audit-pill"><?php echo htmlspecialchars((string)$log['action_type']); ?></span></td>
                                    <td><?php echo htmlspecialchars((string)$log['action_target']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$log['summary']); ?></td>
                                    <td>
                                        <div class="audit-meta"><?php echo htmlspecialchars((string)($log['metadata_json'] ?? '')); ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo aasRenderMiniPagination('audit_page', $auditCurrentPage, $auditTotalPages); ?>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');

            sidebar.classList.toggle('collapsed');
        }

        window.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');

            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        });

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');

            if (window.innerWidth > 768) {
                sidebar.classList.remove('collapsed');
            } else {
                sidebar.classList.add('collapsed');
            }
        });

        // Real-time search with debounce
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        const searchForm = document.getElementById('searchForm');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    searchForm.submit();
                }, 500);
            });
        }

        const masterlistModal = document.getElementById('masterlistModal');
        const openMasterlistModalButton = document.getElementById('openMasterlistModal');
        const closeMasterlistModalButton = document.getElementById('closeMasterlistModal');
        const masterlistModalSearch = document.getElementById('masterlistModalSearch');
        const masterlistModalRows = Array.from(document.querySelectorAll('[data-masterlist-row]'));
        const masterlistModalNoResults = document.getElementById('masterlistModalNoResults');
        const masterlistModalEmptyRow = document.getElementById('masterlistModalEmptyRow');

        if (masterlistModal && masterlistModal.parentElement !== document.body) {
            document.body.appendChild(masterlistModal);
        }

        function setMasterlistModalOpen(isOpen) {
            if (!masterlistModal) {
                return;
            }

            masterlistModal.hidden = !isOpen;
            masterlistModal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            document.body.style.overflow = isOpen ? 'hidden' : '';

            if (isOpen && masterlistModalSearch) {
                masterlistModalSearch.value = '';
                masterlistModalSearch.focus();
            }
        }

        function filterMasterlistModalRows() {
            if (!masterlistModalSearch) {
                return;
            }

            const query = masterlistModalSearch.value.trim().toLowerCase();
            let visibleCount = 0;

            masterlistModalRows.forEach((row) => {
                const haystack = String(row.getAttribute('data-search') || '').toLowerCase();
                const match = query === '' || haystack.includes(query);
                row.hidden = !match;
                if (match) {
                    visibleCount += 1;
                }
            });

            if (masterlistModalNoResults) {
                if (masterlistModalRows.length === 0 || masterlistModalEmptyRow) {
                    masterlistModalNoResults.hidden = true;
                    return;
                }
                masterlistModalNoResults.hidden = visibleCount !== 0;
            }
        }

        if (openMasterlistModalButton) {
            openMasterlistModalButton.addEventListener('click', () => {
                setMasterlistModalOpen(true);
                filterMasterlistModalRows();
            });
        }

        if (closeMasterlistModalButton) {
            closeMasterlistModalButton.addEventListener('click', () => {
                setMasterlistModalOpen(false);
            });
        }

        if (masterlistModal) {
            masterlistModal.addEventListener('click', (event) => {
                if (event.target === masterlistModal) {
                    setMasterlistModalOpen(false);
                }
            });
        }

        if (masterlistModalSearch) {
            masterlistModalSearch.addEventListener('input', filterMasterlistModalRows);
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && masterlistModal && !masterlistModal.hidden) {
                setMasterlistModalOpen(false);
            }
        });

        function toggleAll() {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const studentCheckboxes = document.querySelectorAll('.student-checkbox');
            
            studentCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        }

        function selectAll() {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            selectAllCheckbox.checked = true;
            toggleAll();
        }

        function bulkApprove() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one account to approve.');
                return;
            }
            
            if (confirm(`Are you sure you want to approve ${selected.length} selected account(s)?`)) {
                const form = document.getElementById('bulkForm');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_action';
                input.value = 'approve_selected';
                form.appendChild(input);
                form.submit();
            }
        }

        function bulkReject() {
            const selected = document.querySelectorAll('.student-checkbox:checked');
            if (selected.length === 0) {
                alert('Please select at least one account to reject.');
                return;
            }
            
            if (confirm(`Are you sure you want to reject ${selected.length} selected account(s)?`)) {
                const form = document.getElementById('bulkForm');
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_action';
                input.value = 'reject_selected';
                form.appendChild(input);
                form.submit();
            }
        }

        // Auto-submit form when toggle is changed
        const autoApproveToggle = document.getElementById('auto_approve');
        if (autoApproveToggle) {
            autoApproveToggle.addEventListener('change', function() {
            const form = document.getElementById('settingsForm');
            const toggleState = this.checked ? 'enable' : 'disable';
            const isEnabling = this.checked;
            
            // Create and show custom confirmation modal
            showToggleConfirmation(toggleState, isEnabling, (confirmed) => {
                if (confirmed) {
                    // Show loading state
                    const toggleSwitch = this.closest('.toggle-switch');
                    toggleSwitch.style.opacity = '0.5';
                    toggleSwitch.style.pointerEvents = 'none';
                    
                    // Submit the form
                    form.submit();
                } else {
                    // Revert the toggle if cancelled
                    this.checked = !this.checked;
                }
            });
            });
        }

        function showToggleConfirmation(action, isEnabling, callback) {
            // Create modal overlay
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.style.display = 'block';
            modal.style.zIndex = '3000';
            
            // Modal content based on action
            const title = isEnabling ? 'Enable Auto-Approval?' : 'Disable Auto-Approval?';
            const icon = isEnabling ? 'fas fa-toggle-on' : 'fas fa-toggle-off';
            const iconColor = isEnabling ? '#4CAF50' : '#ff9800';
            const message = isEnabling ? 
                'New student accounts will be <strong>automatically approved</strong> and can login immediately after registration.' :
                'New student accounts will <strong>require manual approval</strong> before they can login.';
            const actionText = isEnabling ? 'Enable Auto-Approval' : 'Disable Auto-Approval';
            const buttonColor = isEnabling ? '#4CAF50' : '#ff9800';
            const consequence = isEnabling ? 
                '<div style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); padding: 15px; border-radius: 10px; margin: 15px 0; border-left: 4px solid #28a745;"><i class="fas fa-check-circle" style="color: #28a745; margin-right: 8px;"></i><strong>All pending accounts will be automatically approved.</strong></div>' : 
                '<div style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); padding: 15px; border-radius: 10px; margin: 15px 0; border-left: 4px solid #ffc107;"><i class="fas fa-exclamation-triangle" style="color: #856404; margin-right: 8px;"></i><strong>Pending accounts will remain pending until manually approved.</strong></div>';
            
            modal.innerHTML = `
                <div class="modal-container" style="min-width: 500px; animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
                    <div class="modal-icon" style="color: ${iconColor}; font-size: 72px; margin-bottom: 25px; animation: pulse 2s infinite;">
                        <i class="${icon}"></i>
                    </div>
                    <div class="modal-title" style="font-size: 28px; margin-bottom: 20px; color: #333; font-weight: 700;">
                        ${title}
                    </div>
                    <div style="margin: 25px 0; color: #555; font-size: 17px; line-height: 1.6; text-align: left;">
                        <div style="margin-bottom: 15px;">${message}</div>
                        ${consequence}
                    </div>
                    <div style="margin-top: 35px; display: flex; gap: 20px; justify-content: center;">
                        <button onclick="confirmToggle(true)" class="confirm-btn confirm-btn-primary" style="
                            background: linear-gradient(135deg, ${buttonColor} 0%, ${isEnabling ? '#45a049' : '#e68900'} 100%);
                            color: white;
                            border: none;
                            border-radius: 30px;
                            padding: 15px 35px;
                            font-size: 16px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.3s ease;
                            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                            position: relative;
                            overflow: hidden;
                        ">
                            <i class="fas fa-check" style="margin-right: 8px;"></i>${actionText}
                        </button>
                        <button onclick="confirmToggle(false)" class="confirm-btn confirm-btn-secondary" style="
                            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
                            color: white;
                            border: none;
                            border-radius: 30px;
                            padding: 15px 35px;
                            font-size: 16px;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.3s ease;
                            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
                            position: relative;
                            overflow: hidden;
                        ">
                            <i class="fas fa-times" style="margin-right: 8px;"></i>Cancel
                        </button>
                    </div>
                </div>
                <style>
                    @keyframes pulse {
                        0% { transform: scale(1); }
                        50% { transform: scale(1.1); }
                        100% { transform: scale(1); }
                    }
                    
                    .confirm-btn::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: -100%;
                        width: 100%;
                        height: 100%;
                        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
                        transition: left 0.5s;
                    }
                    
                    .confirm-btn:hover::before {
                        left: 100%;
                    }
                    
                    .confirm-btn:hover {
                        transform: translateY(-3px);
                        box-shadow: 0 8px 25px rgba(0,0,0,0.25);
                    }
                    
                    .confirm-btn:active {
                        transform: translateY(-1px);
                        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                    }
    </style>
            `;

            document.body.appendChild(modal);
            
            // Animate modal in
            const container = modal.querySelector('.modal-container');
            setTimeout(() => container.classList.add('active'), 10);

            // Handle click outside to close
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    confirmToggle(false);
                }
            });

            // Add keyboard support
            const handleKeyPress = (e) => {
                if (e.key === 'Escape') {
                    confirmToggle(false);
                } else if (e.key === 'Enter') {
                    confirmToggle(true);
                }
            };
            document.addEventListener('keydown', handleKeyPress);

            // Store callback for button clicks and cleanup function
            window.confirmToggleCallback = callback;
            window.confirmToggleCleanup = () => {
                document.removeEventListener('keydown', handleKeyPress);
            };
        }

        function confirmToggle(confirmed) {
            const modal = document.querySelector('.modal-overlay[style*="z-index: 3000"]');
            if (modal) {
                const container = modal.querySelector('.modal-container');
                
                // Enhanced exit animation
                container.style.animation = 'modalSlideOut 0.3s cubic-bezier(0.55, 0.055, 0.675, 0.19)';
                modal.style.transition = 'opacity 0.3s ease';
                modal.style.opacity = '0';
                
                setTimeout(() => {
                    modal.remove();
                    
                    // Cleanup keyboard listener
                    if (window.confirmToggleCleanup) {
                        window.confirmToggleCleanup();
                        delete window.confirmToggleCleanup;
                    }
                    
                    // Execute callback
                    if (window.confirmToggleCallback) {
                        window.confirmToggleCallback(confirmed);
                        delete window.confirmToggleCallback;
                    }
                }, 300);
            }
        }
    </script>
</body>
</html>









