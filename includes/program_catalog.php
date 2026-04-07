<?php

function pcDefaultProgramCatalog(): array
{
    return [
        'BSIndT' => 'BS Industrial Technology',
        'BSCpE' => 'BS Computer Engineering',
        'BSIT' => 'BS Information Technology',
        'BSCS' => 'BS Computer Science',
        'BSHM' => 'BS Hospitality Management',
        'BSBA-HRM' => 'BSBA - Human Resource Management',
        'BSBA-MM' => 'BSBA - Marketing Management',
        'BSEd-English' => 'BSEd Major in English',
        'BSEd-Science' => 'BSEd Major in Science',
        'BSEd-Math' => 'BSEd Major in Math',
    ];
}

function pcNormalizeProgramLabel(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function pcNormalizeProgramCode(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $upper = strtoupper($value);
    $known = [
        'BSINDT' => 'BSIndT',
        'BSCPE' => 'BSCpE',
        'BSIT' => 'BSIT',
        'BSCS' => 'BSCS',
        'BSHM' => 'BSHM',
        'BSBA-HRM' => 'BSBA-HRM',
        'BSBA-MM' => 'BSBA-MM',
        'BSED-ENGLISH' => 'BSEd-English',
        'BSED-SCIENCE' => 'BSEd-Science',
        'BSED-MATH' => 'BSEd-Math',
    ];

    if (isset($known[$upper])) {
        return $known[$upper];
    }

    $sanitized = preg_replace('/[^A-Za-z0-9-]+/', '', $value) ?? '';
    if ($sanitized === '' || !preg_match('/^[A-Za-z][A-Za-z0-9-]{1,63}$/', $sanitized)) {
        return '';
    }

    return $sanitized;
}

function pcEnsureProgramCatalogTable(mysqli $conn): void
{
    $tableExists = $conn->query("SHOW TABLES LIKE 'programs'");
    if (!$tableExists || $tableExists->num_rows === 0) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_program_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return;
    }

    $columns = [];
    $columnResult = $conn->query("SHOW COLUMNS FROM programs");
    if ($columnResult) {
        while ($row = $columnResult->fetch_assoc()) {
            $columns[] = strtolower((string) ($row['Field'] ?? ''));
        }
    }

    if (!in_array('code', $columns, true)) {
        $conn->query("ALTER TABLE programs ADD COLUMN code VARCHAR(64) NULL AFTER id");
    }
    if (!in_array('name', $columns, true)) {
        $conn->query("ALTER TABLE programs ADD COLUMN name VARCHAR(255) NULL AFTER code");
    }
    if (!in_array('created_at', $columns, true)) {
        $conn->query("ALTER TABLE programs ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
    if (!in_array('updated_at', $columns, true)) {
        $conn->query("ALTER TABLE programs ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }

    $defaultsByLabel = array_flip(pcDefaultProgramCatalog());
    $rows = $conn->query("SELECT id, code, name FROM programs ORDER BY id ASC");
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            $name = pcNormalizeProgramLabel((string) ($row['name'] ?? ''));
            $code = pcNormalizeProgramCode((string) ($row['code'] ?? ''));

            if ($code === '' && $name !== '') {
                $code = $defaultsByLabel[$name] ?? pcNormalizeProgramCode($name);
            }

            if ($id > 0 && $code !== '') {
                $stmt = $conn->prepare("UPDATE programs SET code = ?, name = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ssi', $code, $name, $id);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}

function pcLoadProgramCatalog(mysqli $conn, bool $mergeDefaults = true): array
{
    pcEnsureProgramCatalogTable($conn);

    $catalog = $mergeDefaults ? pcDefaultProgramCatalog() : [];

    $sources = [];

    $programRows = $conn->query("SELECT code, name FROM programs ORDER BY name ASC");
    if ($programRows) {
        while ($row = $programRows->fetch_assoc()) {
            $code = pcNormalizeProgramCode((string) ($row['code'] ?? ''));
            $name = pcNormalizeProgramLabel((string) ($row['name'] ?? ''));
            if ($code !== '' && $name !== '') {
                $sources[$code] = $name;
            }
        }
    }

    $yearRows = $conn->query("SELECT DISTINCT program FROM program_curriculum_years");
    if ($yearRows) {
        while ($row = $yearRows->fetch_assoc()) {
            $code = pcNormalizeProgramCode((string) ($row['program'] ?? ''));
            if ($code !== '' && !isset($sources[$code])) {
                $sources[$code] = $catalog[$code] ?? $code;
            }
        }
    }

    $courseRows = $conn->query("SELECT DISTINCT programs FROM cvsucarmona_courses WHERE programs IS NOT NULL AND programs <> ''");
    if ($courseRows) {
        while ($row = $courseRows->fetch_assoc()) {
            $programList = array_map('trim', explode(',', (string) ($row['programs'] ?? '')));
            foreach ($programList as $program) {
                $code = pcNormalizeProgramCode($program);
                if ($code !== '' && !isset($sources[$code])) {
                    $sources[$code] = $catalog[$code] ?? $code;
                }
            }
        }
    }

    foreach ($sources as $code => $name) {
        $catalog[$code] = $name;
    }

    asort($catalog, SORT_NATURAL | SORT_FLAG_CASE);
    return $catalog;
}

function pcSaveProgramCatalogEntry(mysqli $conn, string $code, string $name): array
{
    pcEnsureProgramCatalogTable($conn);

    $code = pcNormalizeProgramCode($code);
    $name = pcNormalizeProgramLabel($name);

    if ($code === '' || $name === '') {
        return ['success' => false, 'message' => 'Program code and program name are required.'];
    }

    $existingByCode = $conn->prepare("SELECT id FROM programs WHERE code = ? LIMIT 1");
    if ($existingByCode) {
        $existingByCode->bind_param('s', $code);
        $existingByCode->execute();
        $existingResult = $existingByCode->get_result();
        if ($existingResult && $existingResult->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE programs SET name = ? WHERE code = ?");
            if ($stmt) {
                $stmt->bind_param('ss', $name, $code);
                $stmt->execute();
                $stmt->close();
            }
            $existingByCode->close();
            return ['success' => true, 'message' => 'Program updated successfully.', 'code' => $code, 'name' => $name];
        }
        $existingByCode->close();
    }

    $existingByName = $conn->prepare("SELECT code FROM programs WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($existingByName) {
        $existingByName->bind_param('s', $name);
        $existingByName->execute();
        $nameResult = $existingByName->get_result();
        if ($nameResult && $nameResult->num_rows > 0) {
            $row = $nameResult->fetch_assoc();
            $existingCode = pcNormalizeProgramCode((string) ($row['code'] ?? ''));
            $existingByName->close();
            return ['success' => false, 'message' => 'This program name already exists under code ' . ($existingCode !== '' ? $existingCode : 'N/A') . '.'];
        }
        $existingByName->close();
    }

    $stmt = $conn->prepare("INSERT INTO programs (code, name) VALUES (?, ?)");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to save the new program right now.'];
    }

    $stmt->bind_param('ss', $code, $name);
    $stmt->execute();
    $stmt->close();

    return ['success' => true, 'message' => 'Program added successfully.', 'code' => $code, 'name' => $name];
}

