<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/laravel_bridge.php';

if (!isset($_SESSION['username']) || (isset($_SESSION['user_type']) && $_SESSION['user_type'] !== 'program_coordinator')) {
    header('Location: ../index.html');
    exit();
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

    if (strpos($normalized, 'INFORMATION TECHNOLOGY') !== false) {
        return 'BSIT';
    }
    if (strpos($normalized, 'INDUSTRIAL TECHNOLOGY') !== false) {
        return 'BSINDTECH';
    }

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

function parseProgramList($programRaw) {
    $programRaw = trim((string)$programRaw);
    if ($programRaw === '') {
        return [];
    }

    $parts = preg_split('/\s*,\s*/', $programRaw);
    $normalized = [];
    foreach ($parts as $part) {
        $key = normalizeProgramKey($part);
        if ($key !== '') {
            $normalized[] = $key;
        }
    }

    return array_values(array_unique($normalized, SORT_STRING));
}

function normalizeBatchPrefix($batchRaw) {
    $batchRaw = trim((string)$batchRaw);
    if ($batchRaw === '') {
        return '';
    }

    if (preg_match('/(\d{4})/', $batchRaw, $match)) {
        return (string)$match[1];
    }

    return '';
}

function determineRetentionStatus(int $failed, int $total): string {
    if ($total === 0) {
        return 'None';
    }

    $failureRate = ($failed / $total) * 100;
    if ($failureRate >= 75) {
        return 'Disqualification';
    }
    if ($failureRate >= 51) {
        return 'Probation';
    }
    if ($failureRate >= 30) {
        return 'Warning';
    }

    return 'None';
}

function loadCoordinatorPrograms(mysqli $db, string $username): array {
    $username = trim($username);
    if ($username === '') {
        return [];
    }

    $tables = ['program_coordinator', 'program_coordinators'];
    foreach ($tables as $table) {
        $tableSafe = $db->real_escape_string($table);
        $tableResult = $db->query("SHOW TABLES LIKE '$tableSafe'");
        if (!$tableResult || $tableResult->num_rows === 0) {
            continue;
        }

        $columnResult = $db->query("SHOW COLUMNS FROM `$tableSafe` LIKE 'program'");
        if (!$columnResult || $columnResult->num_rows === 0) {
            continue;
        }

        $stmt = $db->prepare("SELECT TRIM(program) AS program FROM `$tableSafe` WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return parseProgramList((string)($row['program'] ?? ''));
            }
            $stmt->close();
        }
    }

    $fallback = $db->prepare("SELECT TRIM(program) AS program FROM adviser WHERE username = ? LIMIT 1");
    if ($fallback) {
        $fallback->bind_param('s', $username);
        $fallback->execute();
        $result = $fallback->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $fallback->close();
            return parseProgramList((string)($row['program'] ?? ''));
        }
        $fallback->close();
    }

    return [];
}

function isProgramInScope(string $program, array $coordinatorProgramKeys): bool {
    $normalized = normalizeProgramKey($program);
    return $normalized !== '' && in_array($normalized, $coordinatorProgramKeys, true);
}

function loadStudentRetentionAndFails(mysqli $db, array $studentIds): array {
    if (empty($studentIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $types = str_repeat('s', count($studentIds));
    $query = "SELECT student_id AS student_number,
                     COUNT(*) AS total_courses,
                     SUM(CASE
                         WHEN TRIM(final_grade) IN ('5.00','5.0','5','4.00','4.0','4','INC','DRP','F','W') THEN 1
                         WHEN TRIM(final_grade) REGEXP '^[0-9]+(\\.[0-9]+)?$' AND CAST(final_grade AS DECIMAL(5,2)) > 3.0 THEN 1
                         ELSE 0
                     END) AS failed_courses
              FROM student_checklists
              WHERE student_id IN ($placeholders)
              GROUP BY student_id";

    $stmt = $db->prepare($query);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$studentIds);
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $studentNumber = (string)($row['student_number'] ?? '');
            $failed = isset($row['failed_courses']) ? (int)$row['failed_courses'] : 0;
            $total = isset($row['total_courses']) ? (int)$row['total_courses'] : 0;
            $summary[$studentNumber] = [
                'failed_courses' => $failed,
                'total_courses' => $total,
            ];
        }
    }

    $stmt->close();
    return $summary;
}

