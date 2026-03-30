<?php
/**
 * Database Query Optimization Service
 * Provides cached queries, batch operations, and N+1 prevention
 */

require_once __DIR__ . '/error_logging_service.php';

// Query cache timeout (5 minutes)
define('QOPT_CACHE_TTL', 300);
// Maximum cache entries
define('QOPT_CACHE_MAX', 50);

/**
 * Initialize query cache in session
 */
function qoptInitialize(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['_qopt_cache'])) {
        $_SESSION['_qopt_cache'] = [];
        $_SESSION['_qopt_cache_times'] = [];
    }
}

/**
 * Get cached query result
 */
function qoptGetCached(string $cacheKey): ?array
{
    qoptInitialize();

    if (!isset($_SESSION['_qopt_cache'][$cacheKey])) {
        return null;
    }

    $cached = $_SESSION['_qopt_cache'][$cacheKey];
    $cacheTime = $_SESSION['_qopt_cache_times'][$cacheKey] ?? 0;

    if (time() - $cacheTime > QOPT_CACHE_TTL) {
        unset($_SESSION['_qopt_cache'][$cacheKey]);
        unset($_SESSION['_qopt_cache_times'][$cacheKey]);
        return null;
    }

    elsDebug("Query cache HIT: $cacheKey");
    return $cached;
}

/**
 * Set query cache
 */
function qoptSetCache(string $cacheKey, array $data): void
{
    qoptInitialize();

    // Limit cache size
    if (count($_SESSION['_qopt_cache']) >= QOPT_CACHE_MAX) {
        $firstKey = array_key_first($_SESSION['_qopt_cache']);
        if ($firstKey) {
            unset($_SESSION['_qopt_cache'][$firstKey]);
            unset($_SESSION['_qopt_cache_times'][$firstKey]);
        }
    }

    $_SESSION['_qopt_cache'][$cacheKey] = $data;
    $_SESSION['_qopt_cache_times'][$cacheKey] = time();
}

/**
 * Clear query cache for specific key(s)
 */
function qoptClearCache(string $pattern = null): void
{
    qoptInitialize();

    if (!$pattern) {
        $_SESSION['_qopt_cache'] = [];
        $_SESSION['_qopt_cache_times'] = [];
        return;
    }

    foreach (array_keys($_SESSION['_qopt_cache']) as $key) {
        if (strpos($key, $pattern) !== false) {
            unset($_SESSION['_qopt_cache'][$key]);
            unset($_SESSION['_qopt_cache_times'][$key]);
        }
    }
}

/**
 * Get student by student_number (cached)
 */
function qoptGetStudent($conn, string $studentNumber, bool $useCache = true): ?array
{
    $cacheKey = "student_$studentNumber";

    if ($useCache) {
        $cached = qoptGetCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    $stmt = $conn->prepare("SELECT * FROM student_info WHERE student_number = ?");
    if (!$stmt) {
        elsError("Query preparation failed: " . $conn->error);
        return null;
    }

    $stmt->bind_param("s", $studentNumber);
    if (!$stmt->execute()) {
        elsError("Query execution failed: " . $stmt->error);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    $stmt->close();

    if ($student && $useCache) {
        qoptSetCache($cacheKey, $student);
    }

    return $student;
}

/**
 * Get students in batch (prevents N+1)
 */
function qoptGetStudentsBatch($conn, array $studentNumbers): array
{
    if (empty($studentNumbers)) {
        return [];
    }

    // Filter to only uncached students
    $toFetch = [];
    $cached = [];

    foreach ($studentNumbers as $num) {
        $cacheKey = "student_$num";
        $c = qoptGetCached($cacheKey);
        if ($c) {
            $cached[$num] = $c;
        } else {
            $toFetch[] = $num;
        }
    }

    $results = $cached;

    if (empty($toFetch)) {
        return $results;
    }

    // Fetch uncached students
    $placeholders = implode(',', array_fill(0, count($toFetch), '?'));
    $stmt = $conn->prepare("SELECT * FROM student_info WHERE student_number IN ($placeholders)");

    if (!$stmt) {
        elsError("Batch query preparation failed: " . $conn->error);
        return $results;
    }

    $types = str_repeat('s', count($toFetch));
    $stmt->bind_param($types, ...$toFetch);

    if (!$stmt->execute()) {
        elsError("Batch query execution failed: " . $stmt->error);
        $stmt->close();
        return $results;
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $studentNum = $row['student_number'];
        $results[$studentNum] = $row;
        qoptSetCache("student_$studentNum", $row);
    }

    $stmt->close();
    return $results;
}

/**
 * Get adviser by ID (cached)
 */
function qoptGetAdviser($conn, $adviserId, bool $useCache = true): ?array
{
    $cacheKey = "adviser_$adviserId";

    if ($useCache) {
        $cached = qoptGetCached($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
    }

    $stmt = $conn->prepare("SELECT * FROM adviser WHERE id = ?");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $adviserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $adviser = $result->fetch_assoc();
    $stmt->close();

    if ($adviser && $useCache) {
        qoptSetCache($cacheKey, $adviser);
    }

    return $adviser;
}

/**
 * Get count for paginated queries
 */
function qoptGetCountOnly($conn, string $query, array $params = [], array $types = []): int
{
    if (!strpos($query, 'COUNT(*)')) {
        $query = preg_replace('/SELECT\s+.+?\s+FROM/i', 'SELECT COUNT(*) AS cnt FROM', $query);
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 0;
    }

    if (!empty($params) && $types) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return (int)($row['cnt'] ?? $row['total'] ?? 0);
}

/**
 * Batch insert with duplicate key update
 */
function qoptBatchInsertOrUpdate(
    $conn,
    string $table,
    array $records,
    array $updateFields = null
): int {
    if (empty($records)) {
        return 0;
    }

    // Build column list
    $columns = array_keys($records[0]);
    $columnList = implode(',', $columns);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));

    // Build values for all records
    $allValues = [];
    $allTypes = '';

    foreach ($records as $record) {
        foreach ($columns as $col) {
            $allValues[] = $record[$col] ?? null;
            $allTypes .= is_int($record[$col] ?? null) ? 'i' : 's';
        }
    }

    $valueRows = implode('),(', array_fill(0, count($records), $placeholders));

    // Build UPDATE clause if specified
    $updateClause = '';
    if ($updateFields) {
        $updateParts = [];
        foreach ($updateFields as $field) {
            $updateParts[] = "$field = VALUES($field)";
        }
        $updateClause = ' ON DUPLICATE KEY UPDATE ' . implode(',', $updateParts);
    }

    $query = "INSERT INTO $table ($columnList) VALUES ($valueRows)$updateClause";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        elsError("Batch insert prepare failed: " . $conn->error);
        return 0;
    }

    if (!$stmt->bind_param($allTypes, ...$allValues)) {
        elsError("Batch insert bind failed: " . $stmt->error);
        $stmt->close();
        return 0;
    }

    if (!$stmt->execute()) {
        elsError("Batch insert execute failed: " . $stmt->error);
        $stmt->close();
        return 0;
    }

    $affectedRows = $conn->affected_rows;
    $stmt->close();

    // Clear related cache
    qoptClearCache($table);

    return $affectedRows;
}

