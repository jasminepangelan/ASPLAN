#!/usr/bin/env php
<?php
/**
 * Migration Helper Script
 * This script helps identify which PHP files need to be updated to use the new config system
 * 
 * Run this from command line: php migration_helper.php
 * Or just open it in a browser: http://localhost/PEAS/migration_helper.php
 */

echo "=================================================\n";
echo "PEAS Configuration Migration Helper\n";
echo "=================================================\n\n";

// Files to scan
$directory = __DIR__;
$phpFiles = glob($directory . '/*.php');

// Patterns to look for (old database connection code)
$patterns = [
    'old_connection' => '/\$conn\s*=\s*new\s+mysqli\s*\(/i',
    'hardcoded_host' => '/\$host\s*=\s*[\'"]localhost[\'"]/i',
    'hardcoded_user' => '/\$user\s*=\s*[\'"]root[\'"]/i',
    'hardcoded_db' => '/\$db\s*=\s*[\'"]e_checklist[\'"]/i',
    'smtp_credentials' => '/\$mail->Username\s*=\s*[\'"][^\'"]*/i',
];

// Files that need migration
$needsMigration = [];
$emailFiles = [];

echo "Scanning PHP files...\n\n";

foreach ($phpFiles as $file) {
    $filename = basename($file);
    
    // Skip config files and this migration helper
    if (strpos($filename, 'config') !== false || 
        $filename === 'migration_helper.php' ||
        strpos($filename, 'PHPMailer') !== false) {
        continue;
    }
    
    $content = file_get_contents($file);
    $issues = [];
    
    // Check for old connection patterns
    foreach ($patterns as $type => $pattern) {
        if (preg_match($pattern, $content)) {
            if ($type === 'smtp_credentials') {
                $emailFiles[] = $filename;
            } else {
                $issues[] = $type;
            }
        }
    }
    
    if (!empty($issues)) {
        $needsMigration[$filename] = $issues;
    }
}

// Display results
echo "FILES NEEDING DATABASE MIGRATION (" . count($needsMigration) . " files):\n";
echo str_repeat("-", 60) . "\n";

if (empty($needsMigration)) {
    echo "✓ All files are already using the new config system!\n\n";
} else {
    foreach ($needsMigration as $file => $issues) {
        echo "❌ $file\n";
        echo "   Issues: " . implode(', ', $issues) . "\n\n";
    }
}

echo "\nFILES WITH SMTP/EMAIL CREDENTIALS (" . count($emailFiles) . " files):\n";
echo str_repeat("-", 60) . "\n";

if (empty($emailFiles)) {
    echo "✓ No hardcoded email credentials found!\n\n";
} else {
    foreach ($emailFiles as $file) {
        echo "⚠️  $file\n";
    }
    echo "\nThese files need to use the new getMailer() function from config/email.php\n\n";
}

echo "\n=================================================\n";
echo "MIGRATION INSTRUCTIONS:\n";
echo "=================================================\n\n";

echo "1. For each database file, replace:\n";
echo "   OLD CODE:\n";
echo "   ---------\n";
echo "   \$host = 'localhost';\n";
echo "   \$user = 'root';\n";
echo "   \$pass = '';\n";
echo "   \$db = 'e_checklist';\n";
echo "   \$conn = new mysqli(\$host, \$user, \$pass, \$db);\n\n";

echo "   NEW CODE:\n";
echo "   ---------\n";
echo "   require_once __DIR__ . '/config/config.php';\n";
echo "   \$conn = getDBConnection();\n\n";

echo "2. At the end of each file, replace:\n";
echo "   OLD: \$conn->close();\n";
echo "   NEW: closeDBConnection(\$conn);\n\n";

echo "3. For email files, replace:\n";
echo "   OLD: Manual PHPMailer configuration\n";
echo "   NEW: \$mail = getMailer();\n\n";

echo "4. Files already migrated:\n";
echo "   ✓ login_process.php (example)\n\n";

echo "=================================================\n";
echo "Next file to migrate: " . (empty($needsMigration) ? "None! You're done!" : array_key_first($needsMigration)) . "\n";
echo "=================================================\n\n";

// If running in browser, format output
if (php_sapi_name() !== 'cli') {
    echo "<style>body { font-family: monospace; white-space: pre; background: #1e1e1e; color: #d4d4d4; padding: 20px; }</style>";
}
?>
