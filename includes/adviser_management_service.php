<?php

if (!function_exists('amAcronymFromPhrase')) {
    function amAcronymFromPhrase(string $text): string
    {
        $cleaned = strtoupper(trim($text));
        if ($cleaned === '') {
            return '';
        }

        $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', (string)$cleaned);
        $tokens = explode(' ', (string)$cleaned);
        $skip = ['OF', 'IN', 'AND', 'THE', 'A', 'AN', 'MAJOR', 'PROGRAM'];
        $result = '';

        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $skip, true)) {
                continue;
            }
            $result .= substr($token, 0, 1);
        }

        return $result;
    }
}

if (!function_exists('amNormalizeProgramKey')) {
    function amNormalizeProgramKey(string $programName): string
    {
        $programName = trim($programName);
        if ($programName === '') {
            return '';
        }

        $normalized = strtoupper((string)preg_replace('/\s+/', ' ', $programName));

        if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN|ABENG|ABPSYCH|ABCOMM)\b/', $normalized, $codeMatch)) {
            $baseCode = strtoupper($codeMatch[1]);
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
            $baseCode = 'BS' . amAcronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
            $baseCode = 'BSED';
        } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
            $baseCode = 'BEED';
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE', '', $normalized));
            $baseCode = 'BS' . amAcronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF ARTS') !== false) {
            $subject = trim(str_replace('BACHELOR OF ARTS', '', $normalized));
            $baseCode = 'AB' . amAcronymFromPhrase($subject);
        } else {
            $baseCode = strtoupper($programName);
        }

        $majorKey = '';
        if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
            $majorKey = amAcronymFromPhrase($majorMatch[1]);
        }

        if ($majorKey !== '') {
            return $baseCode . '-' . $majorKey;
        }

        return $baseCode;
    }
}

if (!function_exists('amGetProgramLabelFromKey')) {
    function amGetProgramLabelFromKey(string $programKey): string
    {
        $programKey = trim($programKey);
        if ($programKey === '') {
            return $programKey;
        }

        $parts = explode('-', $programKey, 2);
        if (count($parts) === 2 && $parts[1] !== '') {
            return $parts[0] . ' - ' . $parts[1];
        }

        return $programKey;
    }
}

