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

if (!function_exists('amExpandProgramScopeKeys')) {
    function amExpandProgramScopeKeys(array $programKeys): array
    {
        $expanded = [];

        foreach ($programKeys as $programKey) {
            $programKey = trim((string)$programKey);
            if ($programKey === '') {
                continue;
            }

            $expanded[$programKey] = true;

            if (str_starts_with($programKey, 'BSBA')) {
                $expanded['BSBA-MM'] = true;
                $expanded['BSBA-HRM'] = true;
            }
        }

        return array_values(array_keys($expanded));
    }
}

if (!function_exists('amFetchAssocRows')) {
    function amFetchAssocRows($conn, string $sql): array
    {
        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }

        if ($result instanceof PDOStatement) {
            return $result->fetchAll(PDO::FETCH_ASSOC);
        }

        $rows = [];
        if (method_exists($result, 'fetch_assoc')) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }

        if (method_exists($result, 'fetch')) {
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}

if (!function_exists('amFetchColumnValues')) {
    function amFetchColumnValues($conn, string $sql, string $column): array
    {
        $values = [];
        foreach (amFetchAssocRows($conn, $sql) as $row) {
            $values[] = $row[$column] ?? null;
        }

        return $values;
    }
}

if (!function_exists('amLoadAdviserManagementData')) {
    function amLoadAdviserManagementData($conn, string $selectedProgram): array
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
        $rawPrograms = amFetchColumnValues($conn, $programQuery, 'program');

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

        $scopedProgramKeys = $selectedProgram !== '' ? amExpandProgramScopeKeys([$selectedProgram]) : [];

        if ($selectedProgram !== '') {
            $batchQuery = "SELECT DISTINCT LEFT(student_number, 4) as batch, TRIM(program) AS program
                           FROM student_info
                           WHERE student_number IS NOT NULL
                             AND student_number != ''
                           ORDER BY batch DESC";
            foreach (amFetchAssocRows($conn, $batchQuery) as $batchRow) {
                if (in_array(amNormalizeProgramKey((string)$batchRow['program']), $scopedProgramKeys, true)) {
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
                foreach (amFetchAssocRows($conn, $fallbackBatchQuery) as $fallbackRow) {
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
        foreach (amFetchAssocRows($conn, $adviserQuery) as $adviserRow) {
            if ($selectedProgram === '' || in_array(amNormalizeProgramKey((string)$adviserRow['program']), $scopedProgramKeys, true)) {
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

        foreach (amFetchAssocRows($conn, $assignmentQuery) as $row) {
            if ($selectedProgram !== '' && !in_array(amNormalizeProgramKey((string)$row['program']), $scopedProgramKeys, true)) {
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
    function amBuildDebugHtml($conn, string $selectedProgram, array $availablePrograms, array $advisers, array $batches): string
    {
        $html = '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; margin: 20px; border-radius: 8px; font-family: monospace; font-size: 12px;">';
        $html .= '<h3>DEBUG INFO</h3>';
        $html .= '<strong>Selected Program:</strong> ' . htmlspecialchars($selectedProgram) . '<br>';
        $html .= '<strong>Available Programs:</strong><br>';

        foreach ($availablePrograms as $k => $v) {
            $html .= '&nbsp;&nbsp;' . htmlspecialchars((string)$k) . ' -> ' . htmlspecialchars((string)$v) . '<br>';
        }

        $html .= '<strong>Raw Adviser Programs:</strong><br>';
        foreach (amFetchAssocRows($conn, "SELECT DISTINCT TRIM(program) as prog FROM adviser WHERE program IS NOT NULL ORDER BY prog") as $row) {
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
