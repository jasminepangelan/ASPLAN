<?php
/**
 * Apply Grade Tracking Columns Migration
 * Adds timestamp columns to student_checklists for accurate completion tracking
 */

require_once __DIR__ . '/../../config/config.php';

echo "=============================================================\n";
echo "  GRADE TRACKING COLUMNS MIGRATION\n";
echo "  Adding timestamp columns to student_checklists\n";
echo "=============================================================\n\n";

$conn = getDBConnection();

// Check if columns already exist
echo "Step 1: Checking existing schema...\n";
$columns_check = $conn->query("SHOW COLUMNS FROM student_checklists");
$existing_columns = [];
while ($col = $columns_check->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

$required_columns = [
    'grade_submitted_at',
    'updated_at',
    'submitted_by',
    'grade_approved',
    'approved_at',
    'approved_by'
];

$missing_columns = array_diff($required_columns, $existing_columns);

if (empty($missing_columns)) {
    echo "✓ All columns already exist!\n\n";
    echo "Existing columns:\n";
    foreach ($required_columns as $col) {
        echo "  - $col ✓\n";
    }
} else {
    echo "Missing columns to be added:\n";
    foreach ($missing_columns as $col) {
        echo "  - $col\n";
    }
    echo "\n";
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        echo "Step 2: Adding columns...\n";
        
        // Add grade_submitted_at
        if (in_array('grade_submitted_at', $missing_columns)) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD COLUMN `grade_submitted_at` DATETIME NULL 
                COMMENT 'When the grade was submitted by student/adviser' 
                AFTER `final_grade`");
            echo "  ✓ Added grade_submitted_at\n";
        }
        
        // Add updated_at
        if (in_array('updated_at', $missing_columns)) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP 
                COMMENT 'Last modification timestamp' 
                AFTER `grade_submitted_at`");
            echo "  ✓ Added updated_at\n";
        }
        
        // Add submitted_by
        if (in_array('submitted_by', $missing_columns)) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD COLUMN `submitted_by` VARCHAR(20) NULL 
                COMMENT 'Who submitted: student, adviser, or admin' 
                AFTER `updated_at`");
            echo "  ✓ Added submitted_by\n";
        }
        
        // Add grade_approved
        if (in_array('grade_approved', $missing_columns)) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD COLUMN `grade_approved` TINYINT(1) DEFAULT 0 
                COMMENT 'Whether grade has been verified/approved' 
                AFTER `submitted_by`");
            echo "  ✓ Added grade_approved\n";
        }
        
        // Add approved_at
        if (in_array('approved_at', $missing_columns)) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD COLUMN `approved_at` DATETIME NULL 
                COMMENT 'When grade was approved' 
                AFTER `grade_approved`");
            echo "  ✓ Added approved_at\n";
        }
        
        // Add approved_by
        if (in_array('approved_by', $missing_columns)) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD COLUMN `approved_by` VARCHAR(50) NULL 
                COMMENT 'Who approved the grade' 
                AFTER `approved_at`");
            echo "  ✓ Added approved_by\n";
        }
        
        echo "\nStep 3: Adding indexes for performance...\n";
        
        // Add indexes (check if they exist first)
        $indexes_check = $conn->query("SHOW INDEX FROM student_checklists WHERE Key_name = 'idx_grade_submission'");
        if ($indexes_check->num_rows === 0) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD INDEX `idx_grade_submission` (`student_id`, `grade_submitted_at`)");
            echo "  ✓ Added idx_grade_submission\n";
        }
        
        $indexes_check = $conn->query("SHOW INDEX FROM student_checklists WHERE Key_name = 'idx_grade_approved'");
        if ($indexes_check->num_rows === 0) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD INDEX `idx_grade_approved` (`student_id`, `grade_approved`)");
            echo "  ✓ Added idx_grade_approved\n";
        }
        
        $indexes_check = $conn->query("SHOW INDEX FROM student_checklists WHERE Key_name = 'idx_submitted_by'");
        if ($indexes_check->num_rows === 0) {
            $conn->query("ALTER TABLE `student_checklists` 
                ADD INDEX `idx_submitted_by` (`submitted_by`)");
            echo "  ✓ Added idx_submitted_by\n";
        }
        
        echo "\nStep 4: Updating existing records with valid grades...\n";
        
        $update_query = "UPDATE `student_checklists`
            SET 
                `grade_submitted_at` = NOW(),
                `submitted_by` = 'student',
                `grade_approved` = 1,
                `approved_at` = NOW(),
                `approved_by` = 'system_migration'
            WHERE 
                `final_grade` IS NOT NULL 
                AND `final_grade` != '' 
                AND `final_grade` != 'INC' 
                AND `final_grade` != 'DRP'
                AND `final_grade` != 'S'
                AND `final_grade` != 'N/A'
                AND `final_grade` != '0'
                AND `final_grade` != '0.0'
                AND `final_grade` REGEXP '^[0-9]+(\\\\.[0-9]+)?\$'
                AND CAST(`final_grade` AS DECIMAL(3,1)) BETWEEN 1.0 AND 3.0
                AND `grade_submitted_at` IS NULL";
        
        $conn->query($update_query);
        $updated_rows = $conn->affected_rows;
        echo "  ✓ Updated $updated_rows records with timestamps\n";
        
        // Commit transaction
        $conn->commit();
        
        echo "\n✓ Migration completed successfully!\n\n";
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
        echo "All changes have been rolled back.\n\n";
        exit(1);
    }
}

// Show summary statistics
echo "Step 5: Verification Summary\n";
echo "------------------------------------------------------------\n";

$stats = [];

// Total records
$result = $conn->query("SELECT COUNT(*) as count FROM student_checklists");
$stats['total'] = $result->fetch_assoc()['count'];

// Records with grades
$result = $conn->query("SELECT COUNT(*) as count FROM student_checklists 
    WHERE final_grade IS NOT NULL AND final_grade != ''");
$stats['with_grades'] = $result->fetch_assoc()['count'];

// Records with timestamps
$result = $conn->query("SELECT COUNT(*) as count FROM student_checklists 
    WHERE grade_submitted_at IS NOT NULL");
$stats['with_timestamps'] = $result->fetch_assoc()['count'];

// Approved grades
$result = $conn->query("SELECT COUNT(*) as count FROM student_checklists 
    WHERE grade_approved = 1");
$stats['approved'] = $result->fetch_assoc()['count'];

// Students with completed courses
$result = $conn->query("SELECT COUNT(DISTINCT student_id) as count FROM student_checklists 
    WHERE grade_submitted_at IS NOT NULL 
    AND CAST(final_grade AS DECIMAL(3,1)) BETWEEN 1.0 AND 3.0");
$stats['students_with_grades'] = $result->fetch_assoc()['count'];

echo "Total checklist records:        {$stats['total']}\n";
echo "Records with grades:            {$stats['with_grades']}\n";
echo "Records with timestamps:        {$stats['with_timestamps']}\n";
echo "Approved grades:                {$stats['approved']}\n";
echo "Students with completed courses: {$stats['students_with_grades']}\n";

echo "\n=============================================================\n";
echo "  MIGRATION COMPLETE\n";
echo "=============================================================\n";
echo "\nNext steps:\n";
echo "1. Clear browser cache (Ctrl+Shift+R)\n";
echo "2. Log out and log back in\n";
echo "3. Check Study Plan page - statistics should now be accurate\n";
echo "4. Run diagnostic: php student/diagnose_study_plan.php [STUDENT_ID]\n\n";

closeDBConnection($conn);
?>