function buildCoordinatorReportRows(mysqli $db, array $coordinatorProgramKeys, string $search, string $batch): array {
    $rows = [];
    $search = trim($search);
    $batch = normalizeBatchPrefix($batch);

    $baseQuery = "SELECT student_number, last_name, first_name, middle_name, program, status, stud_classification, reg_status, year_level, general_weighted_average
                  FROM student_info
                  WHERE TRIM(program) IS NOT NULL";
    $params = [];
    $types = '';

    if ($batch !== '') {
        $baseQuery .= " AND LEFT(CAST(student_number AS CHAR), 4) = ?";
        $params[] = $batch;
        $types .= 's';
    }
    if ($search !== '') {
        $baseQuery .= " AND (CAST(student_number AS CHAR) LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ? OR program LIKE ? )";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }
    $baseQuery .= ' ORDER BY last_name, first_name';

    $stmt = $db->prepare($baseQuery);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $program = trim((string)($row['program'] ?? ''));
                if (!isProgramInScope($program, $coordinatorProgramKeys)) {
                    continue;
                }

                $id = (string)($row['student_number'] ?? '');
                $rows[$id] = [
                    'student_number' => $id,
                    'last_name' => trim((string)($row['last_name'] ?? '')),
                    'first_name' => trim((string)($row['first_name'] ?? '')),
                    'middle_name' => trim((string)($row['middle_name'] ?? '')),
                    'program' => $program,
                    'batch' => substr($id, 0, 4),
                    'account_status' => trim((string)($row['status'] ?? '')) ?: 'Registered',
                    'masterlist_only' => 'No',
                    'classification' => trim((string)($row['stud_classification'] ?? '')),
                    'reg_status' => trim((string)($row['reg_status'] ?? '')),
                    'year_level' => trim((string)($row['year_level'] ?? '')),
                    'general_weighted_average' => trim((string)($row['general_weighted_average'] ?? '')),
                    'failed_courses' => 0,
                    'total_courses' => 0,
                    'retention_status' => 'None',
                ];
            }
        }
        $stmt->close();
    }

    $existingIds = array_keys($rows);
    $masterQuery = "SELECT student_number, last_name, first_name, middle_name, program, year_level, reg_status
                    FROM student_masterlist
                    WHERE TRIM(program) IS NOT NULL";
    $params = [];
    $types = '';
    if ($batch !== '') {
        $masterQuery .= " AND LEFT(student_number, 4) = ?";
        $params[] = $batch;
        $types .= 's';
    }
    if ($search !== '') {
        $masterQuery .= " AND (student_number LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR middle_name LIKE ? OR program LIKE ? )";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }
    $masterQuery .= ' ORDER BY last_name, first_name';

    $stmt = $db->prepare($masterQuery);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $id = trim((string)($row['student_number'] ?? ''));
                if ($id === '' || isset($rows[$id])) {
                    continue;
                }
                $program = trim((string)($row['program'] ?? ''));
                if (!isProgramInScope($program, $coordinatorProgramKeys)) {
                    continue;
                }

                $rows[$id] = [
                    'student_number' => $id,
                    'last_name' => trim((string)($row['last_name'] ?? '')),
                    'first_name' => trim((string)($row['first_name'] ?? '')),
                    'middle_name' => trim((string)($row['middle_name'] ?? '')),
                    'program' => $program,
                    'batch' => substr($id, 0, 4),
                    'account_status' => 'No account',
                    'masterlist_only' => 'Yes',
                    'classification' => '',
                    'reg_status' => trim((string)($row['reg_status'] ?? '')),
                    'year_level' => trim((string)($row['year_level'] ?? '')),
                    'general_weighted_average' => '',
                    'failed_courses' => 0,
                    'total_courses' => 0,
                    'retention_status' => 'None',
                ];
            }
        }
        $stmt->close();
    }

    $studentIds = array_keys($rows);
    $courseSummary = loadStudentRetentionAndFails($db, $studentIds);
    foreach ($rows as $studentId => $studentRow) {
        $summary = $courseSummary[$studentId] ?? ['failed_courses' => 0, 'total_courses' => 0];
        $rows[$studentId]['failed_courses'] = $summary['failed_courses'];
        $rows[$studentId]['total_courses'] = $summary['total_courses'];
        $rows[$studentId]['retention_status'] = determineRetentionStatus($summary['failed_courses'], $summary['total_courses']);
    }

    return array_values($rows);
}

