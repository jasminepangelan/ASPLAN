<?php
/**
 * Normalize All Student Programs to Full Names
 * 
 * Standardizes all program variations in student_info to match the full names
 * used in student_input_form_2.html and system mappings
 * 
 * Run this script through browser: http://localhost/ASPLAN_v5/dev/normalize_student_programs.php
 */

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Mapping of all variations to canonical full program names
$programNormalizationMap = [
    // BSCS variations
    'BSCS' => 'Bachelor of Science in Computer Science',
    'bscs' => 'Bachelor of Science in Computer Science',
    'BS CS' => 'Bachelor of Science in Computer Science',
    'BS-CS' => 'Bachelor of Science in Computer Science',
    
    // BSIT variations
    'BSIT' => 'Bachelor of Science in Information Technology',
    'bsit' => 'Bachelor of Science in Information Technology',
    'BS IT' => 'Bachelor of Science in Information Technology',
    'BS-IT' => 'Bachelor of Science in Information Technology',
    
    // BSCpE variations
    'BSCPE' => 'Bachelor of Science in Computer Engineering',
    'BSCpE' => 'Bachelor of Science in Computer Engineering',
    'BSCPE' => 'Bachelor of Science in Computer Engineering',
    'BS CPE' => 'Bachelor of Science in Computer Engineering',
    'BS-CPE' => 'Bachelor of Science in Computer Engineering',
    'bscpe' => 'Bachelor of Science in Computer Engineering',
    
    // BSIndT variations
    'BSINDT' => 'Bachelor of Science in Industrial Technology',
    'BSIndT' => 'Bachelor of Science in Industrial Technology',
    'BS IndT' => 'Bachelor of Science in Industrial Technology',
    'BS-IndT' => 'Bachelor of Science in Industrial Technology',
    'bsindt' => 'Bachelor of Science in Industrial Technology',
    
    // BSHM variations
    'BSHM' => 'Bachelor of Science in Hospitality Management',
    'bshm' => 'Bachelor of Science in Hospitality Management',
    'BS HM' => 'Bachelor of Science in Hospitality Management',
    'BS-HM' => 'Bachelor of Science in Hospitality Management',
    
    // BSBA variations
    'BSBA' => 'Bachelor of Science in Business Administration - Major in Marketing Management', // Default to Marketing if not specified
    'bsba' => 'Bachelor of Science in Business Administration - Major in Marketing Management',
    'BSBA-MM' => 'Bachelor of Science in Business Administration - Major in Marketing Management',
    'BSBA-HRM' => 'Bachelor of Science in Business Administration - Major in Human Resource Management',
    'BSBA-HR' => 'Bachelor of Science in Business Administration - Major in Human Resource Management',
    
    // BSEd variations
    'BSED' => 'Bachelor of Secondary Education major in English', // Default to English if not specified
    'bsed' => 'Bachelor of Secondary Education major in English',
    'BS ED' => 'Bachelor of Secondary Education major in English',
    'BS-ED' => 'Bachelor of Secondary Education major in English',
    'BSEd-English' => 'Bachelor of Secondary Education major in English',
    'BSEd-Math' => 'Bachelor of Secondary Education major Math',
    'BSEd-Science' => 'Bachelor of Secondary Education major in Science',
    'BSED-ENGLISH' => 'Bachelor of Secondary Education major in English',
    'BSED-MATH' => 'Bachelor of Secondary Education major Math',
    'BSED-SCIENCE' => 'Bachelor of Secondary Education major in Science',
];

ob_start();

try {
    // Get database connection
    $conn = getDBConnection();
    
    // Get all unique program values currently in the database
    $checkQuery = "SELECT DISTINCT program FROM student_info WHERE program IS NOT NULL ORDER BY program ASC";
    $result = $conn->query($checkQuery);
    $currentPrograms = [];
    while ($row = $result->fetch_assoc()) {
        $currentPrograms[] = trim($row['program']);
    }
    
    echo json_encode([
        'status' => 'info',
        'message' => 'Current programs in database:',
        'programs' => array_values(array_unique($currentPrograms))
    ]);
    echo "\n";
    
    // Process normalization
    $updateCount = 0;
    $totalUpdated = 0;
    
    foreach ($programNormalizationMap as $variation => $canonical) {
        $updateQuery = "UPDATE student_info SET program = ? WHERE TRIM(UPPER(program)) = UPPER(?)";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("ss", $canonical, $variation);
        
        if ($updateStmt->execute()) {
            $affected = $updateStmt->affected_rows;
            if ($affected > 0) {
                $totalUpdated += $affected;
                $updateCount++;
                echo json_encode([
                    'variation' => $variation,
                    'canonical' => $canonical,
                    'records_updated' => $affected
                ]);
                echo "\n";
            }
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Program normalization completed',
        'total_variations_processed' => $updateCount,
        'total_records_updated' => $totalUpdated
    ]);
    
    // Final verification
    $verifyQuery = "SELECT DISTINCT program FROM student_info WHERE program IS NOT NULL ORDER BY program ASC";
    $verifyResult = $conn->query($verifyQuery);
    $finalPrograms = [];
    while ($row = $verifyResult->fetch_assoc()) {
        $finalPrograms[] = trim($row['program']);
    }
    
    echo "\n";
    echo json_encode([
        'status' => 'info',
        'message' => 'Programs after normalization:',
        'programs' => array_values(array_unique($finalPrograms))
    ]);
    
    // Close connection
    closeDBConnection($conn);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
    ob_end_flush();
    exit;
}

ob_end_flush();
?>
