<?php
/**
 * Apply Program Shift Schema Migration
 *
 * Run from CLI:
 *   php dev/migrations/apply_program_shift_schema.php
 *   php dev/migrations/apply_program_shift_schema.php --check
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/program_shift_schema.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
}

$argvList = $_SERVER['argv'] ?? [];
$checkOnly = in_array('--check', $argvList, true);

echo "=============================================================\n";
echo "  PROGRAM SHIFT SCHEMA MIGRATION\n";
echo "=============================================================\n\n";

$conn = getDBConnection();

try {
    $issues = psProgramShiftSchemaIssues($conn);

    echo "Current schema status:\n";
    if (empty($issues)) {
        echo "  OK - Program shift schema is ready.\n";
    } else {
        foreach ($issues as $issue) {
            echo "  - {$issue}\n";
        }
    }
    echo "\n";

    if ($checkOnly) {
        closeDBConnection($conn);
        exit(empty($issues) ? 0 : 1);
    }

    if (empty($issues)) {
        echo "No migration needed.\n";
        closeDBConnection($conn);
        exit(0);
    }

    $result = psRunProgramShiftSchemaMigration($conn);

    echo "Executed steps:\n";
    foreach ($result['executed_steps'] as $step) {
        echo "  - {$step}\n";
    }

    echo "\nMigration completed successfully.\n";
    closeDBConnection($conn);
    exit(0);
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    closeDBConnection($conn);
    exit(1);
}