if (!function_exists('amLoadAdviserManagementData')) {
    function amLoadAdviserManagementData(PDO $conn, string $selectedProgram): array
    {
        $availablePrograms = [];
        $batches = [];
        $advisers = [];
        $batchAssignments = [];
        $usedBatchFallback = false;

        $programQuery = "SELECT DISTINCT TRIM(program) AS program
                         FROM adviser
                         WHERE program IS NOT NULL
                           AND TRIM(program) != ''
                         ORDER BY program ASC";
        $programStmt = $conn->prepare($programQuery);
        $programStmt->execute();
        $rawPrograms = $programStmt->fetchAll(PDO::FETCH_COLUMN);

        $availableProgramMap = [];
        foreach ($rawPrograms as $rawProgram) {
            $programKey = amNormalizeProgramKey((string)$rawProgram);
            if ($programKey === '') {
                continue;
            }
            if (!isset($availableProgramMap[$programKey])) {
                $availableProgramMap[$programKey] = amGetProgramLabelFromKey($programKey);
            }
        }

        ksort($availableProgramMap);
        $availablePrograms = $availableProgramMap;

        if ($selectedProgram !== '') {
            $selectedProgram = amNormalizeProgramKey($selectedProgram);
        }

        if ($selectedProgram !== '' && !isset($availablePrograms[$selectedProgram])) {
            $selectedProgram = '';
        }

        if ($selectedProgram === '' && count($availablePrograms) === 1) {
            $selectedProgram = (string)array_key_first($availablePrograms);
        }

        if ($selectedProgram !== '') {
            $batchQuery = "SELECT DISTINCT LEFT(student_number, 4) as batch, TRIM(program) AS program
                           FROM student_info
                           WHERE student_number IS NOT NULL
                             AND student_number != ''
                           ORDER BY batch DESC";
            $batchStmt = $conn->prepare($batchQuery);
            $batchStmt->execute();
            while ($batchRow = $batchStmt->fetch(PDO::FETCH_ASSOC)) {
                if (amNormalizeProgramKey((string)$batchRow['program']) === $selectedProgram) {
                    $batches[] = $batchRow['batch'];
                }
            }

            if (!empty($batches)) {
                $batches = array_values(array_unique($batches, SORT_STRING));
                rsort($batches, SORT_STRING);
            }

            if (empty($batches)) {
                $fallbackBatchQuery = "SELECT DISTINCT LEFT(student_number, 4) as batch
                                       FROM student_info
                                       WHERE student_number IS NOT NULL
                                         AND student_number != ''
                                       ORDER BY batch DESC";
                $fallbackBatchStmt = $conn->prepare($fallbackBatchQuery);
                $fallbackBatchStmt->execute();
                while ($fallbackRow = $fallbackBatchStmt->fetch(PDO::FETCH_ASSOC)) {
                    $batches[] = $fallbackRow['batch'];
                }
                if (!empty($batches)) {
                    $batches = array_values(array_unique($batches, SORT_STRING));
                    rsort($batches, SORT_STRING);
                }
                $usedBatchFallback = !empty($batches);
            }
        }

        $adviserQuery = "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, username, TRIM(program) AS program
                         FROM adviser
                         WHERE program IS NOT NULL AND TRIM(program) != ''
                         ORDER BY first_name, last_name";
        $adviserStmt = $conn->prepare($adviserQuery);
        $adviserStmt->execute();
        while ($adviserRow = $adviserStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($selectedProgram === '' || amNormalizeProgramKey((string)$adviserRow['program']) === $selectedProgram) {
                $advisers[] = [
                    'id' => $adviserRow['id'],
                    'full_name' => $adviserRow['full_name'],
                    'username' => $adviserRow['username'],
                    'program_key' => amNormalizeProgramKey((string)$adviserRow['program']),
                ];
            }
        }

        $assignmentQuery = "SELECT ab.batch, a.username, CONCAT(a.first_name, ' ', a.last_name) as full_name, TRIM(a.program) AS program
                            FROM adviser_batch ab
                            INNER JOIN adviser a ON ab.adviser_id = a.id
                            ORDER BY ab.batch DESC";
        $assignmentStmt = $conn->prepare($assignmentQuery);
        $assignmentStmt->execute();

        while ($row = $assignmentStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($selectedProgram !== '' && amNormalizeProgramKey((string)$row['program']) !== $selectedProgram) {
                continue;
            }

            $batch = (string)$row['batch'];
            if (!isset($batchAssignments[$batch])) {
                $batchAssignments[$batch] = [];
            }

            $batchAssignments[$batch][] = [
                'username' => $row['username'],
                'full_name' => htmlspecialchars((string)$row['full_name']),
            ];
        }

        return [
            'selectedProgram' => $selectedProgram,
            'availablePrograms' => $availablePrograms,
            'batches' => $batches,
            'advisers' => $advisers,
            'batchAssignments' => $batchAssignments,
            'usedBatchFallback' => $usedBatchFallback,
        ];
    }
}

if (!function_exists('amBuildDebugHtml')) {
    function amBuildDebugHtml(PDO $conn, string $selectedProgram, array $availablePrograms, array $advisers, array $batches): string
    {
        $html = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; margin: 20px; border-radius: 8px; font-family: monospace; font-size: 12px;">';
        $html .= '<h3>DEBUG INFO</h3>';
        $html .= '<strong>Selected Program:</strong> ' . htmlspecialchars($selectedProgram) . '<br>';
        $html .= '<strong>Available Programs:</strong><br>';

        foreach ($availablePrograms as $k => $v) {
            $html .= '&nbsp;&nbsp;' . htmlspecialchars((string)$k) . ' -> ' . htmlspecialchars((string)$v) . '<br>';
        }

        $html .= '<strong>Raw Adviser Programs:</strong><br>';
        $debugStmt = $conn->query("SELECT DISTINCT TRIM(program) as prog FROM adviser WHERE program IS NOT NULL ORDER BY prog");
        while ($row = $debugStmt->fetch(PDO::FETCH_ASSOC)) {
            $norm = amNormalizeProgramKey((string)$row['prog']);
            $html .= '&nbsp;&nbsp;' . htmlspecialchars((string)$row['prog']) . ' -> normalized: ' . htmlspecialchars($norm) . '<br>';
        }

        $html .= '<strong>Adviser Count:</strong> ' . count($advisers) . '<br>';
        foreach ($advisers as $adv) {
            $html .= '&nbsp;&nbsp;' . htmlspecialchars((string)$adv['full_name']) . ' (' . htmlspecialchars((string)$adv['program_key']) . ')<br>';
        }

        $html .= '<strong>Batch Count:</strong> ' . count($batches) . '</div>';

        return $html;
    }
}
