<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../student/generate_study_plan.php';

$student_id = '220100021';
$program = 'Bachelor of Science in Computer Engineering';

echo "=== TRACING GNED 14 & GNED 09 PLACEMENT FOR STUDENT $student_id (BSCpE) ===\n\n";

// Enable debug mode by setting query parameter
$_GET['debug'] = '1';
$_GET['debuglog'] = '1';

try {
    $generator = new StudyPlanGenerator($student_id, $program);
    $plan = $generator->generateOptimizedPlan();
    
    echo "\n=== GENERATED STUDY PLAN ===\n\n";
    
    if (empty($plan)) {
        echo "ERROR: Study plan is empty!\n";
    } else {
        $term_count = 0;
        foreach ($plan as $term) {
            $term_count++;
            $year = $term['year'] ?? 'Unknown';
            $semester = $term['semester'] ?? 'Unknown';
            $courses = $term['courses'] ?? [];
            $total_units = $term['total_units'] ?? 0;
            $skipped = !empty($term['skipped']);
            
            echo "Term $term_count: $year - $semester\n";
            if ($skipped) {
                echo "  [SKIPPED] Reason: " . ($term['skip_reason'] ?? 'Unknown') . "\n";
            } else {
                echo "  Total Units: $total_units\n";
                echo "  Courses:\n";
                foreach ($courses as $code => $course) {
                    $units = ($course['units'] ?? 0);
                    $cross_reg = !empty($course['cross_registered']) ? ' (Cross-Reg)' : '';
                    $retake = !empty($course['needs_retake']) ? ' [RETAKE]' : '';
                    echo "    - $code: " . ($course['title'] ?? 'N/A') . " ($units units)$cross_reg$retake\n";
                    
                    // Highlight GNED courses
                    if (strtoupper(substr($code, 0, 4)) === 'GNED') {
                        echo "      ⚠️  GNED COURSE FOUND\n";
                    }
                }
            }
            echo "\n";
        }
    }
    
    // Look for GNED 14 and GNED 09 in the plan
    echo "\n=== GNED COURSE SEARCH ===\n";
    $gned14_found = false;
    $gned09_found = false;
    $gned14_term = null;
    $gned09_term = null;
    
    foreach ($plan as $idx => $term) {
        $courses = $term['courses'] ?? [];
        foreach ($courses as $code => $course) {
            if (strtoupper(trim($code)) === 'GNED 14') {
                $gned14_found = true;
                $gned14_term = $idx + 1;
            }
            if (strtoupper(trim($code)) === 'GNED 09') {
                $gned09_found = true;
                $gned09_term = $idx + 1;
            }
        }
    }
    
    echo "GNED 14: " . ($gned14_found ? "Found in Term $gned14_term" : "NOT FOUND") . "\n";
    echo "GNED 09: " . ($gned09_found ? "Found in Term $gned09_term" : "NOT FOUND") . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== END TRACE ===\n";