$search = trim((string)($_GET['search'] ?? ''));
$batch = normalizeBatchPrefix((string)($_GET['batch'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$username = (string)$_SESSION['username'];

$db = getDBConnection();
$db->set_charset('utf8mb4');
$coordinatorPrograms = loadCoordinatorPrograms($db, $username);
if (empty($coordinatorPrograms)) {
    closeDBConnection($db);
    http_response_code(403);
    echo 'Program is not configured for this coordinator account.';
    exit();
}

$rows = buildCoordinatorReportRows($db, $coordinatorPrograms, $search, $batch);
closeDBConnection($db);

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pc_student_report.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Student ID',
        'Last Name',
        'First Name',
        'Middle Name',
        'Program',
        'Batch',
        'Account Status',
        'Masterlist Only',
        'Classification',
        'Registration Status',
        'Year Level',
        'GWA',
        'Failed Courses',
        'Total Courses',
        'Retention Status'
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['student_number'] ?? '',
            $r['last_name'] ?? '',
            $r['first_name'] ?? '',
            $r['middle_name'] ?? '',
            $r['program'] ?? '',
            $r['batch'] ?? '',
            $r['account_status'] ?? '',
            $r['masterlist_only'] ?? '',
            $r['classification'] ?? '',
            $r['reg_status'] ?? '',
            $r['year_level'] ?? '',
            $r['general_weighted_average'] ?? '',
            $r['failed_courses'] ?? 0,
            $r['total_courses'] ?? 0,
            $r['retention_status'] ?? '',
        ]);
    }
    fclose($out);
    exit();
}

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Program Coordinator Student Report</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color:#1b3b1b; }
        .header { display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px; gap:12px; }
        h1 { margin:0; font-size:18px; }
        .meta { font-size:13px; margin-top:4px; }
        table { width:100%; border-collapse:collapse; border:1px solid #ddd; }
        th, td { padding:8px; border:1px solid #eee; font-size:12px; }
        th { background:#f1f9f1; text-align:left; }
        .no-data { text-align:center; color:#666; }
        @media print { .no-print { display:none; } }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Program Coordinator Student Report</h1>
            <div class="meta">Program scope: <?php echo htmlspecialchars(implode(', ', $coordinatorPrograms)); ?></div>
            <div class="meta">Filters: <?php echo ($search !== '' ? 'search=' . htmlspecialchars($search) : '') . ($batch !== '' ? ' batch=' . htmlspecialchars($batch) : ''); ?></div>
            <div class="meta">Generated: <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?></div>
        </div>
        <div class="no-print">
            <button onclick="window.print();">Print / Save as PDF</button>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>"><button>Download CSV</button></a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Last Name</th>
                <th>First Name</th>
                <th>Middle Name</th>
                <th>Program</th>
                <th>Batch</th>
                <th>Account Status</th>
                <th>Masterlist Only</th>
                <th>Classification</th>
                <th>Registration Status</th>
                <th>Year Level</th>
                <th>GWA</th>
                <th>Failed Courses</th>
                <th>Total Courses</th>
                <th>Retention Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td class="no-data" colspan="15">No students found for selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($r['student_number'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['last_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['first_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['middle_name'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['program'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['batch'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['account_status'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['masterlist_only'] ?? 'No')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['classification'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['reg_status'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['year_level'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['general_weighted_average'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['failed_courses'] ?? '0')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['total_courses'] ?? '0')); ?></td>
                        <td><?php echo htmlspecialchars((string)($r['retention_status'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
