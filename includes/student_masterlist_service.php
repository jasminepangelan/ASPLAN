<?php
/**
 * Official student masterlist service.
 *
 * Admin uploads one CSV per program. Only students present in the
 * official masterlist are allowed to register and access the system.
 */

require_once __DIR__ . '/program_catalog.php';

if (!function_exists('smlCanonicalProgramLabel')) {
    function smlCanonicalProgramLabel(string $program): string
    {
        $program = trim($program);
        if ($program === '') {
            return '';
        }

        $code = function_exists('pcNormalizeProgramCode') ? pcNormalizeProgramCode($program) : '';
        $map = [
            'BSBA-MM' => 'Bachelor of Science in Business Administration - Major in Marketing Management',
            'BSBA-HRM' => 'Bachelor of Science in Business Administration - Major in Human Resource Management',
            'BSCpE' => 'Bachelor of Science in Computer Engineering',
            'BSCS' => 'Bachelor of Science in Computer Science',
            'BSHM' => 'Bachelor of Science in Hospitality Management',
            'BSIndT' => 'Bachelor of Science in Industrial Technology',
            'BSIT' => 'Bachelor of Science in Information Technology',
            'BSEd-English' => 'Bachelor of Secondary Education Major in English',
            'BSEd-Math' => 'Bachelor of Secondary Education Major in Mathematics',
            'BSEd-Science' => 'Bachelor of Secondary Education Major in Science',
        ];

        if ($code !== '' && isset($map[$code])) {
            return $map[$code];
        }

        return preg_replace('/\s+/', ' ', $program) ?? $program;
    }
}

if (!function_exists('smlNormalizeCompareValue')) {
    function smlNormalizeCompareValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = preg_replace('/[^[:alnum:]\s-]+/u', '', $value) ?? $value;
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }
}

if (!function_exists('smlHeaderKey')) {
    function smlHeaderKey(string $value): string
    {
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        return strtolower(preg_replace('/[^a-z0-9]+/', '', trim($value)) ?? '');
    }
}

if (!function_exists('smlExtractMiddleInitial')) {
    function smlExtractMiddleInitial(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            $initial = mb_substr($value, 0, 1, 'UTF-8');
            return function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
        }

        return strtoupper(substr($value, 0, 1));
    }
}

if (!function_exists('smlEnsureMasterlistTable')) {
    function smlEnsureMasterlistTable($conn): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS student_masterlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_number VARCHAR(32) NOT NULL,
            last_name VARCHAR(150) NOT NULL,
            first_name VARCHAR(150) NOT NULL,
            middle_initial VARCHAR(8) NULL,
            program VARCHAR(255) NOT NULL,
            source_filename VARCHAR(255) NULL,
            uploaded_by VARCHAR(120) NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_masterlist_student_number (student_number),
            KEY idx_student_masterlist_program (program),
            KEY idx_student_masterlist_uploaded_at (uploaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($conn instanceof PDO) {
            $conn->exec($sql);
            try {
                $columns = $conn->query("SHOW COLUMNS FROM student_masterlist")->fetchAll(PDO::FETCH_COLUMN, 0);
                $normalizedColumns = array_map(static fn ($value) => strtolower((string) $value), $columns ?: []);
                if (!in_array('middle_initial', $normalizedColumns, true)) {
                    $conn->exec("ALTER TABLE student_masterlist ADD COLUMN middle_initial VARCHAR(8) NULL AFTER first_name");
                }
            } catch (Throwable $e) {
                // Keep bootstrap resilient even if column introspection fails.
            }
            return;
        }

        if (is_object($conn) && method_exists($conn, 'query')) {
            $conn->query($sql);
            $columns = [];
            $result = $conn->query("SHOW COLUMNS FROM student_masterlist");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $columns[] = strtolower((string) ($row['Field'] ?? ''));
                }
            }

            if (!in_array('middle_initial', $columns, true)) {
                $conn->query("ALTER TABLE student_masterlist ADD COLUMN middle_initial VARCHAR(8) NULL AFTER first_name");
            }
        }
    }
}

