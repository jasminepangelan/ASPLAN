<?php
/**
 * Migration Runner: e_checklist to osas_db
 * 
 * This script runs the SQL migration and provides a summary of changes
 * Run this from the command line: php run_migration.php
 * Or access via browser (not recommended for production)
 * 
 * IMPORTANT: Run this ONCE only. Backup your databases before running.
 */

// Check if running from CLI
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre>";
}

echo "==============================================\n";
echo "Migration: e_checklist → osas_db\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

// Database connection
$host = 'localhost';
$user = 'root';
$pass = '';

try {
    // Connect without selecting a database
    $conn = new mysqli($host, $user, $pass);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    echo "[✓] Connected to MySQL server\n\n";
    
    // Check if both databases exist
    $result = $conn->query("SHOW DATABASES LIKE 'e_checklist'");
    if ($result->num_rows === 0) {
        throw new Exception("Source database 'e_checklist' does not exist!");
    }
    echo "[✓] Source database 'e_checklist' found\n";
    
    $result = $conn->query("SHOW DATABASES LIKE 'osas_db'");
    if ($result->num_rows === 0) {
        // Create osas_db if it doesn't exist
        $conn->query("CREATE DATABASE `osas_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        echo "[!] Target database 'osas_db' created\n";
    } else {
        echo "[✓] Target database 'osas_db' found\n";
    }
    
    echo "\n--- Pre-Migration Record Counts (e_checklist) ---\n";
    
    // Get record counts from source
    $conn->select_db('e_checklist');
    $tables = ['admins', 'adviser', 'batches', 'students', 'checklist_bscs', 
               'student_checklists', 'password_resets', 'pre_enrollments', 
               'programs', 'system_settings'];
    
    $sourceCounts = [];
    foreach ($tables as $table) {
        $result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        if ($result) {
            $row = $result->fetch_assoc();
            $sourceCounts[$table] = $row['cnt'];
            echo "  $table: {$row['cnt']} records\n";
        } else {
            $sourceCounts[$table] = 0;
            echo "  $table: 0 records (table may not exist)\n";
        }
    }
    
    echo "\n--- Running Migration Script ---\n";
    
    // Read and execute the migration SQL file
    $migrationFile = __DIR__ . '/migrate_e_checklist_to_osas_db.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split SQL by semicolons (accounting for multi-line statements)
    $conn->multi_query($sql);
    
    // Process all results
    $statementCount = 0;
    $errorCount = 0;
    $errors = [];
    
    do {
        $statementCount++;
        if ($result = $conn->store_result()) {
            $result->free();
        }
        if ($conn->error) {
            $errorCount++;
            $errors[] = "Statement $statementCount: " . $conn->error;
        }
    } while ($conn->more_results() && $conn->next_result());
    
    echo "  Executed $statementCount SQL statements\n";
    
    if ($errorCount > 0) {
        echo "  [!] $errorCount errors occurred:\n";
        foreach (array_slice($errors, 0, 5) as $error) {
            echo "      - $error\n";
        }
        if (count($errors) > 5) {
            echo "      ... and " . (count($errors) - 5) . " more errors\n";
        }
    } else {
        echo "  [✓] No errors\n";
    }
    
    echo "\n--- Post-Migration Record Counts (osas_db) ---\n";
    
    // Reconnect to check results (multi_query can leave connection in bad state)
    $conn->close();
    $conn = new mysqli($host, $user, $pass, 'osas_db');
    
    $targetTables = [
        'admin' => 'admins',
        'adviser' => 'adviser',
        'batches' => 'batches',
        'student_info' => 'students',
        'curriculum_courses' => 'checklist_bscs',
        'student_checklists' => 'student_checklists',
        'password_resets' => 'password_resets',
        'pre_enrollments' => 'pre_enrollments',
        'programs' => 'programs',
        'system_settings' => 'system_settings'
    ];
    
    foreach ($targetTables as $targetTable => $sourceTable) {
        $result = $conn->query("SELECT COUNT(*) as cnt FROM `$targetTable`");
        if ($result) {
            $row = $result->fetch_assoc();
            $status = ($row['cnt'] >= ($sourceCounts[$sourceTable] ?? 0)) ? '✓' : '!';
            echo "  [$status] $targetTable: {$row['cnt']} records (from $sourceTable)\n";
        } else {
            echo "  [✗] $targetTable: Error - " . $conn->error . "\n";
        }
    }
    
    echo "\n==============================================\n";
    echo "Migration Complete!\n";
    echo "==============================================\n";
    echo "\nNext Steps:\n";
    echo "1. The database config has been updated to use 'osas_db'\n";
    echo "2. Test your application thoroughly\n";
    echo "3. Keep 'e_checklist' database as backup\n";
    echo "4. Review CODE_CHANGES_REQUIRED.md for field name changes\n";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "\n[✗] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$isCLI) {
    echo "</pre>";
}
