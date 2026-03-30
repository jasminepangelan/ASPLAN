<?php
/**
 * Compare live DB (cvsucarmona_courses) against reference file (checklist_of_programs)
 * Outputs differences: wrong titles, wrong semesters, wrong year levels, wrong prereqs, missing/extra courses
 */
require_once __DIR__ . '/../config/config.php';
$conn = getDBConnection();

// ---- Parse reference file ----
$ref_file = file_get_contents(__DIR__ . '/checklist_of_programs');
$lines = explode("\n", $ref_file);

// Program name in SQL file → short code used in DB programs column
$prog_map = [
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

$ref_courses = []; // key: "PROG|CODE" => array of entries (supports duplicates)

foreach ($lines as $line) {
    $line = trim($line);
    // Match INSERT value rows: (year, 'program', 'year_level', 'semester', 'code', 'title', lec, lab, hrs_lec, hrs_lab, 'pre')
    if (preg_match("/^\((\d+),\s*'((?:[^']|'')+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*'((?:[^']|'')*)',\s*(\d+),\s*(\d+),\s*(\d+),\s*(\d+),\s*'((?:[^']|'')*)'\)/", $line, $m)) {
        $curriculum_year = $m[1];
        $program_full = str_replace("''", "'", $m[2]);
        $year_level = trim($m[3]);
        $semester = trim($m[4]);
        $course_code = trim($m[5]);
        $course_title = str_replace("''", "'", trim($m[6]));
        $lec = (int)$m[7];
        $lab = (int)$m[8];
        $hrs_lec = (int)$m[9];
        $hrs_lab = (int)$m[10];
        $prerequisite = str_replace("''", "'", trim($m[11]));

        $prog_code = $prog_map[$program_full] ?? '???';
        $key = $prog_code . '|' . $course_code;

        if (!isset($ref_courses[$key])) $ref_courses[$key] = [];
        $ref_courses[$key][] = [
            'prog' => $prog_code,
            'code' => $course_code,
            'title' => $course_title,
            'year' => $year_level,
            'sem' => $semester,
            'lec' => $lec,
            'lab' => $lab,
            'hrs_lec' => $hrs_lec,
            'hrs_lab' => $hrs_lab,
            'pre' => $prerequisite,
            'curriculum_year' => $curriculum_year,
        ];
    }
}

// ---- Load all DB courses ----
$db_courses = [];
$result = $conn->query("
    SELECT 
        curriculumyear_coursecode,
        SUBSTRING(curriculumyear_coursecode, 6) AS course_code,
        programs,
        course_title,
        year_level,
        semester,
        credit_units_lec,
        credit_units_lab,
        lect_hrs_lec,
        lect_hrs_lab,
        pre_requisite
    FROM cvsucarmona_courses
    WHERE course_title IS NOT NULL AND course_title != ''
    ORDER BY curriculumyear_coursecode
");

$all_prog_codes = array_values($prog_map);

while ($row = $result->fetch_assoc()) {
    $code = trim($row['course_code']);
    $progs_raw = $row['programs'];
    // Expand programs: e.g. "BSIT, BSCS" → ['BSIT','BSCS']
    $progs = array_map('trim', explode(',', $progs_raw));
    
    foreach ($progs as $prog) {
        if (!in_array($prog, $all_prog_codes)) continue;
        $key = $prog . '|' . $code;
        if (!isset($db_courses[$key])) $db_courses[$key] = [];
        $db_courses[$key][] = [
            'prog' => $prog,
            'code' => $code,
            'title' => trim($row['course_title']),
            'year' => trim($row['year_level']),
            'sem' => trim($row['semester']),
            'lec' => (int)$row['credit_units_lec'],
            'lab' => (int)$row['credit_units_lab'],
            'hrs_lec' => (int)$row['lect_hrs_lec'],
            'hrs_lab' => (int)$row['lect_hrs_lab'],
            'pre' => trim($row['pre_requisite'] ?? ''),
            'raw_code' => $row['curriculumyear_coursecode'],
        ];
    }
}

// Count DB entries
$db_count = 0;
foreach ($db_courses as $entries) { $db_count += count($entries); }

// ---- Compare ----
$issues = [];
$ref_count = 0;

// Count total reference entries
foreach ($ref_courses as $entries) { $ref_count += count($entries); }

// 1. Courses in REF but missing in DB: for each ref entry, check if DB has a matching entry
foreach ($ref_courses as $key => $entries) {
    foreach ($entries as $ref) {
        if (!isset($db_courses[$key])) {
            $issues[] = "MISSING IN DB: [{$ref['prog']}] {$ref['code']} - {$ref['title']} ({$ref['year']}/{$ref['sem']})";
            continue;
        }
        // Check if any DB entry matches this ref entry's year/sem
        $found = false;
        foreach ($db_courses[$key] as $db) {
            if ($db['year'] === $ref['year'] && $db['sem'] === $ref['sem']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $issues[] = "MISSING IN DB: [{$ref['prog']}] {$ref['code']} - {$ref['title']} ({$ref['year']}/{$ref['sem']})";
        }
    }
}

// 2. Courses in DB but not in REF
foreach ($db_courses as $key => $db_entries) {
    foreach ($db_entries as $db) {
        if (!isset($ref_courses[$key])) {
            $issues[] = "EXTRA IN DB:   [{$db['prog']}] {$db['code']} - {$db['title']} ({$db['year']}/{$db['sem']})";
            continue;
        }
        // Check if any ref entry matches this DB entry's year/sem
        $found = false;
        foreach ($ref_courses[$key] as $ref) {
            if ($ref['year'] === $db['year'] && $ref['sem'] === $db['sem']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $issues[] = "EXTRA IN DB:   [{$db['prog']}] {$db['code']} - {$db['title']} ({$db['year']}/{$db['sem']})";
        }
    }
}

// 3. Courses in both but with differences
$field_diffs = [];
foreach ($ref_courses as $key => $entries) {
    foreach ($entries as $ref) {
        if (!isset($db_courses[$key])) continue;
        
        // Find best matching DB entry (same year/sem)
        $db = null;
        foreach ($db_courses[$key] as $candidate) {
            if ($candidate['year'] === $ref['year'] && $candidate['sem'] === $ref['sem']) {
                $db = $candidate;
                break;
            }
        }
        if (!$db) continue; // Will be caught as MISSING
        
        $diffs = [];
        if ($ref['title'] !== $db['title']) {
            $diffs[] = "title: DB=\"{$db['title']}\" REF=\"{$ref['title']}\"";
        }
        if ($ref['lec'] !== $db['lec']) {
            $diffs[] = "lec: DB={$db['lec']} REF={$ref['lec']}";
        }
        if ($ref['lab'] !== $db['lab']) {
            $diffs[] = "lab: DB={$db['lab']} REF={$ref['lab']}";
        }
        if ($ref['hrs_lec'] !== $db['hrs_lec']) {
            $diffs[] = "hrs_lec: DB={$db['hrs_lec']} REF={$ref['hrs_lec']}";
        }
        if ($ref['hrs_lab'] !== $db['hrs_lab']) {
            $diffs[] = "hrs_lab: DB={$db['hrs_lab']} REF={$ref['hrs_lab']}";
        }
        $ref_pre = trim($ref['pre']);
        $db_pre = trim($db['pre']);
        if (strcasecmp($ref_pre, $db_pre) !== 0) {
            $diffs[] = "pre: DB=\"{$db_pre}\" REF=\"{$ref_pre}\"";
        }
        
        if (!empty($diffs)) {
            $field_diffs[] = "DIFFER [{$ref['prog']}] {$ref['code']}: " . implode(' | ', $diffs);
        }
    }
}

// ---- Output ----
echo "=== COMPARISON: Reference File vs Live DB ===\n";
echo "Reference courses: $ref_count\n";
echo "DB courses (mapped): $db_count\n\n";

if (empty($issues) && empty($field_diffs)) {
    echo "NO DIFFERENCES FOUND!\n";
} else {
    if (!empty($issues)) {
        echo "--- MISSING / EXTRA ---\n";
        foreach ($issues as $i) echo "  $i\n";
        echo "\n";
    }
    if (!empty($field_diffs)) {
        echo "--- FIELD DIFFERENCES ---\n";
        foreach ($field_diffs as $d) echo "  $d\n";
        echo "\n";
    }
    echo "Total issues: " . (count($issues) + count($field_diffs)) . "\n";
}

closeDBConnection($conn);
