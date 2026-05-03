<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/security_policy.php';
require_once __DIR__ . '/program_shift_schema.php';

if (!function_exists('psEnsureProgramShiftTables')) {
    function psEnsureProgramShiftTables($conn) {
        // Deprecated compatibility wrapper: request-time DDL has been moved
        // to dev/migrations/apply_program_shift_schema.php.
        psAssertProgramShiftSchemaReady($conn);
    }
}

if (!function_exists('psNormalizeProgramLabel')) {
    function psNormalizeProgramLabel($value) {
        $value = strtoupper(trim((string)$value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value);
        return $value ?? '';
    }
}

if (!function_exists('psAcronymFromPhrase')) {
    function psAcronymFromPhrase($text) {
        $cleaned = strtoupper(trim((string)$text));
        if ($cleaned === '') {
            return '';
        }

        $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
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

if (!function_exists('psNormalizeProgramKey')) {
    function psNormalizeProgramKey($programName) {
        $programName = trim((string)$programName);
        if ($programName === '') {
            return '';
        }

        $normalized = strtoupper((string)preg_replace('/\s+/', ' ', $programName));

        if ((strpos($normalized, 'BUSINESS ADMINISTRATION') !== false || strpos($normalized, 'BSBA') !== false) && strpos($normalized, 'HUMAN RESOURCE') !== false) {
            return 'BSBA-HRM';
        }
        if ((strpos($normalized, 'BUSINESS ADMINISTRATION') !== false || strpos($normalized, 'BSBA') !== false) && strpos($normalized, 'MARKETING') !== false) {
            return 'BSBA-MM';
        }
        if (strpos($normalized, 'INFORMATION TECHNOLOGY') !== false) {
            return 'BSIT';
        }
        if (strpos($normalized, 'INDUSTRIAL TECHNOLOGY') !== false) {
            return 'BSINDT';
        }
        if (strpos($normalized, 'COMPUTER ENGINEERING') !== false) {
            return 'BSCPE';
        }
        if (strpos($normalized, 'CIVIL ENGINEERING') !== false) {
            return 'BSCE';
        }
        if (strpos($normalized, 'ELECTRICAL ENGINEERING') !== false) {
            return 'BSEE';
        }
        if (strpos($normalized, 'MECHANICAL ENGINEERING') !== false) {
            return 'BSME';
        }
        if ((strpos($normalized, 'SECONDARY EDUCATION') !== false || strpos($normalized, 'BSED') !== false) && strpos($normalized, 'ENGLISH') !== false) {
            return 'BSED-ENGLISH';
        }
        if ((strpos($normalized, 'SECONDARY EDUCATION') !== false || strpos($normalized, 'BSED') !== false) && (strpos($normalized, 'MATH') !== false || strpos($normalized, 'MATHEMATICS') !== false)) {
            return 'BSED-MATH';
        }
        if ((strpos($normalized, 'SECONDARY EDUCATION') !== false || strpos($normalized, 'BSED') !== false) && strpos($normalized, 'SCIENCE') !== false) {
            return 'BSED-SCIENCE';
        }

        if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN)\b/', $normalized, $codeMatch)) {
            $baseCode = strtoupper($codeMatch[1]);
        } elseif (strpos($normalized, 'BS ') === 0) {
            $subject = trim(substr($normalized, 3));
            $baseCode = 'BS' . psAcronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
            $baseCode = 'BS' . psAcronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
            $baseCode = 'BSED';
        } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
            $baseCode = 'BEED';
        } else {
            $baseCode = strtoupper($programName);
        }

        $majorKey = '';
        if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
            $majorKey = psAcronymFromPhrase($majorMatch[1]);
        }

        return $majorKey !== '' ? ($baseCode . '-' . $majorKey) : $baseCode;
    }
}

if (!function_exists('psExpandProgramKeyAliases')) {
    function psExpandProgramKeyAliases(array $keys) {
        $expanded = [];
        foreach ($keys as $key) {
            $normalized = strtoupper(trim((string)$key));
            if ($normalized === '') {
                continue;
            }

            $expanded[$normalized] = true;

            // Normalize common variants used across legacy records.
            if ($normalized === 'BSCPE' || $normalized === 'BSCOE') {
                $expanded['BSCPE'] = true;
                $expanded['BSCOE'] = true;
            }

            if ($normalized === 'BSINDT' || $normalized === 'BSINDTECH') {
                $expanded['BSINDT'] = true;
                $expanded['BSINDTECH'] = true;
            }
        }

        return array_keys($expanded);
    }
}

if (!function_exists('psProgramMatchesActorKeys')) {
    function psProgramMatchesActorKeys($programLabel, array $actorProgramKeys) {
        if (empty($actorProgramKeys)) {
            return true;
        }

        $programKey = psNormalizeProgramKey((string)$programLabel);
        if ($programKey === '') {
            return true;
        }

        $actorKeysExpanded = psExpandProgramKeyAliases($actorProgramKeys);
        $programKeysExpanded = psExpandProgramKeyAliases([$programKey]);

        return !empty(array_intersect($programKeysExpanded, $actorKeysExpanded));
    }
}

if (!function_exists('psParseProgramList')) {
    function psParseProgramList($programRaw) {
        $programRaw = trim((string)$programRaw);
        if ($programRaw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\r\n|\r|\n)\s*/', $programRaw);
        $normalized = [];
        foreach ($parts as $part) {
            $key = psNormalizeProgramKey($part);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized, SORT_STRING));
    }
}