/**
 * Batch update
 */
function qoptBatchUpdate(
    $conn,
    string $table,
    array $updates,
    string $keyColumn = 'id'
): int {
    if (empty($updates)) {
        return 0;
    }

    // Build multi-row UPDATE
    $whereConditions = [];
    $updateValues = [];
    $types = '';

    foreach ($updates as $id => $record) {
        foreach ($record as $col => $val) {
            $updateValues[] = $val;
            $types .= is_int($val) ? 'i' : 's';
        }
        $whereConditions[] = $id;
        $types .= 'i';
    }

    if (count($updates) === 1) {
        // Single row update
        $record = reset($updates);
        $id = key($updates);
        $setClauses = [];

        foreach ($record as $col => $val) {
            $setClauses[] = "$col = ?";
        }

        $query = "UPDATE $table SET " . implode(',', $setClauses) . " WHERE $keyColumn = ?";
        $stmt = $conn->prepare($query);

        if (!$stmt) {
            elsError("Update prepare failed: " . $conn->error);
            return 0;
        }

        $params = array_values($record);
        $params[] = $id;
        $paramTypes = '';

        foreach ($params as $p) {
            $paramTypes .= is_int($p) ? 'i' : 's';
        }

        $stmt->bind_param($paramTypes, ...$params);

        if (!$stmt->execute()) {
            elsError("Update execute failed: " . $stmt->error);
            $stmt->close();
            return 0;
        }

        $affectedRows = $conn->affected_rows;
        $stmt->close();
    } else {
        // Multiple rows - split into individual updates
        $affectedRows = 0;

        foreach ($updates as $id => $record) {
            $setClauses = [];
            foreach ($record as $col => $val) {
                $setClauses[] = "$col = ?";
            }

            $query = "UPDATE $table SET " . implode(',', $setClauses) . " WHERE $keyColumn = ?";
            $stmt = $conn->prepare($query);

            if ($stmt) {
                $params = array_values($record);
                $params[] = $id;
                $paramTypes = '';

                foreach ($params as $p) {
                    $paramTypes .= is_int($p) ? 'i' : 's';
                }

                $stmt->bind_param($paramTypes, ...$params);

                if ($stmt->execute()) {
                    $affectedRows += $conn->affected_rows;
                }

                $stmt->close();
            }
        }
    }

    // Clear related cache
    qoptClearCache($table);

    return $affectedRows;
}

/**
 * Get cache statistics
 */
function qoptGetCacheStats(): array
{
    qoptInitialize();

    $now = time();
    $validEntries = 0;
    $expiredEntries = 0;

    foreach ($_SESSION['_qopt_cache_times'] as $key => $time) {
        if ($now - $time > QOPT_CACHE_TTL) {
            $expiredEntries++;
        } else {
            $validEntries++;
        }
    }

    return [
        'valid_entries' => $validEntries,
        'expired_entries' => $expiredEntries,
        'total_entries' => count($_SESSION['_qopt_cache']),
        'cache_max_size' => QOPT_CACHE_MAX,
        'cache_ttl' => QOPT_CACHE_TTL
    ];
}
?>
