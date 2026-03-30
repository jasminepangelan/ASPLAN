<?php
/**
 * Database Configuration
 * Central database connection file - include this instead of duplicating connection code
 * 
 * NOTE: Database migrated from e_checklist to osas_db on March 4, 2026
 * See dev/migrations/migrate_e_checklist_to_osas_db.sql for migration script
 */

// Load environment variables from .env file
require_once __DIR__ . '/../includes/env_loader.php';

if (!function_exists('firstEnvValue')) {
    function readEnvValue($key) {
        $value = getenv($key);
        if ($value !== false && $value !== null && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        return '';
    }

    function normalizeEnvValue($value) {
        if ($value === false || $value === null) {
            return '';
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
            $value = trim($value);
        }

        if (preg_match('/^\$\{\{.+\}\}$/', $value)) {
            return '';
        }

        return $value;
    }

    function firstEnvValue(array $keys, $default = '') {
        foreach ($keys as $key) {
            $value = normalizeEnvValue(readEnvValue($key));
            if ($value !== '') {
                return $value;
            }
        }

        return normalizeEnvValue($default);
    }
}

if (!function_exists('parseDatabaseUrlFallback')) {
    function parseDatabaseUrlFallback()
    {
        $url = firstEnvValue(['DATABASE_URL', 'MYSQL_URL', 'MYSQL_PUBLIC_URL'], '');
        if ($url === '') {
            return [];
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return [];
        }

        return [
            'host' => $parts['host'] ?? '',
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'user' => $parts['user'] ?? '',
            'pass' => $parts['pass'] ?? '',
            'name' => isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '',
        ];
    }
}

$parsedDatabaseUrl = parseDatabaseUrlFallback();

// Database credentials - loaded from environment variables.
// Railway always exposes MYSQL* variables on the database service, so we
// fall back to those automatically when custom DB_* variables are missing.
define('DB_HOST', firstEnvValue(['DB_HOST', 'MYSQLHOST'], $parsedDatabaseUrl['host'] ?? 'localhost'));
define('DB_PORT', (int) firstEnvValue(['DB_PORT', 'MYSQLPORT'], $parsedDatabaseUrl['port'] ?? '3306'));
define('DB_USER', firstEnvValue(['DB_USER', 'DB_USERNAME', 'MYSQLUSER'], $parsedDatabaseUrl['user'] ?? 'root'));
define('DB_PASS', firstEnvValue(['DB_PASS', 'DB_PASSWORD', 'MYSQLPASSWORD'], $parsedDatabaseUrl['pass'] ?? ''));
define('DB_NAME', firstEnvValue(['DB_NAME', 'DB_DATABASE', 'MYSQLDATABASE'], $parsedDatabaseUrl['name'] ?? 'osas_db'));

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}

if (!class_exists('LegacyDbResult')) {
    class LegacyDbResult
    {
        public $num_rows = 0;
        private $rows = [];
        private $index = 0;

        public function __construct(array $rows)
        {
            $this->rows = array_values($rows);
            $this->num_rows = count($this->rows);
        }

        public function fetch_assoc()
        {
            if ($this->index >= $this->num_rows) {
                return null;
            }

            return $this->rows[$this->index++];
        }

        public function fetch_all($mode = MYSQLI_ASSOC)
        {
            return $this->rows;
        }

        public function free()
        {
            $this->rows = [];
            $this->num_rows = 0;
            $this->index = 0;
        }
    }
}