if (!function_exists('psGetCurrentStudentInfo')) {
    function psGetCurrentStudentInfo($conn, $studentNumber) {
        $stmt = $conn->prepare("SELECT student_number, last_name, first_name, middle_name, program, curriculum_year, email, strand FROM student_info WHERE student_number = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $studentNumber = trim((string)$studentNumber);
        $stmt->bind_param('s', $studentNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('psGetStudentDisplayName')) {
    function psGetStudentDisplayName(array $studentRow) {
        $last = trim((string)($studentRow['last_name'] ?? ''));
        $first = trim((string)($studentRow['first_name'] ?? ''));
        $middle = trim((string)($studentRow['middle_name'] ?? ''));

        $fullName = $last;
        if ($first !== '') {
            $fullName .= ($fullName === '' ? '' : ', ') . $first;
        }
        if ($middle !== '') {
            $fullName .= ' ' . $middle;
        }

        return trim($fullName);
    }
}

if (!function_exists('psGenerateRequestCode')) {
    function psGenerateRequestCode() {
        return 'SHIFT-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}

if (!function_exists('psAddAuditLog')) {
    function psAddAuditLog($conn, $requestId, $eventKey, $eventMessage, $actorUsername = null, $actorRole = null, $metadata = null) {
        $metadataJson = null;
        if ($metadata !== null) {
            $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);
            $metadataJson = $encoded === false ? null : $encoded;
        }

        $stmt = $conn->prepare('INSERT INTO program_shift_audit (request_id, event_key, event_message, actor_username, actor_role, metadata_json) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('isssss', $requestId, $eventKey, $eventMessage, $actorUsername, $actorRole, $metadataJson);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('psTableExists')) {
    function psTableExists($conn, $tableName) {
        $tableName = trim((string)$tableName);
        if ($tableName === '') {
            return false;
        }

        $tableSafe = $conn->real_escape_string($tableName);
        try {
            $result = $conn->query("SHOW TABLES LIKE '$tableSafe'");
            return $result && $result->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('psGetProgramOptions')) {
    function psGetProgramOptions($conn) {
        $sources = [
            ['table' => 'curriculum_courses', 'column' => 'program'],
            ['table' => 'student_info', 'column' => 'program'],
            ['table' => 'program_shift_requests', 'column' => 'current_program'],
            ['table' => 'program_shift_requests', 'column' => 'requested_program'],
        ];

        $unique = [];
        foreach ($sources as $source) {
            $table = (string)$source['table'];
            $column = (string)$source['column'];
            if (!psTableExists($conn, $table)) {
                continue;
            }

            try {
                $result = $conn->query("SELECT DISTINCT TRIM($column) AS program FROM $table WHERE $column IS NOT NULL AND TRIM($column) != '' ORDER BY $column ASC");
                if (!$result) {
                    continue;
                }

                while ($row = $result->fetch_assoc()) {
                    $program = trim((string)($row['program'] ?? ''));
                    if ($program === '') {
                        continue;
                    }

                    $key = strtoupper($program);
                    if (!isset($unique[$key])) {
                        $unique[$key] = $program;
                    }
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        $options = array_values($unique);
        natcasesort($options);
        return array_values($options);
    }
}

if (!function_exists('psBuildCourseSignature')) {
    function psBuildCourseSignature(array $courseRow) {
        $code = strtoupper(trim((string)($courseRow['course_code'] ?? '')));
        $title = strtoupper(trim((string)($courseRow['course_title'] ?? '')));
        $cuLec = (int)($courseRow['credit_units_lec'] ?? 0);
        $cuLab = (int)($courseRow['credit_units_lab'] ?? 0);
        $lhLec = (int)($courseRow['lect_hrs_lec'] ?? 0);
        $lhLab = (int)($courseRow['lect_hrs_lab'] ?? 0);

        if ($title === '') {
            $title = $code;
        }

        return implode('|', [$code, $title, $cuLec, $cuLab, $lhLec, $lhLab]);
    }
}

if (!function_exists('psNormalizeStrandKey')) {
    function psNormalizeStrandKey($strand) {
        $value = strtoupper(trim((string)$strand));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string)$value);
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        if (strpos($value, 'SCIENCE TECHNOLOGY ENGINEERING MATHEMATICS') !== false || $value === 'STEM') {
            return 'STEM';
        }
        if (strpos($value, 'ACCOUNTANCY BUSINESS MANAGEMENT') !== false || $value === 'ABM') {
            return 'ABM';
        }
        if (strpos($value, 'HUMANITIES AND SOCIAL SCIENCES') !== false || $value === 'HUMSS') {
            return 'HUMSS';
        }
        if ($value === 'GAS' || strpos($value, 'GENERAL ACADEMIC') !== false) {
            return 'GAS';
        }
        if ($value === 'TVL ICT' || $value === 'TVL-ICT' || strpos($value, 'INFORMATION COMMUNICATIONS TECHNOLOGY') !== false) {
            return 'TVL-ICT';
        }
        if ($value === 'TVL HE' || $value === 'TVL-HE' || strpos($value, 'HOME ECONOMICS') !== false) {
            return 'TVL-HE';
        }
        if ($value === 'TVL IA' || $value === 'TVL-IA' || strpos($value, 'INDUSTRIAL ARTS') !== false) {
            return 'TVL-IA';
        }
        if ($value === 'TVL AFA' || $value === 'TVL-AFA' || strpos($value, 'AGRI FISHERY ARTS') !== false) {
            return 'TVL-AFA';
        }
        if ($value === 'TVL' || strpos($value, 'TECHNICAL VOCATIONAL') !== false) {
            return 'TVL';
        }
        if ($value === 'ADT' || (strpos($value, 'ARTS') !== false && strpos($value, 'DESIGN') !== false)) {
            return 'ADT';
        }
        if (strpos($value, 'SPORTS') !== false) {
            return 'SPORTS';
        }

        return $value;
    }
}

if (!function_exists('psAllowedStrandsForShiftProgram')) {
    function psAllowedStrandsForShiftProgram($programLabel) {
        $programKey = strtoupper(trim(psNormalizeProgramKey((string)$programLabel)));

        $strandMap = [
            'BSCS' => ['STEM', 'TVL-ICT', 'GAS'],
            'BSIT' => ['STEM', 'TVL-ICT', 'GAS'],
            'BSCPE' => ['STEM', 'TVL-ICT', 'TVL-IA'],
            'BSCE' => ['STEM', 'TVL-IA'],
            'BSEE' => ['STEM', 'TVL-IA'],
            'BSME' => ['STEM', 'TVL-IA'],
            'BSINDT' => ['TVL-IA', 'TVL-HE', 'TVL-AFA', 'STEM', 'GAS'],
            'BSBA-HRM' => ['ABM', 'GAS'],
            'BSBA-MM' => ['ABM', 'GAS'],
            'BSHM' => ['ABM', 'TVL-HE', 'GAS'],
            'BSTM' => ['ABM', 'HUMSS', 'TVL-HE', 'GAS'],
            'BSED-ENGLISH' => ['HUMSS', 'GAS'],
            'BSED-MATH' => ['STEM', 'GAS'],
            'BSED-SCIENCE' => ['STEM', 'GAS'],
            'BEED' => ['HUMSS', 'GAS'],
            'BSN' => ['STEM', 'GAS'],
        ];

        return $strandMap[$programKey] ?? [];
    }
}

if (!function_exists('psStudentStrandMatchesAllowed')) {
    function psStudentStrandMatchesAllowed($studentStrand, array $allowedStrands) {
        $studentStrand = strtoupper(trim((string)$studentStrand));
        $allowedUpper = array_map(static function ($value) {
            return strtoupper(trim((string)$value));
        }, $allowedStrands);

        if (in_array($studentStrand, $allowedUpper, true)) {
            return true;
        }

        if ($studentStrand === 'TVL') {
            foreach ($allowedUpper as $allowed) {
                if (strpos($allowed, 'TVL-') === 0) {
                    return true;
                }
            }
        }

        foreach ($allowedUpper as $allowed) {
            if ($allowed === 'TVL' && strpos($studentStrand, 'TVL-') === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('psIsShiftStrandAlignmentEnforced')) {
    function psIsShiftStrandAlignmentEnforced($conn) {
        return policySettingBool($conn, 'enforce_shift_strand_alignment', false);
    }
}

if (!function_exists('psValidateShiftStrandAlignment')) {
    function psValidateShiftStrandAlignment($conn, array $studentRow, $requestedProgram) {
        if (!psIsShiftStrandAlignmentEnforced($conn)) {
            return ['allowed' => true, 'message' => ''];
        }

        $studentStrand = psNormalizeStrandKey((string)($studentRow['strand'] ?? ''));
        if ($studentStrand === '') {
            return [
                'allowed' => false,
                'message' => 'Your strand is not set yet. Please update your profile or contact the administrator before requesting a program shift.',
            ];
        }

        $allowedStrands = psAllowedStrandsForShiftProgram((string)$requestedProgram);
        if (empty($allowedStrands)) {
            return ['allowed' => true, 'message' => ''];
        }

        if (psStudentStrandMatchesAllowed($studentStrand, $allowedStrands)) {
            return ['allowed' => true, 'message' => ''];
        }

        return [
            'allowed' => false,
            'message' => 'Your strand (' . $studentStrand . ') is not aligned with the selected destination program.',
        ];
    }
}

if (!function_exists('psChecklistAttemptApproved')) {
    function psChecklistAttemptApproved($remark, $approvedBy, $gradeApproved) {
        if ((int)$gradeApproved === 1) {
            return true;
        }

        $approvedBy = trim((string)$approvedBy);
        if ($approvedBy !== '') {
            return true;
        }

        $remark = strtoupper(trim((string)$remark));
        if ($remark === '') {
            return false;
        }

        return strpos($remark, 'APPROVED') !== false || strpos($remark, 'CREDITED') !== false;
    }
}

if (!function_exists('psResolveChecklistCreditAttempt')) {
    function psResolveChecklistCreditAttempt(array $row) {
        $attempts = [
            [
                'grade' => trim((string)($row['final_grade'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks'] ?? '')),
            ],
            [
                'grade' => trim((string)($row['final_grade_2'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks_2'] ?? '')),
            ],
            [
                'grade' => trim((string)($row['final_grade_3'] ?? '')),
                'remark' => trim((string)($row['evaluator_remarks_3'] ?? '')),
            ],
        ];

        $approvedBy = trim((string)($row['approved_by'] ?? ''));
        $gradeApproved = (int)($row['grade_approved'] ?? 0);

        for ($index = count($attempts) - 1; $index >= 0; $index--) {
            $grade = $attempts[$index]['grade'];
            if ($grade === '') {
                continue;
            }

            if (!psChecklistAttemptApproved($attempts[$index]['remark'], $approvedBy, $gradeApproved)) {
                continue;
            }

            return [
                'final_grade' => $grade,
                'evaluator_remarks' => $attempts[$index]['remark'],
                'approved_by' => $approvedBy,
                'attempt_slot' => $index + 1,
            ];
        }

        return null;
    }
}

if (!function_exists('psCoordinatorPendingStatuses')) {
    function psCoordinatorPendingStatuses() {
        return ['pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator'];
    }
}

if (!function_exists('psResolveCoordinatorStage')) {
    function psResolveCoordinatorStage(array $requestRow) {
        $status = trim((string)($requestRow['status'] ?? ''));
        if ($status === 'pending_current_coordinator') {
            return 'current';
        }
        if ($status === 'pending_destination_coordinator' || $status === 'pending_coordinator') {
            return 'destination';
        }

        return '';
    }
}

if (!function_exists('psNormalizeCourseCode')) {
    function psNormalizeCourseCode($value) {
        $code = trim((string)$value);
        if ($code === '') {
            return '';
        }

        $suffixes = [' CS-IT', ' CpE', ' CPE', ' IndT', ' INDT', ' CS', ' IT'];
        foreach ($suffixes as $suffix) {
            if (strlen($code) > strlen($suffix) && strcasecmp(substr($code, -strlen($suffix)), $suffix) === 0) {
                return trim(substr($code, 0, -strlen($suffix)));
            }
        }

        return $code;
    }
}

if (!function_exists('psNormalizeCourseRows')) {
    function psNormalizeCourseRows(array $rows) {
        $normalizedRows = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['course_code'] = psNormalizeCourseCode($row['course_code'] ?? '');
            if ($row['course_code'] === '') {
                continue;
            }

            $dedupeKeyParts = [
                strtoupper((string)($row['course_code'] ?? '')),
                strtoupper(trim((string)($row['course_title'] ?? ''))),
                strtoupper(trim((string)($row['year'] ?? $row['year_level'] ?? ''))),
                strtoupper(trim((string)($row['semester'] ?? ''))),
            ];
            $dedupeKey = implode('|', $dedupeKeyParts);
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $normalizedRows[] = $row;
        }

        return $normalizedRows;
    }
}

if (!function_exists('psResolveProgramTokens')) {
    function psResolveProgramTokens($programLabel) {
        $normalizedKey = psNormalizeProgramKey((string)$programLabel);
        if ($normalizedKey === '') {
            return [];
        }

        $tokens = psExpandProgramKeyAliases([$normalizedKey]);
        $tokens[] = strtoupper($normalizedKey);

        return array_values(array_unique(array_filter(array_map(static function ($token) {
            return strtoupper(trim((string)$token));
        }, $tokens), static function ($token) {
            return $token !== '';
        })));
    }
}

if (!function_exists('psNormalizeCurriculumYear')) {
    function psNormalizeCurriculumYear($value) {
        $value = trim((string)$value);
        if (preg_match('/^\d{4}$/', $value)) {
            return $value;
        }

        return '';
    }
}

if (!function_exists('psCanonicalProgramLabel')) {
    function psCanonicalProgramLabel($programKey) {
        $normalizedKey = strtoupper(trim((string)$programKey));
        if ($normalizedKey === '') {
            return '';
        }

        $map = [
            'BSBA-MM' => 'Bachelor of Science in Business Administration Major in Marketing Management',
            'BSBA-HRM' => 'Bachelor of Science in Business Administration Major in Human Resource Management',
            'BSCPE' => 'Bachelor of Science in Computer Engineering',
            'BSCS' => 'Bachelor of Science in Computer Science',
            'BSHM' => 'Bachelor of Science in Hospitality Management',
            'BSINDT' => 'Bachelor of Science in Industrial Technology',
            'BSIT' => 'Bachelor of Science in Information Technology',
            'BSED-ENGLISH' => 'Bachelor of Secondary Education Major in English',
            'BSED-MATH' => 'Bachelor of Secondary Education Major in Mathematics',
            'BSED-SCIENCE' => 'Bachelor of Secondary Education Major in Science',
        ];

        return $map[$normalizedKey] ?? '';
    }
}

if (!function_exists('psResolveChecklistProgramLabels')) {
    function psResolveChecklistProgramLabels($programLabel, $programKey = '') {
        $candidates = [];
        $shortLabelMap = [
            'BSBA-MM' => 'BSBA - Marketing Management',
            'BSBA-HRM' => 'BSBA - Human Resource Management',
            'BSCPE' => 'BS Computer Engineering',
            'BSCS' => 'BS Computer Science',
            'BSHM' => 'BS Hospitality Management',
            'BSINDT' => 'BS Industrial Technology',
            'BSIT' => 'BS Information Technology',
            'BSED-ENGLISH' => 'BSEd Major in English',
            'BSED-MATH' => 'BSEd Major in Math',
            'BSED-SCIENCE' => 'BSEd Major in Science',
        ];
        $normalizedProgramKey = strtoupper(trim((string)psNormalizeProgramKey((string)$programKey)));
        $values = [
            trim((string)$programLabel),
            psCanonicalProgramLabel($programKey),
            psCanonicalProgramLabel(psNormalizeProgramKey((string)$programLabel)),
            $shortLabelMap[$normalizedProgramKey] ?? '',
        ];

        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            $candidates[$value] = true;

            $normalized = psNormalizeProgramLabel($value);
            if ($normalized === 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION MAJOR IN MARKETING MANAGEMENT') {
                $candidates['Bachelor of Science in Business Administration - Major in Marketing Management'] = true;
                $candidates['BSBA - Marketing Management'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION MAJOR IN HUMAN RESOURCE MANAGEMENT') {
                $candidates['Bachelor of Science in Business Administration - Major in Human Resource Management'] = true;
                $candidates['BSBA - Human Resource Management'] = true;
            } elseif ($normalized === 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATH') {
                $candidates['Bachelor of Secondary Education Major in Mathematics'] = true;
                $candidates['BSEd Major in Math'] = true;
            } elseif ($normalized === 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS') {
                $candidates['Bachelor of Secondary Education major Math'] = true;
                $candidates['BSEd Major in Math'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN COMPUTER SCIENCE') {
                $candidates['BS Computer Science'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN INFORMATION TECHNOLOGY') {
                $candidates['BS Information Technology'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN COMPUTER ENGINEERING') {
                $candidates['BS Computer Engineering'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN INDUSTRIAL TECHNOLOGY') {
                $candidates['BS Industrial Technology'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT') {
                $candidates['BS Hospitality Management'] = true;
            }
        }

        return array_keys($candidates);
    }
}

if (!function_exists('psResolveLatestCurriculumYear')) {
    function psResolveLatestCurriculumYear($conn, $programLabel, $programKey = '') {
        $programLabels = psResolveChecklistProgramLabels($programLabel, $programKey);
        if (psTableExists($conn, 'curriculum_courses') && !empty($programLabels)) {
            $conditions = [];
            $params = [];
            $types = '';
            foreach ($programLabels as $candidateLabel) {
                $conditions[] = 'UPPER(TRIM(program)) = ?';
                $params[] = strtoupper(trim((string)$candidateLabel));
                $types .= 's';
            }

            $sql = "
                SELECT MAX(curriculum_year) AS latest_year
                FROM curriculum_courses
                WHERE (" . implode(' OR ', $conditions) . ")
                  AND curriculum_year IS NOT NULL
                  AND TRIM(course_code) != ''
            ";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                $year = psNormalizeCurriculumYear((string)($row['latest_year'] ?? ''));
                if ($year !== '') {
                    return $year;
                }
            }
        }

        $normalizedProgramKey = psNormalizeProgramKey((string)($programKey !== '' ? $programKey : $programLabel));
        if ($normalizedProgramKey !== '' && psTableExists($conn, 'program_curriculum_years')) {
            $stmt = $conn->prepare("
                SELECT MAX(curriculum_year) AS latest_year
                FROM program_curriculum_years
                WHERE TRIM(program) = ?
            ");
            if ($stmt) {
                $stmt->bind_param('s', $normalizedProgramKey);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                $year = psNormalizeCurriculumYear((string)($row['latest_year'] ?? ''));
                if ($year !== '') {
                    return $year;
                }
            }
        }

        if (!psTableExists($conn, 'cvsucarmona_courses')) {
            return '';
        }

        $tokens = psResolveProgramTokens($programKey !== '' ? $programKey : $programLabel);
        if (empty($tokens)) {
            return '';
        }

        $conditions = [];
        $params = [];
        $types = '';
        foreach ($tokens as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), " ", "")) > 0';
            $params[] = $token;
            $types .= 's';
        }

        $sql = "
            SELECT MAX(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1))) AS latest_year
            FROM cvsucarmona_courses
            WHERE (" . implode(' OR ', $conditions) . ")
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return '';
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return psNormalizeCurriculumYear((string)($row['latest_year'] ?? ''));
    }
}

if (!function_exists('psResolveStudentCurriculumYear')) {
    function psResolveStudentCurriculumYear($conn, $studentId, $programLabel = '', $programKey = '') {
        $studentId = trim((string)$studentId);
        $selectedProgramKey = psNormalizeProgramKey((string)($programKey !== '' ? $programKey : $programLabel));
        if ($studentId !== '' && psTableExists($conn, 'student_info')) {
            $stmt = $conn->prepare("
                SELECT program, curriculum_year
                FROM student_info
                WHERE student_number = ?
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                if ($row) {
                    $studentProgramKey = psNormalizeProgramKey((string)($row['program'] ?? ''));
                    $storedYear = psNormalizeCurriculumYear((string)($row['curriculum_year'] ?? ''));
                    if ($selectedProgramKey !== '' && $studentProgramKey !== '' && $selectedProgramKey !== $studentProgramKey) {
                        return psResolveLatestCurriculumYear($conn, $programLabel, $programKey);
                    }
                    if ($programLabel === '') {
                        $programLabel = (string)($row['program'] ?? '');
                    }
                    if ($programKey === '') {
                        $programKey = $studentProgramKey;
                    }
                    if ($storedYear !== '') {
                        $programLabels = psResolveChecklistProgramLabels($programLabel, $programKey);
                        if (psTableExists($conn, 'curriculum_courses') && !empty($programLabels)) {
                            $conditions = [];
                            $params = [];
                            $types = '';
                            foreach ($programLabels as $candidateLabel) {
                                $conditions[] = 'UPPER(TRIM(program)) = ?';
                                $params[] = strtoupper(trim((string)$candidateLabel));
                                $types .= 's';
                            }

                            $sql = "SELECT id FROM curriculum_courses WHERE curriculum_year = ? AND (" . implode(' OR ', $conditions) . ") LIMIT 1";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $bindParams = array_merge([$storedYear], $params);
                                $stmt->bind_param('s' . $types, ...$bindParams);
                                $stmt->execute();
                                $exists = $stmt->get_result();
                                $hasRows = $exists && $exists->num_rows > 0;
                                $stmt->close();
                                if ($hasRows) {
                                    return $storedYear;
                                }
                            }
                        }

                        $tokens = psResolveProgramTokens($programKey !== '' ? $programKey : $programLabel);
                        if (psTableExists($conn, 'cvsucarmona_courses') && !empty($tokens)) {
                            $conditions = [];
                            $params = [];
                            $types = '';
                            foreach ($tokens as $token) {
                                $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), " ", "")) > 0';
                                $params[] = $token;
                                $types .= 's';
                            }

                            $sql = "SELECT curriculumyear_coursecode FROM cvsucarmona_courses WHERE TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1)) = ? AND (" . implode(' OR ', $conditions) . ") LIMIT 1";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $bindParams = array_merge([$storedYear], $params);
                                $stmt->bind_param('s' . $types, ...$bindParams);
                                $stmt->execute();
                                $exists = $stmt->get_result();
                                $hasRows = $exists && $exists->num_rows > 0;
                                $stmt->close();
                                if ($hasRows) {
                                    return $storedYear;
                                }
                            }
                        }
                    }
                }
            }
        }

        return psResolveLatestCurriculumYear($conn, $programLabel, $programKey);
    }
}

if (!function_exists('psFetchChecklistCourses')) {
    function psFetchChecklistCourses($conn, $studentId, $programLabel, $programKey = '') {
        $studentId = trim((string)$studentId);
        if ($studentId === '') {
            return [];
        }

        $programLabels = psResolveChecklistProgramLabels($programLabel, $programKey);
        $curriculumYear = psResolveStudentCurriculumYear($conn, $studentId, $programLabel, $programKey);

        if (psTableExists($conn, 'curriculum_courses') && !empty($programLabels)) {
            $conditions = [];
            $params = [$studentId];
            $types = 's';

            foreach ($programLabels as $candidateLabel) {
                $conditions[] = 'UPPER(TRIM(cc.program)) = ?';
                $params[] = strtoupper(trim((string)$candidateLabel));
                $types .= 's';
            }

            $curriculumYearClause = '';
            if ($curriculumYear !== '') {
                $curriculumYearClause = ' AND cc.curriculum_year = ?';
                $params[] = $curriculumYear;
                $types .= 's';
            }

            $sql = "
                SELECT
                    TRIM(cc.course_code) AS course_code,
                    TRIM(cc.course_title) AS course_title,
                    IFNULL(cc.credit_units_lec, 0) AS credit_unit_lec,
                    IFNULL(cc.credit_units_lab, 0) AS credit_unit_lab,
                    IFNULL(cc.lect_hrs_lec, 0) AS contact_hrs_lec,
                    IFNULL(cc.lect_hrs_lab, 0) AS contact_hrs_lab,
                    TRIM(IFNULL(cc.pre_requisite, 'NONE')) AS pre_requisite,
                    TRIM(cc.year_level) AS year,
                    TRIM(cc.semester) AS semester,
                    sc.final_grade,
                    sc.evaluator_remarks,
                    sc.professor_instructor,
                    sc.final_grade_2,
                    sc.evaluator_remarks_2,
                    sc.final_grade_3,
                    sc.evaluator_remarks_3,
                    sc.approved_by,
                    sc.submitted_by
                FROM curriculum_courses cc
                LEFT JOIN student_checklists sc
                    ON TRIM(cc.course_code) = sc.course_code AND sc.student_id = ?
                WHERE (" . implode(' OR ', $conditions) . ")" . $curriculumYearClause . "
                ORDER BY
                    IFNULL(cc.curriculum_year, 0),
                    CASE UPPER(TRIM(cc.year_level))
                        WHEN 'FIRST YEAR' THEN 1
                        WHEN 'SECOND YEAR' THEN 2
                        WHEN 'THIRD YEAR' THEN 3
                        WHEN 'FOURTH YEAR' THEN 4
                        ELSE 99
                    END,
                    CASE UPPER(TRIM(cc.semester))
                        WHEN 'FIRST SEMESTER' THEN 1
                        WHEN 'SECOND SEMESTER' THEN 2
                        WHEN 'MID YEAR' THEN 3
                        WHEN 'MIDYEAR' THEN 3
                        WHEN 'SUMMER' THEN 3
                        ELSE 99
                    END,
                    cc.id,
                    TRIM(cc.course_code)
            ";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = [];
                while ($result && ($row = $result->fetch_assoc())) {
                    $rows[] = $row;
                }
                $stmt->close();

                if (!empty($rows)) {
                    return psNormalizeCourseRows($rows);
                }
            }
        }

        if (!psTableExists($conn, 'cvsucarmona_courses')) {
            return [];
        }

        $tokens = psResolveProgramTokens($programKey !== '' ? $programKey : $programLabel);
        if (empty($tokens)) {
            return [];
        }

        $conditions = [];
        $params = [$studentId];
        $types = 's';
        foreach ($tokens as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(c.programs), " ", "")) > 0';
            $params[] = $token;
            $types .= 's';
        }

        $curriculumYearClause = '';
        if ($curriculumYear !== '') {
            $curriculumYearClause = ' AND TRIM(SUBSTRING_INDEX(c.curriculumyear_coursecode, \'_\', 1)) = ?';
            $params[] = $curriculumYear;
            $types .= 's';
        }

        $sql = "
            SELECT DISTINCT
                TRIM(SUBSTRING_INDEX(c.curriculumyear_coursecode, '_', -1)) AS course_code,
                TRIM(c.course_title) AS course_title,
                IFNULL(c.credit_units_lec, 0) AS credit_unit_lec,
                IFNULL(c.credit_units_lab, 0) AS credit_unit_lab,
                IFNULL(c.lect_hrs_lec, 0) AS contact_hrs_lec,
                IFNULL(c.lect_hrs_lab, 0) AS contact_hrs_lab,
                TRIM(IFNULL(c.pre_requisite, 'NONE')) AS pre_requisite,
                TRIM(c.year_level) AS year,
                TRIM(c.semester) AS semester,
                sc.final_grade,
                sc.evaluator_remarks,
                sc.professor_instructor,
                sc.final_grade_2,
                sc.evaluator_remarks_2,
                sc.final_grade_3,
                sc.evaluator_remarks_3,
                sc.approved_by,
                sc.submitted_by
            FROM cvsucarmona_courses c
            LEFT JOIN student_checklists sc
                ON TRIM(SUBSTRING_INDEX(c.curriculumyear_coursecode, '_', -1)) = sc.course_code
                AND sc.student_id = ?
            WHERE (" . implode(' OR ', $conditions) . ")" . $curriculumYearClause . "
            ORDER BY
                CASE UPPER(TRIM(c.year_level))
                    WHEN 'FIRST YEAR' THEN 1
                    WHEN 'SECOND YEAR' THEN 2
                    WHEN 'THIRD YEAR' THEN 3
                    WHEN 'FOURTH YEAR' THEN 4
                    ELSE 99
                END,
                CASE UPPER(TRIM(c.semester))
                    WHEN 'FIRST SEMESTER' THEN 1
                    WHEN 'SECOND SEMESTER' THEN 2
                    WHEN 'MID YEAR' THEN 3
                    WHEN 'MIDYEAR' THEN 3
                    WHEN 'SUMMER' THEN 3
                    ELSE 99
                END,
                c.curriculumyear_coursecode
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();

        return psNormalizeCourseRows($rows);
    }
}

if (!function_exists('psFetchCurriculumCourses')) {
    function psFetchCurriculumCourses($conn, $programLabel, $curriculumYear = '') {
        $curriculumYear = psNormalizeCurriculumYear($curriculumYear);
        if ($curriculumYear === '') {
            $curriculumYear = psResolveLatestCurriculumYear($conn, $programLabel);
        }

        // Preferred schema.
        if (psTableExists($conn, 'curriculum_courses')) {
            $rows = [];
            $sql =
                "SELECT
                    TRIM(course_code) AS course_code,
                    TRIM(course_title) AS course_title,
                    IFNULL(credit_units_lec, 0) AS credit_units_lec,
                    IFNULL(credit_units_lab, 0) AS credit_units_lab,
                    IFNULL(lect_hrs_lec, 0) AS lect_hrs_lec,
                    IFNULL(lect_hrs_lab, 0) AS lect_hrs_lab
                 FROM curriculum_courses
                 WHERE TRIM(program) = ?";
            if ($curriculumYear !== '') {
                $sql .= " AND curriculum_year = ?";
            }
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return [];
            }

            $programLabel = trim((string)$programLabel);
            if ($curriculumYear !== '') {
                $stmt->bind_param('ss', $programLabel, $curriculumYear);
            } else {
                $stmt->bind_param('s', $programLabel);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $rows[] = $row;
            }
            $stmt->close();

            return psNormalizeCourseRows($rows);
        }

        // Fallback schema used by curriculum management.
        if (!psTableExists($conn, 'cvsucarmona_courses')) {
            return [];
        }

        $tokens = psResolveProgramTokens($programLabel);
        if (empty($tokens)) {
            return [];
        }

        $conditions = [];
        $params = [];
        $types = '';
        foreach ($tokens as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), " ", "")) > 0';
            $params[] = $token;
            $types .= 's';
        }

        $sql =
            "SELECT DISTINCT
                TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
                TRIM(course_title) AS course_title,
                IFNULL(credit_units_lec, 0) AS credit_units_lec,
                IFNULL(credit_units_lab, 0) AS credit_units_lab,
                IFNULL(lect_hrs_lec, 0) AS lect_hrs_lec,
                IFNULL(lect_hrs_lab, 0) AS lect_hrs_lab
             FROM cvsucarmona_courses
             WHERE (" . implode(' OR ', $conditions) . ")";
        if ($curriculumYear !== '') {
            $sql .= " AND TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1)) = ?";
            $params[] = $curriculumYear;
            $types .= 's';
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();

        return psNormalizeCourseRows($rows);
    }
}

if (!function_exists('psHasActiveShiftRequest')) {
    function psHasActiveShiftRequest($conn, $studentNumber) {
        $stmt = $conn->prepare("SELECT id FROM program_shift_requests WHERE student_number = ? AND status IN ('pending_adviser', 'pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator') LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $studentNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result && $result->num_rows > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('psCreateStudentRequest')) {
    function psCreateStudentRequest($conn, $studentNumber, $requestedProgram, $reason) {
        $studentRow = psGetCurrentStudentInfo($conn, $studentNumber);
        if (!$studentRow) {
            return ['ok' => false, 'message' => 'Student record not found.'];
        }

        $currentProgram = trim((string)($studentRow['program'] ?? ''));
        if ($currentProgram === '') {
            return ['ok' => false, 'message' => 'Your current program is not set. Please contact the administrator.'];
        }

        $requestedProgram = trim((string)$requestedProgram);
        if ($requestedProgram === '') {
            return ['ok' => false, 'message' => 'Please select a destination program.'];
        }

        if (strcasecmp(psNormalizeProgramLabel($currentProgram), psNormalizeProgramLabel($requestedProgram)) === 0) {
            return ['ok' => false, 'message' => 'You are already enrolled in the selected program.'];
        }

        $strandValidation = psValidateShiftStrandAlignment($conn, $studentRow, $requestedProgram);
        if (empty($strandValidation['allowed'])) {
            return ['ok' => false, 'message' => (string)($strandValidation['message'] ?? 'Your strand is not aligned with the selected destination program.')];
        }

        if (psHasActiveShiftRequest($conn, (string)$studentRow['student_number'])) {
            return ['ok' => false, 'message' => 'You already have a pending shift request.'];
        }

        $requestCode = psGenerateRequestCode();
        $studentName = psGetStudentDisplayName($studentRow);
        $reason = trim((string)$reason);

        $stmt = $conn->prepare("INSERT INTO program_shift_requests (request_code, student_number, student_name, current_program, requested_program, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'pending_adviser')");
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Unable to save shift request.'];
        }

        $studentNumberValue = (string)$studentRow['student_number'];
        $stmt->bind_param('ssssss', $requestCode, $studentNumberValue, $studentName, $currentProgram, $requestedProgram, $reason);
        $saved = $stmt->execute();
        $newId = $saved ? (int)$stmt->insert_id : 0;
        $stmt->close();

        if (!$saved) {
            return ['ok' => false, 'message' => 'Unable to save shift request.'];
        }

        psAddAuditLog($conn, $newId, 'request_submitted', 'Program shift request submitted by student.', $studentNumberValue, 'student', [
            'current_program' => $currentProgram,
            'requested_program' => $requestedProgram,
        ]);

        psSendProgramShiftEmail(
            (string)($studentRow['email'] ?? ''),
            $studentName,
            'submitted',
            $requestCode,
            $currentProgram,
            $requestedProgram,
            'Your program shift request has been submitted successfully and is waiting for review.'
        );

        psNotifyAdvisersOfProgramShiftRequest(
            $conn,
            $studentRow,
            $requestCode,
            $currentProgram,
            $requestedProgram,
            $reason
        );

        return [
            'ok' => true,
            'message' => 'Shift request submitted successfully.',
            'request_id' => $newId,
            'request_code' => $requestCode,
        ];
    }
}

if (!function_exists('psSendProgramShiftEmail')) {
    function psSendProgramShiftEmail($email, $studentName, $statusLabel, $requestCode, $currentProgram, $requestedProgram, $details = '') {
        $email = trim((string)$email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $rootPath = dirname(__DIR__);
        require_once $rootPath . '/includes/EmailNotification.php';

        if (!class_exists('EmailNotification')) {
            return false;
        }

        try {
            $notifier = new EmailNotification();
            if ($statusLabel === 'submitted') {
                return $notifier->sendProgramShiftSubmitted($email, (string)$studentName, (string)$requestCode, (string)$currentProgram, (string)$requestedProgram);
            }

            return $notifier->sendProgramShiftStatusUpdate($email, (string)$studentName, (string)$requestCode, (string)$statusLabel, (string)$currentProgram, (string)$requestedProgram, (string)$details);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('psTableHasColumn')) {
    function psTableHasColumn($conn, $tableName, $columnName) {
        $tableName = trim((string)$tableName);
        $columnName = trim((string)$columnName);
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        try {
            $tableSafe = $conn->real_escape_string($tableName);
            $columnSafe = $conn->real_escape_string($columnName);
            $result = $conn->query("SHOW COLUMNS FROM `$tableSafe` LIKE '$columnSafe'");
            return $result && $result->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('psNotifyAdvisersOfProgramShiftRequest')) {
    function psNotifyAdvisersOfProgramShiftRequest($conn, array $studentRow, string $requestCode, string $currentProgram, string $requestedProgram, string $reason = ''): int {
        if (!psTableExists($conn, 'adviser') || !psTableHasColumn($conn, 'adviser', 'email')) {
            return 0;
        }

        $currentProgramKeys = psResolveProgramTokens($currentProgram);
        if (empty($currentProgramKeys)) {
            return 0;
        }

        $studentNumber = trim((string)($studentRow['student_number'] ?? ''));
        $stmt = $conn->prepare('SELECT id, email, first_name, last_name, username, program FROM adviser');
        if (!$stmt) {
            return 0;
        }

        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            return 0;
        }

        $notifier = null;
        $seen = [];
        $sent = 0;

        while ($row = $result->fetch_assoc()) {
            if (!psProgramMatchesActorKeys((string)($row['program'] ?? ''), $currentProgramKeys)) {
                continue;
            }

            $adviserBatches = psResolveAdviserBatches($conn, (int)($row['id'] ?? 0), (string)($row['username'] ?? ''));
            if (!psStudentMatchesAdviserBatches($studentNumber, $adviserBatches)) {
                continue;
            }

            $email = trim((string)($row['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $emailKey = strtolower($email);
            if (isset($seen[$emailKey])) {
                continue;
            }
            $seen[$emailKey] = true;

            $adviserName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            if ($adviserName === '') {
                $adviserName = trim((string)($row['username'] ?? 'Adviser'));
            }
            if ($adviserName === '') {
                $adviserName = 'Adviser';
            }

            try {
                if ($notifier === null) {
                    $rootPath = dirname(__DIR__);
                    require_once $rootPath . '/includes/EmailNotification.php';
                    if (!class_exists('EmailNotification')) {
                        break;
                    }
                    $notifier = new EmailNotification();
                }

                $studentName = trim((string)($studentRow['first_name'] ?? ''));
                $studentLast = trim((string)($studentRow['last_name'] ?? ''));
                if ($studentLast !== '') {
                    $studentName = trim($studentLast . ', ' . $studentName);
                }
                if ($studentName === '') {
                    $studentName = (string)($studentRow['student_number'] ?? '');
                }

                $notifier->sendProgramShiftAdviserNotification(
                    $email,
                    $adviserName,
                    $studentName,
                    $requestCode,
                    $currentProgram,
                    $requestedProgram,
                    $reason
                );
                $sent++;
            } catch (Throwable $e) {
                continue;
            }
        }

        $stmt->close();
        return $sent;
    }
}

if (!function_exists('psResolveAdviserProgramKeys')) {
    function psResolveAdviserProgramKeys($conn, $adviserId, $username) {
        $keys = [];

        if ($adviserId !== null && $adviserId !== '') {
            $stmt = $conn->prepare("SELECT TRIM(program) AS program FROM adviser WHERE id = ? LIMIT 1");
            if ($stmt) {
                $adviserIdInt = (int)$adviserId;
                $stmt->bind_param('i', $adviserIdInt);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $keys = psParseProgramList((string)($row['program'] ?? ''));
                }
                $stmt->close();
            }
        }

        if (!empty($keys)) {
            return $keys;
        }

        $username = trim((string)$username);
        if ($username !== '') {
            $stmt = $conn->prepare("SELECT TRIM(program) AS program FROM adviser WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $keys = psParseProgramList((string)($row['program'] ?? ''));
                }
                $stmt->close();
            }
        }

        return $keys;
    }
}

if (!function_exists('psResolveAdviserBatches')) {
    function psResolveAdviserBatches($conn, $adviserId, $username = '') {
        if (!psTableExists($conn, 'adviser_batch')) {
            return [];
        }

        $resolvedAdviserId = (int)$adviserId;
        if ($resolvedAdviserId <= 0) {
            $username = trim((string)$username);
            if ($username !== '') {
                $stmt = $conn->prepare("SELECT id FROM adviser WHERE username = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result && $result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $resolvedAdviserId = (int)($row['id'] ?? 0);
                    }
                    $stmt->close();
                }
            }
        }

        if ($resolvedAdviserId <= 0) {
            return [];
        }

        $batches = [];
        $stmt = $conn->prepare("SELECT batch FROM adviser_batch WHERE adviser_id = ? ORDER BY batch");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $resolvedAdviserId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $batch = trim((string)($row['batch'] ?? ''));
            if ($batch !== '') {
                $batches[] = $batch;
            }
        }
        $stmt->close();

        return array_values(array_unique($batches, SORT_STRING));
    }
}

if (!function_exists('psStudentMatchesAdviserBatches')) {
    function psStudentMatchesAdviserBatches($studentNumber, array $batches) {
        $studentNumber = trim((string)$studentNumber);
        if ($studentNumber === '' || empty($batches)) {
            return false;
        }

        foreach ($batches as $batch) {
            $batch = trim((string)$batch);
            if ($batch !== '' && strpos($studentNumber, $batch) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('psRequestMatchesAdviserScope')) {
    function psRequestMatchesAdviserScope(array $requestRow, array $actorProgramKeys, array $adviserBatches) {
        if (empty($actorProgramKeys) || empty($adviserBatches)) {
            return false;
        }

        if (!psProgramMatchesActorKeys((string)($requestRow['current_program'] ?? ''), $actorProgramKeys)) {
            return false;
        }

        return psStudentMatchesAdviserBatches((string)($requestRow['student_number'] ?? ''), $adviserBatches);
    }
}

if (!function_exists('psResolveCoordinatorProgramKeys')) {
    function psResolveCoordinatorProgramKeys($conn, $username) {
        $username = trim((string)$username);
        if ($username === '') {
            return [];
        }

        $tables = ['program_coordinator', 'program_coordinators'];
        foreach ($tables as $table) {
            $tableSafe = $conn->real_escape_string($table);
            $tableResult = $conn->query("SHOW TABLES LIKE '$tableSafe'");
            if (!$tableResult || $tableResult->num_rows === 0) {
                continue;
            }

            $columnResult = $conn->query("SHOW COLUMNS FROM `$tableSafe` LIKE 'program'");
            if (!$columnResult || $columnResult->num_rows === 0) {
                continue;
            }

            $stmt = $conn->prepare("SELECT TRIM(program) AS program FROM `$tableSafe` WHERE username = ? LIMIT 1");
            if (!$stmt) {
                continue;
            }

            $stmt->bind_param('s', $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                $keys = psParseProgramList((string)($row['program'] ?? ''));
                if (!empty($keys)) {
                    return $keys;
                }
            } else {
                $stmt->close();
            }
        }

        return [];
    }
}

if (!function_exists('psGetRequestById')) {
    function psGetRequestById($conn, $requestId) {
        $stmt = $conn->prepare('SELECT * FROM program_shift_requests WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $requestId = (int)$requestId;
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('psGradeIsPassing')) {
    function psGradeIsPassing($gradeRaw) {
        $grade = strtoupper(trim((string)$gradeRaw));
        if ($grade === '') {
            return false;
        }

        $failMarkers = ['5.00', 'FAILED', 'FAIL', 'INC', 'INCOMPLETE', 'DRP', 'DROP', 'DROPPED'];
        if (in_array($grade, $failMarkers, true)) {
            return false;
        }

        if (is_numeric($grade)) {
            return (float)$grade <= 3.0;
        }

        return true;
    }
}

if (!function_exists('psFetchLatestChecklistGrade')) {
    function psFetchLatestChecklistGrade($conn, $studentNumber, $courseCode) {
        $stmt = $conn->prepare(
            "SELECT final_grade, evaluator_remarks, approved_by, grade_approved,
                    final_grade_2, evaluator_remarks_2,
                    final_grade_3, evaluator_remarks_3
             FROM student_checklists
             WHERE student_id = ?
               AND TRIM(course_code) = ?
               AND (
                    (final_grade IS NOT NULL AND TRIM(final_grade) != '')
                    OR (final_grade_2 IS NOT NULL AND TRIM(final_grade_2) != '')
                    OR (final_grade_3 IS NOT NULL AND TRIM(final_grade_3) != '')
               )
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $studentNumber = trim((string)$studentNumber);
        $courseCode = trim((string)$courseCode);
        $stmt->bind_param('ss', $studentNumber, $courseCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return null;
        }

        return psResolveChecklistCreditAttempt($row);
    }
}

if (!function_exists('psExecuteApprovedShift')) {
    function psExecuteApprovedShift($conn, array $requestRow, $actorUsername) {
        $requestId = (int)$requestRow['id'];
        $studentNumber = (string)$requestRow['student_number'];
        $sourceProgram = trim((string)$requestRow['current_program']);
        $destinationProgram = trim((string)$requestRow['requested_program']);

        if ($sourceProgram === '' || $destinationProgram === '') {
            return ['ok' => false, 'message' => 'Missing program information for execution.'];
        }

        $sourceCurriculumYear = psResolveStudentCurriculumYear($conn, $studentNumber, $sourceProgram);
        $destinationCurriculumYear = psResolveLatestCurriculumYear($conn, $destinationProgram);
        $destinationCourses = psFetchCurriculumCourses($conn, $destinationProgram, $destinationCurriculumYear);
        $sourceCourses = psFetchCurriculumCourses($conn, $sourceProgram, $sourceCurriculumYear);

        $canAutoCredit = !empty($destinationCourses) && !empty($sourceCourses);
        $autoCreditSkippedReason = '';
        if (!$canAutoCredit) {
            $autoCreditSkippedReason = 'Auto-credit skipped because curriculum entries are missing for the source or destination program.';
        }

        $sourceCourseIndex = [];
        if ($canAutoCredit) {
            foreach ($sourceCourses as $sourceCourseRow) {
                $signature = psBuildCourseSignature($sourceCourseRow);
                if (!isset($sourceCourseIndex[$signature])) {
                    $sourceCourseIndex[$signature] = [];
                }
                $sourceCourseIndex[$signature][] = $sourceCourseRow;
            }
        }

        $credited = 0;
        $hasGradeSubmittedAt = psTableHasColumn($conn, 'student_checklists', 'grade_submitted_at');

        if ($canAutoCredit) {
            foreach ($destinationCourses as $destCourse) {
                $courseCode = trim((string)$destCourse['course_code']);
                $signature = psBuildCourseSignature($destCourse);
                if ($courseCode === '' || !isset($sourceCourseIndex[$signature])) {
                    continue;
                }

                $gradeRow = null;
                $finalGrade = '';
                $sourceCourseCode = '';
                foreach ($sourceCourseIndex[$signature] as $sourceCourseRow) {
                    $candidateSourceCode = trim((string)($sourceCourseRow['course_code'] ?? ''));
                    if ($candidateSourceCode === '') {
                        continue;
                    }

                    $candidateGradeRow = psFetchLatestChecklistGrade($conn, $studentNumber, $candidateSourceCode);
                    if (!$candidateGradeRow) {
                        continue;
                    }

                    $candidateFinalGrade = trim((string)($candidateGradeRow['final_grade'] ?? ''));
                    if (!psGradeIsPassing($candidateFinalGrade)) {
                        continue;
                    }

                    $gradeRow = $candidateGradeRow;
                    $finalGrade = $candidateFinalGrade;
                    $sourceCourseCode = $candidateSourceCode;
                    break;
                }

                if (!$gradeRow || $sourceCourseCode === '') {
                    continue;
                }

                $remarks = trim((string)($gradeRow['evaluator_remarks'] ?? ''));
                if ($remarks === '') {
                    $remarks = 'Credited (Shift Equivalency)';
                } elseif (stripos($remarks, 'credited') === false) {
                    $remarks .= ' | Credited (Shift Equivalency)';
                }

                $checkExisting = $conn->prepare('SELECT id FROM student_checklists WHERE student_id = ? AND TRIM(course_code) = ? ORDER BY id DESC LIMIT 1');
                if (!$checkExisting) {
                    continue;
                }

                $checkExisting->bind_param('ss', $studentNumber, $courseCode);
                $checkExisting->execute();
                $existingResult = $checkExisting->get_result();
                $existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
                $checkExisting->close();

                if ($existingRow) {
                    $updateSql = "UPDATE student_checklists
                        SET final_grade = ?,
                            evaluator_remarks = ?,
                            grade_approved = 1,
                            approved_at = NOW(),
                            approved_by = 'shift_engine',
                            updated_at = NOW(),
                            submitted_by = 'shift_engine'";
                    if ($hasGradeSubmittedAt) {
                        $updateSql .= ",
                            grade_submitted_at = NOW()";
                    }
                    $updateSql .= "
                        WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    if ($updateStmt) {
                        $existingId = (int)$existingRow['id'];
                        $updateStmt->bind_param('ssi', $finalGrade, $remarks, $existingId);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }
                } else {
                    $insertColumns = 'student_id, course_code, status, final_grade, evaluator_remarks, submitted_by, grade_approved, approved_at, approved_by, created_at, updated_at';
                    $insertValues = "?, ?, 'Taken', ?, ?, 'shift_engine', 1, NOW(), 'shift_engine', NOW(), NOW()";
                    if ($hasGradeSubmittedAt) {
                        $insertColumns .= ', grade_submitted_at';
                        $insertValues .= ', NOW()';
                    }
                    $insertStmt = $conn->prepare("INSERT INTO student_checklists
                        ($insertColumns)
                        VALUES ($insertValues)");
                    if ($insertStmt) {
                        $insertStmt->bind_param('ssss', $studentNumber, $courseCode, $finalGrade, $remarks);
                        $insertStmt->execute();
                        $insertStmt->close();
                    }
                }

                $mapStmt = $conn->prepare('INSERT INTO program_shift_credit_map (request_id, student_number, source_program, destination_program, source_course_code, destination_course_code, final_grade, evaluator_remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                if ($mapStmt) {
                    $mapStmt->bind_param('isssssss', $requestId, $studentNumber, $sourceProgram, $destinationProgram, $sourceCourseCode, $courseCode, $finalGrade, $remarks);
                    $mapStmt->execute();
                    $mapStmt->close();
                }

                $credited++;
            }
        }

        $updateProgram = $conn->prepare('UPDATE student_info SET program = ?, curriculum_year = ? WHERE student_number = ?');
        if (!$updateProgram) {
            return ['ok' => false, 'message' => 'Unable to apply destination program.'];
        }

        $updateProgram->bind_param('sss', $destinationProgram, $destinationCurriculumYear, $studentNumber);
        $updateProgram->execute();
        $updateProgram->close();

        if (psTableExists($conn, 'student_study_plan_overrides')) {
            $clearOverrides = $conn->prepare('DELETE FROM student_study_plan_overrides WHERE student_id = ?');
            if ($clearOverrides) {
                $clearOverrides->bind_param('s', $studentNumber);
                $clearOverrides->execute();
                $clearOverrides->close();
            }
        }

        $executionNote = 'Shift executed successfully. Auto-credited courses: ' . $credited . '.';
        if (!$canAutoCredit) {
            $executionNote .= ' ' . $autoCreditSkippedReason;
        }
        $finalize = $conn->prepare("UPDATE program_shift_requests
            SET status = 'approved',
                executed_by = ?,
                executed_at = NOW(),
                execution_note = ?
            WHERE id = ?");
        if ($finalize) {
            $finalize->bind_param('ssi', $actorUsername, $executionNote, $requestId);
            $finalize->execute();
            $finalize->close();
        }

        psAddAuditLog($conn, $requestId, 'shift_executed', 'Program shift executed and student program updated.', $actorUsername, 'program_coordinator', [
            'student_number' => $studentNumber,
            'source_program' => $sourceProgram,
            'destination_program' => $destinationProgram,
            'credited_courses' => $credited,
            'auto_credit_skipped' => !$canAutoCredit,
            'auto_credit_skip_reason' => $autoCreditSkippedReason,
        ]);

        return ['ok' => true, 'message' => $executionNote, 'credited_courses' => $credited];
    }
}

if (!function_exists('psHandleAdviserDecision')) {
    function psHandleAdviserDecision($conn, $requestId, $action, $actorUsername, $actorName, array $actorProgramKeys, array $adviserBatches, $comment = '') {
        $request = psGetRequestById($conn, $requestId);
        if (!$request) {
            return ['ok' => false, 'message' => 'Shift request not found.'];
        }

        if ((string)$request['status'] !== 'pending_adviser') {
            return ['ok' => false, 'message' => 'This request is not pending adviser review.'];
        }

        if (!psRequestMatchesAdviserScope($request, $actorProgramKeys, $adviserBatches)) {
            return ['ok' => false, 'message' => 'You are not assigned to review this request for the selected program and batch scope.'];
        }

        $action = strtolower(trim((string)$action));
        if (!in_array($action, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Invalid action.'];
        }

        $nextStatus = $action === 'approve' ? 'pending_current_coordinator' : 'rejected';

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("UPDATE program_shift_requests
                SET status = ?, adviser_action_by = ?, adviser_action_name = ?, adviser_action_at = NOW(), adviser_comment = ?
                WHERE id = ? AND status = 'pending_adviser'");
            if (!$stmt) {
                throw new RuntimeException('Unable to update request.');
            }

            $requestId = (int)$requestId;
            $stmt->bind_param('ssssi', $nextStatus, $actorUsername, $actorName, $comment, $requestId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Unable to update request.');
            }

            if ((int)$stmt->affected_rows === 0) {
                $stmt->close();
                throw new RuntimeException('This request was already processed by another user. Please refresh the queue.');
            }
            $stmt->close();

            $approval = $conn->prepare('INSERT INTO program_shift_approvals (request_id, stage, action, actor_username, actor_name, actor_program, comments) VALUES (?, "adviser", ?, ?, ?, ?, ?)');
            if (!$approval) {
                throw new RuntimeException('Unable to save adviser action log.');
            }

            $actorProgram = implode(', ', $actorProgramKeys);
            $approval->bind_param('isssss', $requestId, $action, $actorUsername, $actorName, $actorProgram, $comment);
            if (!$approval->execute()) {
                $approval->close();
                throw new RuntimeException('Unable to save adviser action log.');
            }
            $approval->close();

            $auditMessage = $action === 'approve'
                ? 'Adviser approved and forwarded the shift request to the current-program coordinator.'
                : 'Adviser rejected the shift request.';
            psAddAuditLog($conn, $requestId, 'adviser_' . $action, $auditMessage, $actorUsername, 'adviser', [
                'comment' => $comment,
                'next_status' => $nextStatus,
            ]);

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to process adviser decision.'];
        }

        $studentRow = psGetCurrentStudentInfo($conn, (string)$request['student_number']);
        if ($studentRow) {
            psSendProgramShiftEmail(
                (string)($studentRow['email'] ?? ''),
                (string)($request['student_name'] ?? ''),
                $action === 'approve' ? 'Pending Current Program Coordinator Review' : 'Rejected by Adviser',
                (string)($request['request_code'] ?? ''),
                (string)($request['current_program'] ?? ''),
                (string)($request['requested_program'] ?? ''),
                $action === 'approve'
                    ? 'Your request has been forwarded to the Program Coordinator of your current program.'
                    : 'Your request was rejected by the Adviser.'
            );
        }

        return [
            'ok' => true,
            'message' => $action === 'approve'
                ? 'Request approved and forwarded to the current-program coordinator.'
                : 'Request rejected by adviser.',
        ];
    }
}

if (!function_exists('psHandleCoordinatorDecision')) {
    function psHandleCoordinatorDecision($conn, $requestId, $action, $actorUsername, $actorName, array $actorProgramKeys, $comment = '') {
        $request = psGetRequestById($conn, $requestId);
        if (!$request) {
            return ['ok' => false, 'message' => 'Shift request not found.'];
        }

        $stage = psResolveCoordinatorStage($request);
        if ($stage === '') {
            return ['ok' => false, 'message' => 'This request is not pending Program Coordinator review.'];
        }

        $scopeProgram = $stage === 'current'
            ? (string)($request['current_program'] ?? '')
            : (string)($request['requested_program'] ?? '');
        if (!psProgramMatchesActorKeys($scopeProgram, $actorProgramKeys)) {
            return ['ok' => false, 'message' => $stage === 'current'
                ? 'You are not assigned to review the student\'s current program.'
                : 'You are not assigned to review this destination program.'];
        }

        $action = strtolower(trim((string)$action));
        if (!in_array($action, ['approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Invalid action.'];
        }

        $conn->begin_transaction();

        try {
            if ($action === 'approve') {
                $newStatus = $stage === 'current' ? 'pending_destination_coordinator' : 'approved';
            } else {
                $newStatus = 'rejected';
            }
            $update = $conn->prepare("UPDATE program_shift_requests
                SET status = ?, coordinator_action_by = ?, coordinator_action_name = ?, coordinator_action_at = NOW(), coordinator_comment = ?
                WHERE id = ? AND status = ?");
            if (!$update) {
                throw new RuntimeException('Unable to update request.');
            }

            $requestId = (int)$requestId;
            $currentStatus = (string)($request['status'] ?? '');
            $update->bind_param('ssssis', $newStatus, $actorUsername, $actorName, $comment, $requestId, $currentStatus);
            if (!$update->execute()) {
                $update->close();
                throw new RuntimeException('Unable to update request.');
            }
            if ((int)$update->affected_rows === 0) {
                $update->close();
                throw new RuntimeException('This request was already processed by another user. Please refresh the queue.');
            }
            $update->close();

            $approval = $conn->prepare('INSERT INTO program_shift_approvals (request_id, stage, action, actor_username, actor_name, actor_program, comments) VALUES (?, "coordinator", ?, ?, ?, ?, ?)');
            if ($approval) {
                $actorProgram = implode(', ', $actorProgramKeys);
                $approval->bind_param('isssss', $requestId, $action, $actorUsername, $actorName, $actorProgram, $comment);
                $approval->execute();
                $approval->close();
            }

            $auditMessage = 'Program Coordinator ' . $action . 'd the shift request.';
            if ($stage === 'current' && $action === 'approve') {
                $auditMessage = 'Current-program coordinator approved and forwarded the shift request to the destination-program coordinator.';
            } elseif ($stage === 'current' && $action === 'reject') {
                $auditMessage = 'Current-program coordinator rejected the shift request.';
            } elseif ($stage === 'destination' && $action === 'approve') {
                $auditMessage = 'Destination-program coordinator approved and executed the shift request.';
            } elseif ($stage === 'destination' && $action === 'reject') {
                $auditMessage = 'Destination-program coordinator rejected the shift request.';
            }

            psAddAuditLog($conn, $requestId, 'coordinator_' . $action, $auditMessage, $actorUsername, 'program_coordinator', [
                'comment' => $comment,
                'stage' => $stage,
                'next_status' => $newStatus,
            ]);

            if ($action === 'approve' && $stage === 'destination') {
                $executionResult = psExecuteApprovedShift($conn, $request, $actorUsername);
                if (!$executionResult['ok']) {
                    throw new RuntimeException((string)$executionResult['message']);
                }
            }

            $conn->commit();

            $studentRow = psGetCurrentStudentInfo($conn, (string)$request['student_number']);
            if ($studentRow) {
                if ($action === 'approve' && $stage === 'current') {
                    $details = 'Your request was approved by the Program Coordinator of your current program and has been forwarded to the Program Coordinator of your requested program.';
                    $statusLabel = 'Pending Destination Program Coordinator Review';
                } elseif ($action === 'approve') {
                    $details = 'Your request has been approved. Shift execution completed.';
                    $statusLabel = 'Approved';
                } else {
                    $details = $stage === 'current'
                        ? 'Your request was rejected by the Program Coordinator of your current program.'
                        : 'Your request was rejected by the Program Coordinator of your requested program.';
                    $statusLabel = 'Rejected by Program Coordinator';
                }
                psSendProgramShiftEmail(
                    (string)($studentRow['email'] ?? ''),
                    (string)($request['student_name'] ?? ''),
                    $statusLabel,
                    (string)($request['request_code'] ?? ''),
                    (string)($request['current_program'] ?? ''),
                    (string)($request['requested_program'] ?? ''),
                    $details
                );
            }

            return [
                'ok' => true,
                'message' => $action === 'approve'
                    ? ($stage === 'current'
                        ? 'Request approved and forwarded to the destination-program coordinator.'
                        : 'Request approved and shift execution completed.')
                    : 'Request rejected by Program Coordinator.',
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('psFetchStudentRequestHistory')) {
    function psFetchStudentRequestHistory($conn, $studentNumber) {
        $rows = [];
        $stmt = $conn->prepare('SELECT * FROM program_shift_requests WHERE student_number = ? ORDER BY requested_at DESC, id DESC');
        if (!$stmt) {
            return $rows;
        }

        $studentNumber = (string)$studentNumber;
        $stmt->bind_param('s', $studentNumber);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('psFetchAdviserQueue')) {
    function psFetchAdviserQueue($conn, array $programKeys, array $adviserBatches) {
        $rows = [];
        $result = $conn->query("SELECT * FROM program_shift_requests WHERE status = 'pending_adviser' ORDER BY requested_at ASC, id ASC");
        if (!$result) {
            return $rows;
        }

        while ($row = $result->fetch_assoc()) {
            if (psRequestMatchesAdviserScope($row, $programKeys, $adviserBatches)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}

if (!function_exists('psFetchCoordinatorQueue')) {
    function psFetchCoordinatorQueue($conn, array $programKeys) {
        $rows = [];
        $statuses = "'" . implode("','", array_map([$conn, 'real_escape_string'], psCoordinatorPendingStatuses())) . "'";
        $result = $conn->query("SELECT * FROM program_shift_requests WHERE status IN ($statuses) ORDER BY adviser_action_at ASC, requested_at ASC, id ASC");
        if (!$result) {
            return $rows;
        }

        while ($row = $result->fetch_assoc()) {
            $stage = psResolveCoordinatorStage($row);
            $scopeProgram = $stage === 'current'
                ? (string)($row['current_program'] ?? '')
                : (string)($row['requested_program'] ?? '');
            if (empty($programKeys) || psProgramMatchesActorKeys($scopeProgram, $programKeys)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}

if (!function_exists('psFetchAdviserActionLog')) {
    function psFetchAdviserActionLog($conn, $actorUsername, array $programKeys, array $adviserBatches, $limit = 12) {
        $rows = [];
        $actorUsername = trim((string)$actorUsername);
        if ($actorUsername === '') {
            return $rows;
        }

        $limit = (int)$limit;
        if ($limit < 1) {
            $limit = 12;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $sql = "SELECT
                    a.request_id,
                    a.action,
                    a.comments,
                    a.created_at AS action_at,
                    r.request_code,
                    r.student_number,
                    r.student_name,
                    r.current_program,
                    r.requested_program,
                    r.status
                FROM program_shift_approvals a
                INNER JOIN program_shift_requests r ON r.id = a.request_id
                WHERE a.stage = 'adviser'
                  AND a.actor_username = ?
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $rows;
        }

        $stmt->bind_param('si', $actorUsername, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            if (!psRequestMatchesAdviserScope($row, $programKeys, $adviserBatches)) {
                continue;
            }
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('psGetStudentShiftSummary')) {
    function psGetStudentShiftSummary($conn, $studentNumber) {
        $summary = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'latest_status' => null,
            'latest_requested_program' => null,
            'latest_requested_at' => null,
        ];

        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('pending_adviser', 'pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator') THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
             FROM program_shift_requests
             WHERE student_number = ?"
        );
        if ($stmt) {
            $studentNumber = trim((string)$studentNumber);
            $stmt->bind_param('s', $studentNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($row) {
                $summary['total'] = (int)($row['total'] ?? 0);
                $summary['pending'] = (int)($row['pending'] ?? 0);
                $summary['approved'] = (int)($row['approved'] ?? 0);
                $summary['rejected'] = (int)($row['rejected'] ?? 0);
            }
        }

        $latestStmt = $conn->prepare('SELECT status, requested_program, requested_at FROM program_shift_requests WHERE student_number = ? ORDER BY requested_at DESC, id DESC LIMIT 1');
        if ($latestStmt) {
            $studentNumber = trim((string)$studentNumber);
            $latestStmt->bind_param('s', $studentNumber);
            $latestStmt->execute();
            $latestResult = $latestStmt->get_result();
            $latestRow = $latestResult ? $latestResult->fetch_assoc() : null;
            $latestStmt->close();

            if ($latestRow) {
                $summary['latest_status'] = (string)($latestRow['status'] ?? '');
                $summary['latest_requested_program'] = (string)($latestRow['requested_program'] ?? '');
                $summary['latest_requested_at'] = (string)($latestRow['requested_at'] ?? '');
            }
        }

        return $summary;
    }
}

if (!function_exists('psGetAdviserShiftSummary')) {
    function psGetAdviserShiftSummary($conn, array $programKeys) {
        $summary = [
            'pending' => 0,
            'forwarded' => 0,
            'rejected' => 0,
        ];

        $result = $conn->query("SELECT status, current_program FROM program_shift_requests WHERE status IN ('pending_adviser', 'pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator', 'rejected')");
        if (!$result) {
            return $summary;
        }

        while ($row = $result->fetch_assoc()) {
            if (!psProgramMatchesActorKeys((string)($row['current_program'] ?? ''), $programKeys)) {
                continue;
            }

            $status = (string)($row['status'] ?? '');
            if ($status === 'pending_adviser') {
                $summary['pending']++;
            } elseif (in_array($status, ['pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator'], true)) {
                $summary['forwarded']++;
            } elseif ($status === 'rejected') {
                $summary['rejected']++;
            }
        }

        return $summary;
    }
}

if (!function_exists('psGetCoordinatorShiftSummary')) {
    function psGetCoordinatorShiftSummary($conn, array $programKeys) {
        $summary = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        $result = $conn->query("SELECT status FROM program_shift_requests WHERE status IN ('pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator', 'approved', 'rejected')");
        if (!$result) {
            return $summary;
        }

        while ($row = $result->fetch_assoc()) {
            $status = (string)($row['status'] ?? '');
            if (in_array($status, ['pending_current_coordinator', 'pending_destination_coordinator', 'pending_coordinator'], true)) {
                $summary['pending']++;
            } elseif ($status === 'approved') {
                $summary['approved']++;
            } elseif ($status === 'rejected') {
                $summary['rejected']++;
            }
        }

        return $summary;
    }
}
