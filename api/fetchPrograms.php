<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';

/**
 * Gradual migration bridge.
 * Set USE_LARAVEL_BRIDGE=1 in .env to route this endpoint to Laravel sidecar.
 */
$useLaravelBridge = getenv('USE_LARAVEL_BRIDGE') === '1';

if ($useLaravelBridge) {
    $bridgeUrl = laravelBridgeUrl('/api/fetch-programs');
    $response = @file_get_contents($bridgeUrl);

    if ($response !== false) {
        echo $response;
        exit;
    }
}

// Get database connection
$conn = getDBConnection();

$programs = [];

// Fetch programs from the database with schema fallbacks.
$queries = [
    "SELECT DISTINCT name FROM programs WHERE name IS NOT NULL AND name <> '' ORDER BY name",
    "SELECT DISTINCT programs AS name FROM cvsucarmona_courses WHERE programs IS NOT NULL AND programs <> '' ORDER BY programs",
    "SELECT DISTINCT program AS name FROM student_info WHERE program IS NOT NULL AND program <> '' ORDER BY program",
];

foreach ($queries as $sql) {
    try {
        $result = $conn->query($sql);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $name = trim((string)($row['name'] ?? ''));
                if ($name !== '') {
                    $programs[$name] = true;
                }
            }

            if (!empty($programs)) {
                break;
            }
        }
    } catch (Throwable $e) {
        // Try next fallback source when this table/column is unavailable.
    }
}

$programs = array_keys($programs);

closeDBConnection($conn);

// Return programs as a JSON response
echo json_encode(['programs' => $programs]);
?>
