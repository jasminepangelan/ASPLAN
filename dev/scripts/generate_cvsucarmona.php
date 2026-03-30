<?php
/**
 * Generate cvsucarmona_courses.sql from checklist_of_programs
 * Groups courses by curriculum_year + course_code, aggregates programs
 */

$inputFile = __DIR__ . '/../checklist_of_programs';
$outputFile = __DIR__ . '/../cvsucarmona_courses.sql';

// Program name to abbreviation mapping
$programAbbreviations = [
    'Bachelor of Science in Industrial Technology' => 'BSIndT',
    'Bachelor of Science in Computer Engineering' => 'BSCpE',
    'Bachelor of Science in Information Technology' => 'BSIT',
    'Bachelor of Science in Computer Science' => 'BSCS',
    'Bachelor of Science in Hospitality Management' => 'BSHM',
    'Bachelor of Science in Business Administration Major in Human Resource Management' => 'BSBA-HRM',
    'Bachelor of Science in Business Administration Major in Marketing Management' => 'BSBA-MM',
    'Bachelor of Secondary Education Major in English' => 'BSEd-English',
    'Bachelor of Secondary Education Major in Science' => 'BSEd-Science',
    'Bachelor of Secondary Education Major in Mathematics' => 'BSEd-Math',
];

$content = file_get_contents($inputFile);
if ($content === false) {
    die("Error: Cannot read input file: $inputFile\n");
}

// Parse all value tuples from the SQL file
// Match lines like: (2018, 'program', 'year', 'sem', 'code', 'title', 1, 2, 3, 4, 'prereq')
$pattern = '/\(\s*(\d{4})\s*,\s*\'([^\']*)\'\s*,\s*\'([^\']*)\'\s*,\s*\'([^\']*)\'\s*,\s*\'([^\']*)\'\s*,\s*\'([^\']*)\'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*\'([^\']*)\'\s*\)/';

preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

echo "Found " . count($matches) . " course entries\n";

// Aggregate courses by curriculum_year + course_code
$courses = [];

foreach ($matches as $match) {
    $curriculumYear = $match[1];
    $program = $match[2];
    $yearLevel = trim($match[3]);
    $semester = trim($match[4]);
    $courseCode = trim($match[5]);
    $courseTitle = $match[6];
    $creditLec = (int)$match[7];
    $creditLab = (int)$match[8];
    $lectLec = (int)$match[9];
    $lectLab = (int)$match[10];
    $preRequisite = trim($match[11]);

    // Create unique key
    $key = $curriculumYear . '_' . $courseCode;

    // Get abbreviation
    $abbr = isset($programAbbreviations[$program]) ? $programAbbreviations[$program] : $program;

    if (!isset($courses[$key])) {
        $courses[$key] = [
            'curriculumyear_coursecode' => $key,
            'programs' => [$abbr],
            'course_title' => $courseTitle,
            'year_level' => $yearLevel,
            'semester' => $semester,
            'credit_units_lec' => $creditLec,
            'credit_units_lab' => $creditLab,
            'lect_hrs_lec' => $lectLec,
            'lect_hrs_lab' => $lectLab,
            'pre_requisite' => $preRequisite,
        ];
    } else {
        // Add program if not already listed
        if (!in_array($abbr, $courses[$key]['programs'])) {
            $courses[$key]['programs'][] = $abbr;
        }
    }
}

echo "Unique courses: " . count($courses) . "\n";

// Generate SQL output
$sql = "-- CvSU Carmona Courses Database\n";
$sql .= "-- This table contains unique courses with aggregated program listings\n";
$sql .= "-- Connected to osas_db database\n";
$sql .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
$sql .= "USE `osas_db`;\n\n";

$sql .= "CREATE TABLE IF NOT EXISTS `cvsucarmona_courses` (\n";
$sql .= "  `curriculumyear_coursecode` varchar(50) NOT NULL,\n";
$sql .= "  `programs` text NOT NULL,\n";
$sql .= "  `course_title` varchar(255) NOT NULL,\n";
$sql .= "  `year_level` varchar(50) NOT NULL,\n";
$sql .= "  `semester` varchar(50) NOT NULL,\n";
$sql .= "  `credit_units_lec` int(2) DEFAULT 0,\n";
$sql .= "  `credit_units_lab` int(2) DEFAULT 0,\n";
$sql .= "  `lect_hrs_lec` int(2) DEFAULT 0,\n";
$sql .= "  `lect_hrs_lab` int(2) DEFAULT 0,\n";
$sql .= "  `pre_requisite` varchar(255) DEFAULT 'NONE',\n";
$sql .= "  PRIMARY KEY (`curriculumyear_coursecode`),\n";
$sql .= "  KEY `year_level` (`year_level`),\n";
$sql .= "  KEY `semester` (`semester`)\n";
$sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

$sql .= "-- Insert aggregated course data\n";
$sql .= "-- Courses are grouped by curriculum_year and course_code, with all programs listed\n";
$sql .= "-- Program Abbreviations: BSIndT, BSCpE, BSIT, BSCS, BSHM, BSBA-HRM, BSBA-MM, BSEd-English, BSEd-Science, BSEd-Math\n\n";

$sql .= "INSERT INTO `cvsucarmona_courses` (`curriculumyear_coursecode`, `programs`, `course_title`, `year_level`, `semester`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES\n";

$values = [];
foreach ($courses as $course) {
    $programsStr = implode(', ', $course['programs']);
    $courseTitle = str_replace("'", "''", $course['course_title']);
    $preReq = str_replace("'", "''", $course['pre_requisite']);
    $currKey = str_replace("'", "''", $course['curriculumyear_coursecode']);

    $values[] = sprintf(
        "('%s', '%s', '%s', '%s', '%s', %d, %d, %d, %d, '%s')",
        $currKey,
        $programsStr,
        $courseTitle,
        $course['year_level'],
        $course['semester'],
        $course['credit_units_lec'],
        $course['credit_units_lab'],
        $course['lect_hrs_lec'],
        $course['lect_hrs_lab'],
        $preReq
    );
}

$sql .= implode(",\n", $values) . ";\n";

file_put_contents($outputFile, $sql);

echo "SQL file generated: $outputFile\n";
echo "Total unique rows: " . count($values) . "\n";
