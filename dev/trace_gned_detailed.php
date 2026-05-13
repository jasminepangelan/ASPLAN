<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';

$student_id = '220100021';
$program = 'Bachelor of Science in Computer Engineering';

echo "=== DETAILED TRACE: GNED 14 & GNED 09 PLACEMENT ===\n";
echo "Student: $student_id\n";
echo "Program: $program\n";
echo "Generated at: " . date('Y-m-d H:i:s') . "\n\n";

// Enable debug mode
$_GET['debug'] = '1';
$_GET['debuglog'] = '1';

try {
    // Generate the plan with debug enabled
    $generator = new StudyPlanGenerator($student_id, $program);
    $plan = $generator->generateOptimizedPlan();
    
    // Get the debug log file path from the generator
    $debugLogFile = $generator->getDebugLogPath();
    
    if (!empty($debugLogFile) && file_exists($debugLogFile)) {
        echo "=== DEBUG LOG (Location: $debugLogFile) ===\n\n";
        
        $logContent = file_get_contents($debugLogFile);
        $lines = explode("\n", $logContent);
        
        // Filter and display relevant GNED logs
        $gned_logs = [];
        foreach ($lines as $line) {
            if (stripos($line, 'GNED') !== false || 
                stripos($line, 'buildTermPlanFromAvailable') !== false ||
                stripos($line, 'SCHEDULE') !== false ||
                stripos($line, 'SKIP') !== false ||
                stripos($line, 'applyConstraints') !== false ||
                stripos($line, 'prerequisite') !== false) {
                $gned_logs[] = $line;
            }
        }
        
        if (!empty($gned_logs)) {
            echo "Filtered Log Entries (GNED + Scheduling Decisions):\n";
            echo str_repeat("-", 100) . "\n";
            foreach ($gned_logs as $line) {
                echo $line . "\n";
            }
            echo str_repeat("-", 100) . "\n\n";
        } else {
            echo "No GNED-specific logs found. Full debug log:\n";
            echo str_repeat("-", 100) . "\n";
            echo $logContent;
            echo str_repeat("-", 100) . "\n\n";
        }
    } else {
        echo "WARNING: Debug log file not found at: " . ($debugLogFile ?? 'Unknown path') . "\n";
        echo "Debug logging may be disabled or the temp directory is not accessible.\n";
        echo "\n";
    }
    
    // Analyze the generated plan
    echo "=== GENERATED STUDY PLAN ANALYSIS ===\n\n";
    
    if (empty($plan)) {
        echo "ERROR: Study plan is empty!\n";
    } else {
        $term_num = 0;
        $gned14_term = null;
        $gned09_term = null;
        $all_gned = [];
        
        foreach ($plan as $term) {
            $term_num++;
            $year = $term['year'] ?? '?';
            $semester = $term['semester'] ?? '?';
            $courses = $term['courses'] ?? [];
            $skipped = !empty($term['skipped']);
            
            echo "Term $term_num: $year - $semester";
            if ($skipped) {
                echo " [SKIPPED: " . ($term['skip_reason'] ?? 'Unknown') . "]";
            }
            echo "\n";
            
            if (!$skipped) {
                echo "  Courses (" . count($courses) . "):\n";
                foreach ($courses as $code => $course) {
                    $units = ($course['units'] ?? 0);
                    $status = '';
                    if (!empty($course['needs_retake'])) {
                        $status = '[RETAKE]';
                    } elseif (!empty($course['cross_registered'])) {
                        $status = '[CROSS-REG]';
                    }
                    
                    echo "    ✓ $code " . str_pad('(' . $units . ' units)', 12) . " $status\n";
                    
                    if (strtoupper(trim($code)) === 'GNED 14') {
                        $gned14_term = $term_num;
                        echo "      👈 GNED 14 FOUND HERE (Term $term_num)\n";
                    }
                    if (strtoupper(trim($code)) === 'GNED 09') {
                        $gned09_term = $term_num;
                        echo "      👈 GNED 09 FOUND HERE (Term $term_num)\n";
                    }
                    
                    if (strtoupper(substr(trim($code), 0, 4)) === 'GNED') {
                        $all_gned[] = ['code' => $code, 'term' => $term_num];
                    }
                }
            }
            echo "\n";
        }
        
        echo "=== SUMMARY ===\n";
        echo "Total Terms: $term_num\n";
        echo "Total GNED Courses: " . count($all_gned) . "\n";
        if (!empty($all_gned)) {
            foreach ($all_gned as $g) {
                echo "  - " . $g['code'] . " in Term " . $g['term'] . "\n";
            }
        }
        echo "\n";
        
        echo "TARGET ANALYSIS:\n";
        echo "  GNED 14: " . ($gned14_term ? "Term $gned14_term (which is page " . ceil($gned14_term / 5) . ")" : "NOT FOUND") . "\n";
        echo "  GNED 09: " . ($gned09_term ? "Term $gned09_term (which is page " . ceil($gned09_term / 5) . ")" : "NOT FOUND") . "\n";
        echo "\n";
        
        if (($gned14_term === 5 || $gned14_term > 5) || ($gned09_term === 5 || $gned09_term > 5)) {
            echo "⚠️  LATE PLACEMENT DETECTED:\n";
            echo "  These GNED courses should likely appear earlier but are scheduled for the final term(s).\n";
            echo "  This suggests constraints are preventing earlier placement.\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== END TRACE ===\n";
