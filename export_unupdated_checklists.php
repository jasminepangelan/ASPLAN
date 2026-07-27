<?php
/**
 * Export Students Who Have Not Updated Their Checklist yet (Railway DB)
 * Criteria:
 * - Students who have no grades in all courses (empty checklist)
 * - Includes 1st years (batch 2501) with no grades in 1st year 1st sem
 * - Includes 2nd years (batch 2401) with no grades from 1st yr 1st sem to 2nd yr 1st sem
 * - Includes 3rd years (batch 2301) with no grades from 1st yr 1st sem to 3rd yr 1st sem
 */

$_ENV['MYSQL_PUBLIC_URL'] = 'mysql://root:PIlezyGzBauvijKewcPUtNqUtETTNcfP@hayabusa.proxy.rlwy.net:58143/railway';
require_once __DIR__ . '/config/database.php';

$conn = getDBConnection();

// Query students who have no grades in student_checklists
$sql = "
    SELECT student_number, last_name, first_name, middle_name, program, curriculum_year, stud_classification
    FROM student_info s
    WHERE NOT EXISTS (
        SELECT 1 FROM student_checklists c
        WHERE c.student_id = s.student_number
          AND (
               (c.final_grade != '' AND c.final_grade IS NOT NULL) 
            OR (c.grade != '' AND c.grade IS NOT NULL)
            OR (c.final_grade_2 != '' AND c.final_grade_2 IS NOT NULL)
            OR (c.final_grade_3 != '' AND c.final_grade_3 IS NOT NULL)
          )
    )
    ORDER BY student_number DESC
";

$res = $conn->query($sql);
if (!$res) {
    die("Database query failed: " . $conn->error . "\n");
}

$students = [];
$byBatch = [];

while ($row = $res->fetch_assoc()) {
    $sNum = trim($row['student_number']);
    // Clean trailing commas and whitespace from names
    $lName = rtrim(trim($row['last_name']), ',');
    $fName = rtrim(trim($row['first_name']), ',');
    $mName = rtrim(trim($row['middle_name']), ',');
    $mInit = !empty($mName) ? strtoupper(substr($mName, 0, 1)) . '.' : '';
    
    $batch = substr($sNum, 0, 4);
    
    $item = [
        'student_number' => $sNum,
        'last_name' => $lName,
        'first_name' => $fName,
        'middle_initial' => $mInit,
        'batch' => $batch
    ];
    
    $students[] = $item;
    $byBatch[$batch][] = $item;
}

// Write CSV file
$csvFile = __DIR__ . '/students_not_updated_checklist.csv';
$fp = fopen($csvFile, 'w');
fputcsv($fp, ['Student Number', 'Last Name', 'First Name', 'Middle Initial']);
foreach ($students as $item) {
    fputcsv($fp, [
        $item['student_number'],
        $item['last_name'],
        $item['first_name'],
        $item['middle_initial']
    ]);
}
fclose($fp);

echo "Successfully exported " . count($students) . " students to students_not_updated_checklist.csv\n\n";

// Write Markdown Artifact Report
$artifactFile = 'C:/Users/Stephen Tiozon/.gemini/antigravity/brain/2a75e153-cb95-4a85-bd7f-b63a8c8fdea3/unupdated_checklists_report.md';
$md = "# Report: Students Who Have Not Updated Their Checklist\n\n";
$md .= "This report identifies all student accounts in the Railway production database that have not yet updated their study plan checklist (i.e. having **zero grades** submitted or approved across all curriculum courses).\n\n";
$md .= "## Summary Breakdown\n\n";
$md .= "| Batch / Year Level | Number of Students |\n";
$md .= "| :--- | :---: |\n";

ksort($byBatch);
$byBatch = array_reverse($byBatch, true);
foreach ($byBatch as $batch => $list) {
    $label = "Batch {$batch}";
    if ($batch === '2501') $label .= " (1st Year)";
    elseif ($batch === '2401') $label .= " (2nd Year)";
    elseif ($batch === '2301') $label .= " (3rd Year)";
    elseif ($batch === '2201') $label .= " (4th Year / Senior)";
    
    $md .= "| **{$label}** | " . count($list) . " |\n";
}
$md .= "| **Total Unupdated Accounts** | **" . count($students) . "** |\n\n";

$md .= "## Exported Data File\n";
$md .= "The complete list has been exported to CSV format: [students_not_updated_checklist.csv](file:///c:/Users/Stephen%20Tiozon/Documents/GitHub/ASPLAN/students_not_updated_checklist.csv)\n\n";

$md .= "## Full Student List\n\n";
$md .= "| Student Number | Last Name | First Name | Middle Initial | Batch |\n";
$md .= "| :--- | :--- | :--- | :---: | :---: |\n";
foreach ($students as $item) {
    $md .= "| {$item['student_number']} | {$item['last_name']} | {$item['first_name']} | {$item['middle_initial']} | {$item['batch']} |\n";
}

file_put_contents($artifactFile, $md);
echo "Successfully generated artifact report at: {$artifactFile}\n";
