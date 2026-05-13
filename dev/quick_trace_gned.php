<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';

$student_id = '220100021';
$program = 'Bachelor of Science in Computer Engineering';

// Enable debug
$_GET['debug'] = '1';
$_GET['debuglog'] = '1';

$generator = new StudyPlanGenerator($student_id, $program);
$plan = $generator->generateOptimizedPlan();
$debugLogPath = $generator->getDebugLogPath();

echo "\n";
echo "Student: $student_id\n";
echo "Program: $program\n";
echo "Debug Log: " . ($debugLogPath ?? 'N/A') . "\n\n";

// Display study plan summary
echo "=== STUDY PLAN GENERATED ===\n";
$termNum = 0;
$gned14Found = false;
$gned09Found = false;

foreach ($plan as $term) {
    $termNum++;
    $year = $term['year'] ?? '?';
    $sem = $term['semester'] ?? '?';
    $courses = $term['courses'] ?? [];
    $skipped = !empty($term['skipped']);
    
    echo "Term $termNum ($year - $sem): ";
    
    if ($skipped) {
        echo "[SKIPPED]\n";
    } else {
        echo count($courses) . " courses, " . ($term['total_units'] ?? 0) . " units\n";
        foreach ($courses as $code => $course) {
            echo "  - $code\n";
            if (strtoupper(trim($code)) === 'GNED 14') $gned14Found = true;
            if (strtoupper(trim($code)) === 'GNED 09') $gned09Found = true;
        }
    }
}

echo "\nGNED 14 Found: " . ($gned14Found ? "YES" : "NO") . "\n";
echo "GNED 09 Found: " . ($gned09Found ? "YES" : "NO") . "\n";

// Now show relevant debug log entries
if (!empty($debugLogPath) && file_exists($debugLogPath)) {
    echo "\n=== DEBUG LOG EXCERPT (GNED-related) ===\n";
    $lines = file($debugLogPath, FILE_IGNORE_NEW_LINES);
    $gned_lines = array_filter($lines, function($line) {
        return stripos($line, 'GNED') !== false || 
               stripos($line, 'buildTermPlanFromAvailable') !== false;
    });
    
    if (!empty($gned_lines)) {
        foreach ($gned_lines as $line) {
            echo $line . "\n";
        }
    } else {
        echo "No GNED-specific debug entries found.\n";
    }
}

echo "\n";
