<?php
/**
 * Automatic Migration Script - Batch Update
 * This script will automatically update multiple PHP files to use the new config system
 * 
 * BACKUP YOUR FILES BEFORE RUNNING THIS!
 * 
 * Usage: php batch_migrate.php
 */

echo "=================================================\n";
echo "PEAS Batch Migration Script\n";
echo "=================================================\n\n";

// Files to migrate (add more as needed)
$filesToMigrate = [
    'save_profile.php',
    'save_checklist.php',
    'save_checklist_stud.php',
    'save_pre_enrollment.php',
    'get_checklist_data.php',
    'get_enrollment_details.php',
    'get_transaction_history.php',
    'change_password.php',
    'approve_account_admin.php',
    'approve_account_adviser.php',
    'reject_admin.php',
    'reject_adviser.php',
    'list_of_students.php',
    'fetchPrograms.php',
    'savePrograms.php',
];

// Patterns to replace
// @phpstan-ignore-next-line - These are regex patterns for finding old code, not actual code
$oldPatterns = [
    // Pattern 1: Standard mysqli connection
    [
        'old' => "/\\/\\/ Database connection.*?\n.*?\\$host = ['\"]localhost['\"];.*?\n.*?\\$username = ['\"]root['\"];.*?\n.*?\\$password = ['\"]['\"]{0,};.*?\n.*?\\$database = ['\"]e_checklist['\"];.*?\n.*?\n.*?\\$conn = new mysqli\\(\\$host, \\$username, \\$password, \\$database\\);.*?\n.*?\n.*?if \\(\\$conn->connect_error\\) \\{.*?\n.*?die\\([^)]+\\);.*?\n.*?\\}/s",
        'new' => "require_once __DIR__ . '/config/config.php';\n\n// Get database connection\n\$conn = getDBConnection();"
    ],
    // Pattern 2: Simplified version
    [
        'old' => "/\\$host = ['\"]localhost['\"];.*?\n.*?\\$(?:user|username) = ['\"]root['\"];.*?\n.*?\\$(?:pass|password) = ['\"]['\"]{0,};.*?\n.*?\\$(?:db|database) = ['\"]e_checklist['\"];.*?\n.*?\\$conn = new mysqli\\([^)]+\\);/s",
        'new' => "require_once __DIR__ . '/config/config.php';\n\$conn = getDBConnection();"
    ],
    // Pattern 3: Just the connection line with variables
    [
        'old' => "/\\$conn = new mysqli\\(\\$host, \\$(?:user|username), \\$(?:pass|password), \\$(?:db|database)\\);/",
        'new' => "\$conn = getDBConnection();"
    ],
    // Pattern 4: Close connection
    [
        // @phpstan-ignore-next-line - This is a regex pattern for finding old code
        'old' => "/\\$conn->close\\(\\);/",
        'new' => "closeDBConnection(\$conn);"
    ],
];

$migratedCount = 0;
$skippedCount = 0;
$errorCount = 0;

foreach ($filesToMigrate as $filename) {
    $filepath = __DIR__ . '/' . $filename;
    
    if (!file_exists($filepath)) {
        echo "⚠️  SKIP: $filename (file not found)\n";
        $skippedCount++;
        continue;
    }
    
    $content = file_get_contents($filepath);
    $originalContent = $content;
    
    // Check if already migrated
    if (strpos($content, "require_once __DIR__ . '/config/config.php'") !== false ||
        strpos($content, 'getDBConnection()') !== false) {
        echo "✓  SKIP: $filename (already migrated)\n";
        $skippedCount++;
        continue;
    }
    
    // Apply replacements
    $modified = false;
    foreach ($oldPatterns as $pattern) {
        if (preg_match($pattern['old'], $content)) {
            $content = preg_replace($pattern['old'], $pattern['new'], $content);
            $modified = true;
        }
    }
    
    if ($modified && $content !== $originalContent) {
        // Create backup
        $backupFile = $filepath . '.backup';
        file_put_contents($backupFile, $originalContent);
        
        // Write new content
        if (file_put_contents($filepath, $content)) {
            echo "✅ MIGRATED: $filename (backup created)\n";
            $migratedCount++;
        } else {
            echo "❌ ERROR: $filename (failed to write)\n";
            $errorCount++;
        }
    } else {
        echo "⚠️  NO CHANGE: $filename (no patterns matched)\n";
        $skippedCount++;
    }
}

echo "\n=================================================\n";
echo "MIGRATION SUMMARY\n";
echo "=================================================\n";
echo "✅ Migrated: $migratedCount files\n";
echo "⚠️  Skipped: $skippedCount files\n";
echo "❌ Errors: $errorCount files\n";
echo "=================================================\n\n";

if ($migratedCount > 0) {
    echo "NEXT STEPS:\n";
    echo "1. Test your application thoroughly\n";
    echo "2. If everything works, delete .backup files\n";
    echo "3. If there are issues, restore from .backup files\n";
    echo "4. Run migration_helper.php to check remaining files\n\n";
}

echo "Done!\n";
?>
