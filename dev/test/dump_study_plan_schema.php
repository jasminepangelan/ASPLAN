<?php
require_once __DIR__ . '/../../config/config.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$tables = ['student_info', 'student_checklists', 'program_shift_requests'];
$conn = getDBConnection();

foreach ($tables as $table) {
    echo '===' . $table . "===\n";

    $exists = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    if (!$exists || $exists->num_rows === 0) {
        echo "MISSING\n";
        continue;
    }

    $result = $conn->query('SHOW COLUMNS FROM ' . $table);
    if (!$result) {
        echo "ERROR\n";
        continue;
    }

    while ($row = $result->fetch_assoc()) {
        $field = (string)($row['Field'] ?? '');
        $type = (string)($row['Type'] ?? '');
        $nullable = (string)($row['Null'] ?? '');
        $default = $row['Default'];
        $extra = (string)($row['Extra'] ?? '');
        $key = (string)($row['Key'] ?? '');

        echo $field
            . '|' . $type
            . '|' . $nullable
            . '|' . ($default === null ? 'NULL' : (string)$default)
            . '|' . $extra
            . '|' . $key
            . "\n";
    }
}

closeDBConnection($conn);