if (!class_exists('LegacyDbStatement')) {
    class LegacyDbStatement
    {
        public $num_rows = 0;
        public $affected_rows = 0;
        public $error = '';

        private $pdoStatement;
        private $connection;
        private $boundValues = [];
        private $resultRows = null;
        private $executed = false;

        public function __construct(PDOStatement $pdoStatement, LegacyDbConnection $connection)
        {
            $this->pdoStatement = $pdoStatement;
            $this->connection = $connection;
        }

        public function bind_param($types, ...$values)
        {
            $this->boundValues = array_values($values);
            return true;
        }

        public function execute()
        {
            try {
                foreach ($this->boundValues as $index => $value) {
                    $paramType = PDO::PARAM_STR;
                    if (is_int($value)) {
                        $paramType = PDO::PARAM_INT;
                    } elseif (is_bool($value)) {
                        $paramType = PDO::PARAM_BOOL;
                    } elseif ($value === null) {
                        $paramType = PDO::PARAM_NULL;
                    }

                    $this->pdoStatement->bindValue($index + 1, $value, $paramType);
                }

                $this->pdoStatement->execute();
                $this->executed = true;
                $this->affected_rows = $this->pdoStatement->rowCount();

                if ($this->pdoStatement->columnCount() > 0) {
                    $this->resultRows = $this->pdoStatement->fetchAll(PDO::FETCH_ASSOC);
                    $this->num_rows = count($this->resultRows);
                } else {
                    $this->resultRows = [];
                    $this->num_rows = 0;
                    $this->connection->setAffectedRows($this->affected_rows);
                    $this->connection->setInsertId((int) $this->connection->getPdo()->lastInsertId());
                }

                return true;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                $this->connection->setError($this->error);
                return false;
            }
        }

        public function get_result()
        {
            if (!$this->executed) {
                $this->execute();
            }

            return new LegacyDbResult($this->resultRows ?? []);
        }

        public function store_result()
        {
            if (!$this->executed) {
                $this->execute();
            }

            return true;
        }

        public function close()
        {
            $this->pdoStatement = null;
        }
    }
}

if (!class_exists('LegacyDbConnection')) {
    class LegacyDbConnection
    {
        public $connect_error = '';
        public $error = '';
        public $insert_id = 0;
        public $affected_rows = 0;

        private $pdo;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function getPdo()
        {
            return $this->pdo;
        }

        public function setError($message)
        {
            $this->error = (string) $message;
        }

        public function setInsertId($id)
        {
            $this->insert_id = (int) $id;
        }

        public function setAffectedRows($rows)
        {
            $this->affected_rows = (int) $rows;
        }

        public function set_charset($charset)
        {
            try {
                $this->pdo->exec('SET NAMES ' . $this->pdo->quote((string) $charset));
                return true;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function prepare($sql)
        {
            try {
                $stmt = $this->pdo->prepare($sql);
                if (!$stmt) {
                    $this->error = 'Failed to prepare statement.';
                    return false;
                }

                return new LegacyDbStatement($stmt, $this);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function query($sql)
        {
            try {
                $stmt = $this->pdo->query($sql);
                if ($stmt === false) {
                    $this->error = 'Query failed.';
                    return false;
                }

                if ($stmt->columnCount() > 0) {
                    return new LegacyDbResult($stmt->fetchAll(PDO::FETCH_ASSOC));
                }

                $this->affected_rows = $stmt->rowCount();
                $this->insert_id = (int) $this->pdo->lastInsertId();
                return true;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }

        public function begin_transaction()
        {
            return $this->pdo->beginTransaction();
        }

        public function commit()
        {
            return $this->pdo->commit();
        }

        public function rollback()
        {
            return $this->pdo->rollBack();
        }

        public function real_escape_string($value)
        {
            return substr($this->pdo->quote((string) $value), 1, -1);
        }

        public function close()
        {
            $this->pdo = null;
        }
    }
}

if (!function_exists('createPdoFallbackConnection')) {
    function createPdoFallbackConnection()
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);

        return new LegacyDbConnection($pdo);
    }
}

/**
 * Get database connection
 * @return mysqli Database connection object
 */
function getDBConnection() {
    try {
        if (class_exists('mysqli')) {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

            if (!$conn->connect_error) {
                $conn->set_charset("utf8mb4");
                return $conn;
            }

            error_log("MySQLi connection failed, falling back to PDO: " . $conn->connect_error . ' (host=' . DB_HOST . ', port=' . DB_PORT . ', db=' . DB_NAME . ', user=' . DB_USER . ')');
        }

        return createPdoFallbackConnection();
    } catch (Throwable $e) {
        error_log("Database connection failed: " . $e->getMessage() . ' (host=' . DB_HOST . ', port=' . DB_PORT . ', db=' . DB_NAME . ', user=' . DB_USER . ')');
        die(json_encode([
            'status' => 'error', 
            'message' => 'Database connection failed. Please try again later.'
        ]));
    }
}

/**
 * Close database connection
 * @param mysqli $conn Database connection object
 */
function closeDBConnection($conn) {
    if ($conn && $conn instanceof mysqli) {
        $conn->close();
        return;
    }

    if ($conn && method_exists($conn, 'close')) {
        $conn->close();
    }
}
