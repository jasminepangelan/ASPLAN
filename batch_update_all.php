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
logSecurityEvent('batch_update_all_initiated', [
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

function loadProgramBatches(PDO $conn, $selectedProgram) {
    $selectedProgram = trim((string)$selectedProgram);
    if ($selectedProgram === '') {
        return [];
    }

    $stmt = $conn->prepare("SELECT DISTINCT LEFT(student_number, 4) AS batch, TRIM(program) AS program FROM student_info WHERE student_number IS NOT NULL AND student_number != ''");
    $stmt->execute();

    $batches = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batch = trim((string)($row['batch'] ?? ''));
        if ($batch === '') {
            continue;
        }

        if (normalizeProgramKey((string)($row['program'] ?? '')) === $selectedProgram) {
            $batches[$batch] = true;
        }
    }

    return array_keys($batches);
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

$selectedProgram = isset($_POST['selected_program']) ? normalizeProgramKey((string)$_POST['selected_program']) : '';
$redirectTarget = resolveRedirectTarget($_POST['redirect_to'] ?? '');
$programQuery = $selectedProgram !== '' ? '&program=' . urlencode($selectedProgram) : '';

if (getenv('USE_LARAVEL_BRIDGE') === '1') {
    $bridgePayload = $_POST;
    $bridgePayload['bridge_authorized'] = true;
    $bridgePayload['user_type'] = $_SESSION['user_type'] ?? '';
    $bridgePayload['username'] = $_SESSION['username'] ?? '';

    $bridgeData = postLaravelJsonBridge(
        'http://localhost/ASPLAN_v10/laravel-app/public/api/adviser-management/batch-update-all',
        $bridgePayload
    );

    if (is_array($bridgeData) && array_key_exists('success', $bridgeData)) {
        $queryKey = !empty($bridgeData['success']) ? 'message' : 'error';
        $redirect = (string) ($bridgeData['redirect_to'] ?? ($redirectTarget . '?' . $queryKey . '=' . urlencode((string) ($bridgeData['message'] ?? 'Batch update complete.'))));
        header("Location: {$redirect}");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['assignments_json'])) {
    header('Location: ' . $redirectTarget . '?error=' . urlencode('Invalid bulk update request.') . $programQuery);
    exit();
}

$assignments = json_decode($_POST['assignments_json'], true);
if (!is_array($assignments) || empty($assignments)) {
    header('Location: ' . $redirectTarget . '?error=' . urlencode('No batch assignments were provided.') . $programQuery);
    exit();
}

try {
    $conn = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator') {
        $redirectTarget = 'program_coordinator/adviser_management.php';
        $selectedProgram = resolveCoordinatorProgramKey($conn, $_SESSION['username'] ?? '');
        if ($selectedProgram === '') {
            header('Location: ' . $redirectTarget . '?error=' . urlencode('Program is not configured for your coordinator account.'));
            exit();
        }
        $programQuery = '&program=' . urlencode($selectedProgram);

        $allowedBatches = array_flip(loadProgramBatches($conn, $selectedProgram));
        $assignments = array_filter(
            $assignments,
            function ($batch) use ($allowedBatches) {
                return isset($allowedBatches[trim((string)$batch)]);
            },
            ARRAY_FILTER_USE_KEY
        );

        if (empty($assignments)) {
            header('Location: ' . $redirectTarget . '?error=' . urlencode('No valid student batches were provided for your program.') . $programQuery);
            exit();
        }
    }

    $conn->beginTransaction();

    $deleteStmt = $conn->prepare('DELETE FROM adviser_batch WHERE batch = ?');
    $insertStmt = $conn->prepare('INSERT INTO adviser_batch (adviser_id, batch) VALUES (?, ?)');
    $scopedAdvisers = getProgramScopedAdvisers($conn, $selectedProgram);
    $scopedAdviserIds = $scopedAdvisers['ids'];
    $scopedByUsername = $scopedAdvisers['by_username'];

    $updatedBatches = 0;
    $totalAssignments = 0;

    foreach ($assignments as $batch => $usernames) {
        $batch = trim((string)$batch);
        if ($batch === '') {
            continue;
        }

        if ($selectedProgram === '') {
            $deleteStmt->execute([$batch]);
        } else {
            deleteScopedBatchAssignments($conn, $batch, $scopedAdviserIds);
        }
        $updatedBatches++;

        if (!is_array($usernames) || empty($usernames)) {
            continue;
        }

        $uniqueUsernames = array_values(array_unique(array_map(function ($u) {
            return trim((string)$u);
        }, $usernames)));

        foreach ($uniqueUsernames as $username) {
            $username = trim((string)$username);
            if ($username === '') {
                continue;
            }

            if (isset($scopedByUsername[$username])) {
                try {
                    $insertStmt->execute([$scopedByUsername[$username], $batch]);
                    $totalAssignments++;
                } catch (PDOException $e) {
                    // Ignore duplicate advisor-batch pairs and continue.
                    if ((string)$e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            }
        }
    }

    $conn->commit();

    if ($updatedBatches === 0) {
        header('Location: ' . $redirectTarget . '?error=' . urlencode('No valid batches were updated.') . $programQuery);
        exit();
    }

    $message = "Updated {$updatedBatches} batch(es) with {$totalAssignments} adviser assignment(s).";
    header('Location: ' . $redirectTarget . '?message=' . urlencode($message) . $programQuery);
    exit();
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('batch_update_all error: ' . $e->getMessage());
    error_log('batch_update_all trace: ' . $e->getTraceAsString());
    
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'program_coordinator') {
        $redirectTarget = 'program_coordinator/adviser_management.php';
    }

    // Provide detailed error message based on error code
    $errorMessage = 'An error occurred while updating all batch assignments.';
    
    // Log the full error for debugging
    $debugMsg = 'Technical details: ' . $e->getCode() . ' - ' . $e->getMessage();
    error_log($debugMsg);
    
    header('Location: ' . $redirectTarget . '?error=' . urlencode($errorMessage) . $programQuery);
    exit();
}
