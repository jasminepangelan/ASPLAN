<!-- batch_update -->
<?php
// SECURITY: Restrict to admin and program coordinator users
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/security_policy_enforce.php';
require_once __DIR__ . '/includes/laravel_bridge.php';

// Require admin or program coordinator authentication
$auth = requireRole(['admin', 'program_coordinator']);

// Log batch operation
logSecurityEvent('batch_update_initiated', [
    'user_id' => $auth['user_id'],
    'role' => $auth['role'],
], 'info');

// Suppress errors in production
if (getenv('APP_ENV') === 'production') {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

function acronymFromPhrase($text) {
    $cleaned = strtoupper(trim((string)$text));
    if ($cleaned === '') {
        return '';
    }

    $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    $tokens = explode(' ', $cleaned);
    $skip = ['OF', 'IN', 'AND', 'THE', 'A', 'AN', 'MAJOR', 'PROGRAM'];
    $result = '';

    foreach ($tokens as $token) {
        if ($token === '' || in_array($token, $skip, true)) {
            continue;
        }
        $result .= substr($token, 0, 1);
    }

    return $result;
}

function normalizeProgramKey($programName) {
    $programName = trim((string)$programName);
    if ($programName === '') {
        return '';
    }

    $normalized = strtoupper(preg_replace('/\s+/', ' ', $programName));

    if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN|ABENG|ABPSYCH|ABCOMM)\b/', $normalized, $codeMatch)) {
        $baseCode = strtoupper($codeMatch[1]);
    } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
        $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
        $baseCode = 'BS' . acronymFromPhrase($subject);
    } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
        $baseCode = 'BSED';
    } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
        $baseCode = 'BEED';
    } elseif (strpos($normalized, 'BACHELOR OF SCIENCE') !== false) {
        $subject = trim(str_replace('BACHELOR OF SCIENCE', '', $normalized));
        $baseCode = 'BS' . acronymFromPhrase($subject);
    } elseif (strpos($normalized, 'BACHELOR OF ARTS') !== false) {
        $subject = trim(str_replace('BACHELOR OF ARTS', '', $normalized));
        $baseCode = 'AB' . acronymFromPhrase($subject);
    } else {
        $baseCode = strtoupper($programName);
    }

    $majorKey = '';
    if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
        $majorKey = acronymFromPhrase($majorMatch[1]);
    }

    if ($majorKey !== '') {
        return $baseCode . '-' . $majorKey;
    }

    return $baseCode;
}

function getProgramScopedAdvisers(PDO $conn, $selectedProgram) {
    $stmt = $conn->prepare('SELECT id, username, TRIM(program) AS program FROM adviser');
    $stmt->execute();

    $byUsername = [];
    $ids = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $programKey = normalizeProgramKey($row['program']);
        if ($selectedProgram !== '' && $programKey !== $selectedProgram) {
            continue;
        }

        $id = (int)$row['id'];
        $username = trim((string)$row['username']);
        if ($id <= 0 || $username === '') {
            continue;
        }

        $ids[] = $id;
        $byUsername[$username] = $id;
    }

    return ['ids' => $ids, 'by_username' => $byUsername];
}

function deleteScopedBatchAssignments(PDO $conn, $batch, array $adviserIds) {
    if (empty($adviserIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($adviserIds), '?'));
    $sql = "DELETE FROM adviser_batch WHERE batch = ? AND adviser_id IN ($placeholders)";
    $params = array_merge([$batch], $adviserIds);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
}

function batchExistsForProgram(PDO $conn, $batch, $selectedProgram) {
    $batch = trim((string)$batch);
    $selectedProgram = trim((string)$selectedProgram);
    if ($batch === '' || $selectedProgram === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT DISTINCT TRIM(program) AS program FROM student_info WHERE LEFT(student_number, 4) = ?");
    $stmt->execute([$batch]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (normalizeProgramKey((string)($row['program'] ?? '')) === $selectedProgram) {
            return true;
        }
    }

    return false;
}

function resolveRedirectTarget($value) {
    $allowed = [
        'admin/adviser_management.php',
        'program_coordinator/adviser_management.php'
    ];
    $candidate = trim((string)$value);
    if (in_array($candidate, $allowed, true)) {
        return $candidate;
    }
    return 'admin/adviser_management.php';
}

function resolveCoordinatorProgramKey(PDO $conn, $username) {
    $username = trim((string)$username);
    if ($username === '') {
        return '';
    }

    $tables = ['program_coordinator', 'program_coordinators'];
    foreach ($tables as $table) {
        $tableCheck = $conn->prepare("SHOW TABLES LIKE ?");
        $tableCheck->execute([$table]);
        if (!$tableCheck->fetchColumn()) {
            continue;
        }

        $columnCheck = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE 'program'");
        $columnCheck->execute();
        if (!$columnCheck->fetchColumn()) {
            continue;
        }

        $stmt = $conn->prepare("SELECT TRIM(program) AS program FROM `$table` WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['program'])) {
            $normalized = normalizeProgramKey((string)$row['program']);
            if ($normalized !== '') {
                return $normalized;
            }
        }
    }

    $fallback = $conn->prepare("SELECT TRIM(program) AS program FROM adviser WHERE username = ? LIMIT 1");
    $fallback->execute([$username]);
    $fallbackRow = $fallback->fetch(PDO::FETCH_ASSOC);
    if ($fallbackRow && isset($fallbackRow['program'])) {
        return normalizeProgramKey((string)$fallbackRow['program']);
    }

    return '';
}

$redirectTarget = resolveRedirectTarget($_POST['redirect_to'] ?? '');

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgePayload = $_POST;
    $bridgePayload['bridge_authorized'] = true;
    $bridgePayload['user_type'] = $_SESSION['user_type'] ?? '';
    $bridgePayload['username'] = $_SESSION['username'] ?? '';

    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser-management/batch-update',
        $bridgePayload
    );

    if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
        $queryKey = !empty($bridgeData['success']) ? 'message' : 'error';
        $redirect = (string) ($bridgeData['redirect_to'] ?? ($redirectTarget . '?' . $queryKey . '=' . urlencode((string) ($bridgeData['message'] ?? 'Batch update complete.'))));
        header("Location: {$redirect}");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['batch'])) {
    header("Location: {$redirectTarget}?error=" . urlencode("Invalid request."));
    exit();
}