if (!function_exists('smlLoadProgramOptions')) {
    function smlLoadProgramOptions(PDO $conn): array
    {
        smlEnsureMasterlistTable($conn);

        $options = [];

        $append = static function (string $program) use (&$options): void {
            $canonical = smlCanonicalProgramLabel($program);
            if ($canonical !== '') {
                $options[$canonical] = $canonical;
            }
        };

        foreach (pcDefaultProgramCatalog() as $code => $name) {
            $append($code);
            $append($name);
        }

        $queries = [
            "SELECT name AS program_name FROM programs",
            "SELECT DISTINCT program AS program_name FROM program_curriculum_years",
            "SELECT DISTINCT program AS program_name FROM student_info",
        ];

        foreach ($queries as $query) {
            try {
                $rows = $conn->query($query);
                if ($rows instanceof PDOStatement) {
                    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $append((string) ($row['program_name'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
                // Ignore missing legacy tables; the defaults still populate the picker.
            }
        }

        try {
            $courseRows = $conn->query("SELECT DISTINCT programs FROM cvsucarmona_courses WHERE programs IS NOT NULL AND TRIM(programs) <> ''");
            if ($courseRows instanceof PDOStatement) {
                foreach ($courseRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $programList = array_map('trim', explode(',', (string) ($row['programs'] ?? '')));
                    foreach ($programList as $program) {
                        $append($program);
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore missing course catalog tables.
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }
}

if (!function_exists('smlLoadMasterlistSummary')) {
    function smlLoadMasterlistSummary(PDO $conn): array
    {
        smlEnsureMasterlistTable($conn);

        $stmt = $conn->query("
            SELECT
                base.program,
                base.total_students,
                base.last_uploaded_at,
                (
                    SELECT latest.uploaded_by
                    FROM student_masterlist AS latest
                    WHERE latest.program = base.program
                    ORDER BY latest.uploaded_at DESC, latest.id DESC
                    LIMIT 1
                ) AS uploaded_by
            FROM (
                SELECT program, COUNT(*) AS total_students, MAX(uploaded_at) AS last_uploaded_at
                FROM student_masterlist
                GROUP BY program
            ) AS base
            ORDER BY base.program ASC
        ");

        if (!$stmt instanceof PDOStatement) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('smlParseCsvUpload')) {
    function smlParseCsvUpload(string $tmpPath): array
    {
        $handle = @fopen($tmpPath, 'rb');
        if ($handle === false) {
            return ['success' => false, 'message' => 'Unable to read the uploaded CSV file.'];
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return ['success' => false, 'message' => 'The uploaded CSV file is empty.'];
        }

        $headerMap = [];
        foreach ($header as $index => $column) {
            $headerMap[smlHeaderKey((string) $column)] = $index;
        }

        $requiredColumns = [
            'studentnumber' => 'Student Number',
            'lastname' => 'Last name',
            'firstname' => 'First name',
        ];

        foreach ($requiredColumns as $requiredKey => $label) {
            if (!array_key_exists($requiredKey, $headerMap)) {
                fclose($handle);
                return ['success' => false, 'message' => 'CSV must include the columns: Student Number, Last name, and First name. Middle Initial is optional.'];
            }
        }

        $middleInitialIndex = null;
        foreach (['middleinitial', 'middle', 'mi', 'middlename'] as $optionalKey) {
            if (array_key_exists($optionalKey, $headerMap)) {
                $middleInitialIndex = (int) $headerMap[$optionalKey];
                break;
            }
        }

        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            $studentNumber = trim((string) ($data[$headerMap['studentnumber']] ?? ''));
            $lastName = trim((string) ($data[$headerMap['lastname']] ?? ''));
            $firstName = trim((string) ($data[$headerMap['firstname']] ?? ''));
            $middleInitial = $middleInitialIndex !== null
                ? smlExtractMiddleInitial((string) ($data[$middleInitialIndex] ?? ''))
                : '';

            if ($studentNumber === '' && $lastName === '' && $firstName === '' && $middleInitial === '') {
                continue;
            }

            if ($studentNumber === '' || $lastName === '' || $firstName === '') {
                fclose($handle);
                return ['success' => false, 'message' => 'Each masterlist row must include Student Number, Last name, and First name. Middle Initial may be blank if needed. Problem found near line ' . $line . '.'];
            }

            $rows[] = [
                'student_number' => $studentNumber,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_initial' => $middleInitial,
            ];
        }

        fclose($handle);

        if (empty($rows)) {
            return ['success' => false, 'message' => 'The uploaded CSV does not contain any student rows.'];
        }

        return ['success' => true, 'rows' => $rows];
    }
}

if (!function_exists('smlReplaceProgramMasterlist')) {
    function smlReplaceProgramMasterlist(PDO $conn, string $program, array $rows, string $sourceFilename, string $adminId): array
    {
        smlEnsureMasterlistTable($conn);

        $program = smlCanonicalProgramLabel($program);
        if ($program === '') {
            return ['success' => false, 'message' => 'Please choose a valid program for the uploaded masterlist.'];
        }

        if (empty($rows)) {
            return ['success' => false, 'message' => 'The uploaded masterlist does not contain any students.'];
        }

        $conn->beginTransaction();
        try {
            $deleteStmt = $conn->prepare('DELETE FROM student_masterlist WHERE program = ?');
            $deleteStmt->execute([$program]);

            $insertStmt = $conn->prepare("
                INSERT INTO student_masterlist (student_number, last_name, first_name, middle_initial, program, source_filename, uploaded_by, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    last_name = VALUES(last_name),
                    first_name = VALUES(first_name),
                    middle_initial = VALUES(middle_initial),
                    program = VALUES(program),
                    source_filename = VALUES(source_filename),
                    uploaded_by = VALUES(uploaded_by),
                    uploaded_at = NOW()
            ");

            foreach ($rows as $row) {
                $insertStmt->execute([
                    trim((string) ($row['student_number'] ?? '')),
                    trim((string) ($row['last_name'] ?? '')),
                    trim((string) ($row['first_name'] ?? '')),
                    trim((string) ($row['middle_initial'] ?? '')),
                    $program,
                    substr($sourceFilename, 0, 255),
                    substr($adminId, 0, 120),
                ]);
            }

            $conn->commit();

            if (function_exists('aasWriteAdminAuditLog')) {
                aasWriteAdminAuditLog(
                    $conn,
                    $adminId,
                    'masterlist_upload',
                    $program,
                    'Uploaded official student masterlist for program.',
                    ['program' => $program, 'row_count' => count($rows), 'source_filename' => $sourceFilename]
                );
            }

            return [
                'success' => true,
                'message' => 'Official masterlist uploaded for ' . $program . '. ' . count($rows) . ' student record(s) are now authorized for this program.',
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            return ['success' => false, 'message' => 'Failed to save the masterlist. Please check the CSV and try again.'];
        }
    }
}

if (!function_exists('smlFindMasterlistRecord')) {
    function smlFindMasterlistRecord($conn, string $studentNumber): ?array
    {
        smlEnsureMasterlistTable($conn);

        if ($conn instanceof PDO) {
            $stmt = $conn->prepare('SELECT student_number, last_name, first_name, middle_initial, program FROM student_masterlist WHERE student_number = ? LIMIT 1');
            $stmt->execute([$studentNumber]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        if (is_object($conn) && method_exists($conn, 'prepare')) {
            $stmt = $conn->prepare('SELECT student_number, last_name, first_name, middle_initial, program FROM student_masterlist WHERE student_number = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $studentNumber);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                return is_array($row) ? $row : null;
            }
        }

        return null;
    }
}

if (!function_exists('smlStudentIdAllowedForRegistration')) {
    function smlStudentIdAllowedForRegistration($conn, string $studentNumber): array
    {
        $record = smlFindMasterlistRecord($conn, $studentNumber);
        if ($record === null) {
            return [
                'allowed' => false,
                'message' => 'This student number is not included in the official masterlist. Please contact the administrator.',
            ];
        }

        return ['allowed' => true, 'record' => $record];
    }
}

if (!function_exists('smlValidateStudentRegistrationAgainstMasterlist')) {
    function smlValidateStudentRegistrationAgainstMasterlist($conn, array $formData): array
    {
        $studentNumber = trim((string) ($formData['student_id'] ?? ''));
        $record = smlFindMasterlistRecord($conn, $studentNumber);
        if ($record === null) {
            return [
                'valid' => false,
                'message' => 'Only students included in the official masterlist can create an account.',
            ];
        }

        $inputLast = smlNormalizeCompareValue((string) ($formData['last_name'] ?? ''));
        $inputFirst = smlNormalizeCompareValue((string) ($formData['first_name'] ?? ''));
        $inputMiddleInitial = smlExtractMiddleInitial((string) ($formData['middle_name'] ?? ''));
        $recordLast = smlNormalizeCompareValue((string) ($record['last_name'] ?? ''));
        $recordFirst = smlNormalizeCompareValue((string) ($record['first_name'] ?? ''));
        $recordMiddleInitial = smlExtractMiddleInitial((string) ($record['middle_initial'] ?? ''));

        if ($inputLast === '' || $inputFirst === '' || $inputLast !== $recordLast || $inputFirst !== $recordFirst) {
            return [
                'valid' => false,
                'message' => 'Your student name does not match the official masterlist for the provided student number.',
            ];
        }

        if ($recordMiddleInitial !== '' && $inputMiddleInitial !== $recordMiddleInitial) {
            return [
                'valid' => false,
                'message' => 'Your middle initial does not match the official masterlist for the provided student number.',
            ];
        }

        $selectedProgram = smlCanonicalProgramLabel((string) ($formData['program'] ?? ''));
        $recordProgram = smlCanonicalProgramLabel((string) ($record['program'] ?? ''));
        if ($selectedProgram === '' || $recordProgram === '' || $selectedProgram !== $recordProgram) {
            return [
                'valid' => false,
                'message' => 'The selected program does not match the official masterlist for this student number.',
            ];
        }

        return ['valid' => true, 'record' => $record];
    }
}

if (!function_exists('smlStudentHasSystemAccess')) {
    function smlStudentHasSystemAccess($conn, string $studentNumber): bool
    {
        return smlFindMasterlistRecord($conn, $studentNumber) !== null;
    }
}
