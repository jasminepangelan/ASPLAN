<?php

require_once __DIR__ . '/student_current_enrollment_service.php';

if (!function_exists('ctlsNormalizeTermYearLabel')) {
    function ctlsNormalizeTermYearLabel(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        $map = [
            'FIRST YEAR' => '1ST YR',
            '1ST YEAR' => '1ST YR',
            '1ST YR' => '1ST YR',
            'SECOND YEAR' => '2ND YR',
            '2ND YEAR' => '2ND YR',
            '2ND YR' => '2ND YR',
            'THIRD YEAR' => '3RD YR',
            '3RD YEAR' => '3RD YR',
            '3RD YR' => '3RD YR',
            'FOURTH YEAR' => '4TH YR',
            '4TH YEAR' => '4TH YR',
            '4TH YR' => '4TH YR',
        ];

        return $map[$normalized] ?? $normalized;
    }
}

if (!function_exists('ctlsNormalizeTermSemesterLabel')) {
    function ctlsNormalizeTermSemesterLabel(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        $map = [
            'FIRST SEMESTER' => '1ST SEM',
            '1ST SEMESTER' => '1ST SEM',
            '1ST SEM' => '1ST SEM',
            'SECOND SEMESTER' => '2ND SEM',
            '2ND SEMESTER' => '2ND SEM',
            '2ND SEM' => '2ND SEM',
            'MID YEAR' => 'MID YEAR',
            'MIDYEAR' => 'MID YEAR',
        ];

        return $map[$normalized] ?? $normalized;
    }
}

if (!function_exists('ctlsBuildTermKey')) {
    function ctlsBuildTermKey(string $yearLevel, string $semester): string
    {
        $year = ctlsNormalizeTermYearLabel($yearLevel);
        $sem = ctlsNormalizeTermSemesterLabel($semester);
        if ($year === '' || $sem === '') {
            return '';
        }

        return $year . '|' . $sem;
    }
}

if (!function_exists('ctlsParseChecklistRowKeyTerm')) {
    function ctlsParseChecklistRowKeyTerm(string $courseRowKey): array
    {
        $courseRowKey = trim($courseRowKey);
        if ($courseRowKey === '') {
            return [];
        }

        $parts = explode('|', $courseRowKey);
        if (count($parts) < 4) {
            return [];
        }

        $semester = trim((string) array_pop($parts));
        $year = trim((string) array_pop($parts));

        $yearKey = ctlsNormalizeTermYearLabel($year);
        $semesterKey = ctlsNormalizeTermSemesterLabel($semester);
        if ($yearKey === '' || $semesterKey === '') {
            return [];
        }

        return [
            'year' => $yearKey,
            'semester' => $semesterKey,
        ];
    }
}

if (!function_exists('ctlsLoadStudentCurrentEnrollmentTerm')) {
    function ctlsLoadStudentCurrentEnrollmentTerm($conn, string $studentId): ?array
    {
        // student_current_enrollment_service currently expects mysqli.
        // When the app is running through PDO fallback, skip this optional
        // lookup instead of crashing with a TypeError.
        if (!($conn instanceof mysqli)) {
            return null;
        }

        if (!function_exists('sceLoadStudentCurrentEnrollment')) {
            return null;
        }

        $enrollment = sceLoadStudentCurrentEnrollment($conn, $studentId);
        if (!is_array($enrollment)) {
            return null;
        }

        $yearKey = ctlsNormalizeTermYearLabel((string) ($enrollment['year_level'] ?? ''));
        $semesterKey = ctlsNormalizeTermSemesterLabel((string) ($enrollment['semester'] ?? ''));
        if ($yearKey === '' || $semesterKey === '') {
            return null;
        }

        return [
            'year' => $yearKey,
            'semester' => $semesterKey,
        ];
    }
}

if (!function_exists('ctlsIsChecklistRowLockedToCurrentTerm')) {
    function ctlsIsChecklistRowLockedToCurrentTerm(string $courseRowKey, ?array $currentTerm): bool
    {
        if (empty($currentTerm)) {
            return false;
        }

        $rowTerm = ctlsParseChecklistRowKeyTerm($courseRowKey);
        if (empty($rowTerm)) {
            return false;
        }

        return $rowTerm['year'] !== ($currentTerm['year'] ?? '')
            || $rowTerm['semester'] !== ($currentTerm['semester'] ?? '');
    }
}

if (!function_exists('ctlsDescribeChecklistTerm')) {
    function ctlsDescribeChecklistTerm(?array $term): string
    {
        if (empty($term)) {
            return '';
        }

        $year = trim((string) ($term['year'] ?? ''));
        $semester = trim((string) ($term['semester'] ?? ''));
        if ($year === '' || $semester === '') {
            return '';
        }

        return $year . ' - ' . $semester;
    }
}