try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $batch = trim($_POST['batch']);
    $selectedProgram = isset($_POST['selected_program']) ? normalizeProgramKey((string)$_POST['selected_program']) : '';

    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator') {
        $redirectTarget = 'program_coordinator/adviser_management.php';
        $selectedProgram = resolveCoordinatorProgramKey($conn, $_SESSION['username'] ?? '');
        if ($selectedProgram === '') {
            header("Location: {$redirectTarget}?error=" . urlencode("Program is not configured for your coordinator account."));
            exit();
        }
    }

    $programQuery = $selectedProgram !== '' ? '&program=' . urlencode($selectedProgram) : '';
    $scopedAdvisers = getProgramScopedAdvisers($conn, $selectedProgram);
    $scopedAdviserIds = $scopedAdvisers['ids'];
    $scopedByUsername = $scopedAdvisers['by_username'];

    if (isset($_POST['unassign_batch'])) {
        // Unassign only advisers within the selected program for this batch.
        if ($selectedProgram === '') {
            $del = $conn->prepare("DELETE FROM adviser_batch WHERE batch = ?");
            $del->execute([$batch]);
        } else {
            deleteScopedBatchAssignments($conn, $batch, $scopedAdviserIds);
        }
        header("Location: {$redirectTarget}?message=" . urlencode("All advisers unassigned from batch $batch.") . $programQuery);
        exit();
    }

    if (isset($_POST['direct_submit'])) {
        if (
            isset($_SESSION['user_type'])
            && $_SESSION['user_type'] === 'program_coordinator'
            && !batchExistsForProgram($conn, $batch, $selectedProgram)
        ) {
            header("Location: {$redirectTarget}?error=" . urlencode("Batch $batch does not belong to your program or has no existing students.") . $programQuery);
            exit();
        }

        // Remove current assignments scoped to the selected program.
        if ($selectedProgram === '') {
            $del = $conn->prepare("DELETE FROM adviser_batch WHERE batch = ?");
            $del->execute([$batch]);
        } else {
            deleteScopedBatchAssignments($conn, $batch, $scopedAdviserIds);
        }

        // If advisers were selected, assign them
        if (isset($_POST['advisers']) && is_array($_POST['advisers']) && !empty($_POST['advisers'])) {
            $ins = $conn->prepare("INSERT INTO adviser_batch (adviser_id, batch) VALUES (?, ?)");
            $successCount = 0;

            $uniqueUsernames = array_values(array_unique(array_map(function ($u) {
                return trim((string)$u);
            }, $_POST['advisers'])));

            foreach ($uniqueUsernames as $username) {
                $username = trim((string)$username);
                if ($username === '' || !isset($scopedByUsername[$username])) {
                    continue;
                }

                $adviserId = $scopedByUsername[$username];
                if ($adviserId > 0) {
                    try {
                        $ins->execute([$adviserId, $batch]);
                        $successCount++;
                    } catch (PDOException $e) {
                        // Ignore duplicate advisor-batch pairs and continue.
                        if ((string)$e->getCode() !== '23000') {
                            throw $e;
                        }
                    }
                }
            }

            header("Location: {$redirectTarget}?message=" . urlencode("Assigned $successCount adviser(s) to batch $batch.") . $programQuery);
            exit();
        }

        header("Location: {$redirectTarget}?message=" . urlencode("All advisers removed from batch $batch.") . $programQuery);
        exit();
    }

    header("Location: {$redirectTarget}?error=" . urlencode("Invalid request.") . $programQuery);
    exit();
} catch (PDOException $e) {
    error_log("batch_update error: " . $e->getMessage());
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator') {
        $redirectTarget = 'program_coordinator/adviser_management.php';
    }
    $selectedProgram = isset($_POST['selected_program']) ? normalizeProgramKey((string)$_POST['selected_program']) : '';
    $programQuery = $selectedProgram !== '' ? '&program=' . urlencode($selectedProgram) : '';
    header("Location: {$redirectTarget}?error=" . urlencode("An error occurred while updating assignments.") . $programQuery);
    exit();
}
?>
