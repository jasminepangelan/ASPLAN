<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$expectedToken = trim((string) getenv('IMPORT_DB_TOKEN'));
$providedToken = trim((string) ($_SERVER['HTTP_X_IMPORT_TOKEN'] ?? $_GET['token'] ?? ''));

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

set_time_limit(0);
ini_set('memory_limit', '512M');

$sqlPath = __DIR__ . '/../dev/osas_db_041626.sql';
if (!is_file($sqlPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SQL dump not found']);
    exit;
}

$host = trim((string) getenv('DB_HOST'));
$port = (int) (getenv('DB_PORT') ?: 3306);
$database = trim((string) (getenv('DB_NAME') ?: getenv('DB_DATABASE')));
$username = trim((string) (getenv('DB_USER') ?: getenv('DB_USERNAME')));
$password = (string) (getenv('DB_PASS') ?: getenv('DB_PASSWORD'));

if ($host === '' || $database === '' || $username === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database environment is incomplete']);
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $username, $password, $database, $port);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

$conn->set_charset('utf8mb4');
$sql = file_get_contents($sqlPath);
if (!is_string($sql) || trim($sql) === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SQL dump is empty']);
    exit;
}

$startedAt = microtime(true);
$conn->query('SET FOREIGN_KEY_CHECKS=0');

if (!$conn->multi_query($sql)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $conn->error]);
    $conn->close();
    exit;
}

$statements = 0;
do {
    $statements++;
    if ($result = $conn->store_result()) {
        $result->free();
    }
} while ($conn->more_results() && $conn->next_result());

if ($conn->errno) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $conn->error, 'statements_processed' => $statements]);
    $conn->close();
    exit;
}

$conn->query('SET FOREIGN_KEY_CHECKS=1');

$tableCount = 0;
$countResult = $conn->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE()");
if ($countResult) {
    $row = $countResult->fetch_assoc();
    $tableCount = (int) ($row['table_count'] ?? 0);
    $countResult->free();
}

$sampleCounts = [];
foreach (['admin', 'adviser', 'curriculum_courses', 'student_info', 'programs'] as $table) {
    $safeTable = str_replace('`', '``', $table);
    $result = $conn->query("SELECT COUNT(*) AS row_count FROM `$safeTable`");
    if ($result) {
        $row = $result->fetch_assoc();
        $sampleCounts[$table] = (int) ($row['row_count'] ?? 0);
        $result->free();
    }
}

$conn->close();

echo json_encode([
    'ok' => true,
    'database' => $database,
    'tables' => $tableCount,
    'sample_counts' => $sampleCounts,
    'statements_processed' => $statements,
    'duration_seconds' => round(microtime(true) - $startedAt, 2),
]);
