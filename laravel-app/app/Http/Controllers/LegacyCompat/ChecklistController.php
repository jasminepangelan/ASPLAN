<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ChecklistController extends Controller
{
    public function view(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', ''));
            if ($studentId === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Student ID is required',
                ], 422);
            }

            $student = DB::table('student_info')
                ->select(['program', 'curriculum_year'])
                ->where('student_number', $studentId)
                ->first();

            if ($student === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Student not found',
                ], 404);
            }

            $selectedProgramLabel = trim((string) ($student->program ?? ''));
            $programAbbr = $this->resolveProgramAbbreviation($selectedProgramLabel);
            $requestedProgramView = trim((string) $request->input('program_view', ''));
            if ($requestedProgramView !== '') {
                $programAbbr = $requestedProgramView;
            }

            $canonicalProgramLabel = $this->canonicalProgramLabel($programAbbr);
            if ($canonicalProgramLabel !== '') {
                $selectedProgramLabel = $canonicalProgramLabel;
            }

            if ($programAbbr === '' && $selectedProgramLabel === '') {
                return response()->json([
                    'status' => 'success',
                    'courses' => [],
                ]);
            }

            $studentCurriculumYear = $this->normalizeCurriculumYear((string) ($student->curriculum_year ?? ''));
            $courses = $this->fetchChecklistCourses($studentId, $selectedProgramLabel, $programAbbr, $studentCurriculumYear, (string) ($student->program ?? ''));
            $courses = $this->normalizeCourseRows($courses);

            return response()->json([
                'status' => 'success',
                'courses' => $courses,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load checklist data',
            ], 500);
        }
    }

    public function save(Request $request): JsonResponse
    {
        try {
            if ($request->method() !== 'POST') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid request method',
                ], 405);
            }

            $isBulk = (bool) $request->input('bulk_approve', false);
            $saveContext = strtolower(trim((string) $request->input('save_context', 'staff')));

            if ($isBulk) {
                return $this->saveBulk($request);
            }

            if ($saveContext === 'student') {
                return $this->saveStudent($request);
            }

            return $this->saveStaff($request);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function saveBulk(Request $request): JsonResponse
    {
        $studentId = (string) $request->input('student_id', '');
        $courses = $this->parseArray($request->input('courses', []));
        $grades = $this->parseArray($request->input('grades', []));
        $professors = $this->parseArray($request->input('professors', []));

        if ($studentId === '' || empty($courses)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing required bulk approval data',
            ], 400);
        }

        if (!$this->studentExists($studentId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Student ID does not exist',
            ], 400);
        }

        $grades = $this->normalizeAssoc($grades, $courses);
        $professors = $this->normalizeAssoc($professors, $courses);

        $successful = 0;
        $timestamp = now();

        DB::transaction(function () use ($studentId, $courses, $grades, $professors, $timestamp, &$successful): void {
            foreach ($courses as $courseCode) {
                $courseCode = trim((string) $courseCode);
                if ($courseCode === '') {
                    continue;
                }

                $existing = DB::table('student_checklists')
                    ->select(['grade_submitted_at', 'submitted_by'])
                    ->where('student_id', $studentId)
                    ->where('course_code', $courseCode)
                    ->first();

                $payload = [
                    'final_grade' => (string) ($grades[$courseCode] ?? ''),
                    'evaluator_remarks' => 'Approved',
                    'professor_instructor' => (string) ($professors[$courseCode] ?? ''),
                    'grade_approved' => 1,
                    'approved_at' => $timestamp,
                    'approved_by' => 'adviser',
                ];

                if ($existing === null || empty($existing->grade_submitted_at)) {
                    $payload['grade_submitted_at'] = $timestamp;
                    $payload['submitted_by'] = !empty($existing?->submitted_by)
                        ? (string) $existing->submitted_by
                        : 'adviser';
                }

                DB::table('student_checklists')->updateOrInsert(
                    ['student_id' => $studentId, 'course_code' => $courseCode],
                    $payload
                );

                $successful++;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Bulk approved {$successful} records",
        ]);
    }

    private function saveStaff(Request $request): JsonResponse
    {
        $studentId = (string) $request->input('student_id', '');
        $courses = $this->parseArray($request->input('courses', []));
        $grades = $this->parseArray($request->input('final_grades', []));
        $grades2 = $this->parseArray($request->input('final_grades_2', []));
        $grades3 = $this->parseArray($request->input('final_grades_3', []));
        $remarks = $this->parseArray($request->input('evaluator_remarks', []));
        $professors = $this->parseArray($request->input('professor_instructors', []));

        if ($studentId === '' || empty($courses) || empty($grades) || empty($remarks)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing required course data',
            ], 400);
        }

        if (count($courses) !== count($grades) || count($courses) !== count($remarks)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data array length mismatch',
            ], 400);
        }

        if (!$this->studentExists($studentId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Student ID does not exist',
            ], 400);
        }

        $professors = $this->normalizeAssoc($professors, $courses);

        $successful = 0;
        $timestamp = now();

        DB::transaction(function () use ($studentId, $courses, $grades, $grades2, $grades3, $remarks, $professors, $timestamp, &$successful): void {
            foreach ($courses as $index => $courseCode) {
                $courseCode = trim((string) $courseCode);
                if ($courseCode === '') {
                    continue;
                }

                $grade = $this->normalizeString($grades[$index] ?? '');
                $grade2 = $this->normalizeString($grades2[$index] ?? '');
                $grade3 = $this->normalizeString($grades3[$index] ?? '');
                $remark = $this->normalizeString($remarks[$index] ?? '');
                $isApproved = ($remark === 'Approved' && $grade !== '' && $grade !== 'No Grade');

                $existing = DB::table('student_checklists')
                    ->select(['grade_submitted_at', 'submitted_by'])
                    ->where('student_id', $studentId)
                    ->where('course_code', $courseCode)
                    ->first();

                $payload = [
                    'final_grade' => $grade,
                    'evaluator_remarks' => $remark,
                    'professor_instructor' => $this->resolveCourseValue($professors, $index, $courseCode),
                    'grade_approved' => $isApproved ? 1 : 0,
                    'approved_at' => $isApproved ? $timestamp : null,
                    'approved_by' => $isApproved ? 'adviser' : null,
                ];

                if ($grade2 !== '') {
                    $payload['final_grade_2'] = $grade2;
                    $payload['evaluator_remarks_2'] = $remark;
                }

                if ($grade3 !== '') {
                    $payload['final_grade_3'] = $grade3;
                    $payload['evaluator_remarks_3'] = $remark;
                }

                if ($isApproved && ($existing === null || empty($existing->grade_submitted_at))) {
                    $payload['grade_submitted_at'] = $timestamp;
                    $payload['submitted_by'] = !empty($existing?->submitted_by)
                        ? (string) $existing->submitted_by
                        : 'adviser';
                }

                DB::table('student_checklists')->updateOrInsert(
                    ['student_id' => $studentId, 'course_code' => $courseCode],
                    $payload
                );

                $successful++;
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Successfully saved {$successful} records",
        ]);
    }

    private function saveStudent(Request $request): JsonResponse
    {
        $studentId = (string) $request->input('student_id', '');
        $courses = $this->parseArray($request->input('courses', []));
        $grades = $this->parseArray($request->input('final_grades', []));
        $grades2 = $this->parseArray($request->input('final_grades_2', []));
        $grades3 = $this->parseArray($request->input('final_grades_3', []));
        $professors = $this->parseArray($request->input('professor_instructors', []));

        if ($studentId === '' || empty($courses)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing required course data',
            ], 400);
        }

        if (!$this->studentExists($studentId)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Student ID does not exist',
            ], 400);
        }

        $successful = 0;
        $errors = [];
        $timestamp = now();

        DB::transaction(function () use ($studentId, $courses, $grades, $grades2, $grades3, $professors, $timestamp, &$successful, &$errors): void {
            foreach ($courses as $index => $courseCode) {
                $courseCode = trim((string) $courseCode);
                if ($courseCode === '') {
                    continue;
                }

                $existing = DB::table('student_checklists')
                    ->select([
                        'final_grade',
                        'evaluator_remarks',
                        'final_grade_2',
                        'evaluator_remarks_2',
                        'final_grade_3',
                        'evaluator_remarks_3',
                        'approved_by',
                        'submitted_by',
                    ])
                    ->where('student_id', $studentId)
                    ->where('course_code', $courseCode)
                    ->first();

                if ($this->isCreditedLockedRecord($existing)) {
                    $errors[] = "Skipped credited course (locked): {$courseCode}";
                    continue;
                }

                $grade = $this->normalizeString($grades[$index] ?? '');
                $grade2 = $this->normalizeString($grades2[$index] ?? '');
                $grade3 = $this->normalizeString($grades3[$index] ?? '');
                $hasIncomingSubmittedAttempt = $grade !== '' || $grade2 !== '' || $grade3 !== '';

                $payload = [
                    'professor_instructor' => $this->resolveCourseValue($professors, $index, $courseCode),
                ];

                $payload += $this->resolveStudentAttemptPayload($existing, $grade, $grade2, $grade3);
                $hasAnySavedAttempt = $this->hasAnySavedAttempt($payload);

                if ($hasIncomingSubmittedAttempt) {
                    $payload['grade_submitted_at'] = $timestamp;
                    $payload['submitted_by'] = 'student';
                } elseif (!$hasAnySavedAttempt) {
                    $payload['grade_submitted_at'] = null;
                    $payload['submitted_by'] = null;
                }

                DB::table('student_checklists')->updateOrInsert(
                    ['student_id' => $studentId, 'course_code' => $courseCode],
                    $payload
                );

                $successful++;
            }
        });

        return response()->json([
            'status' => 'success',
            'updated' => $successful,
            'errors' => $errors,
        ]);
    }

    private function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function resolveCourseValue(array $values, int $index, string $courseCode): string
    {
        if (array_values($values) !== $values) {
            return $this->normalizeString($values[$courseCode] ?? '');
        }

        return $this->normalizeString($values[$index] ?? '');
    }

    private function resolveStudentAttemptRemark(mixed $incomingGrade, mixed $existingGrade, mixed $existingRemark): string
    {
        $incoming = $this->normalizeString($incomingGrade);
        if ($incoming === '' || $incoming === 'No Grade') {
            return $this->normalizeString($existingRemark);
        }

        $currentGrade = $this->normalizeString($existingGrade);
        if ($currentGrade === $incoming) {
            $remark = $this->normalizeString($existingRemark);
            return $remark !== '' ? $remark : 'Pending';
        }

        return 'Pending';
    }

    private function resolveStudentAttemptPayload(object|null $existing, string $grade1, string $grade2, string $grade3): array
    {
        $attempts = [
            1 => [
                'grade' => $this->normalizeString($existing?->final_grade ?? ''),
                'remark' => $this->normalizeString($existing?->evaluator_remarks ?? ''),
            ],
            2 => [
                'grade' => $this->normalizeString($existing?->final_grade_2 ?? ''),
                'remark' => $this->normalizeString($existing?->evaluator_remarks_2 ?? ''),
            ],
            3 => [
                'grade' => $this->normalizeString($existing?->final_grade_3 ?? ''),
                'remark' => $this->normalizeString($existing?->evaluator_remarks_3 ?? ''),
            ],
        ];

        $incomingGrades = [
            1 => $this->normalizeString($grade1),
            2 => $this->normalizeString($grade2),
            3 => $this->normalizeString($grade3),
        ];

        foreach ($incomingGrades as $preferredSlot => $incomingGrade) {
            $this->applyIncomingStudentAttempt($attempts, $preferredSlot, $incomingGrade);
        }

        return [
            'final_grade' => $attempts[1]['grade'],
            'evaluator_remarks' => $attempts[1]['remark'],
            'final_grade_2' => $attempts[2]['grade'],
            'evaluator_remarks_2' => $attempts[2]['remark'],
            'final_grade_3' => $attempts[3]['grade'],
            'evaluator_remarks_3' => $attempts[3]['remark'],
        ];
    }

    private function applyIncomingStudentAttempt(array &$attempts, int $preferredSlot, string $incomingGrade): void
    {
        if ($incomingGrade === '' || $incomingGrade === 'No Grade') {
            if (!$this->isLockedApprovedAttempt($attempts[$preferredSlot]['remark'] ?? '')) {
                $attempts[$preferredSlot]['grade'] = '';
                $attempts[$preferredSlot]['remark'] = '';
            }
            return;
        }

        $preferredSlot = max(1, min(3, $preferredSlot));
        $existingGrade = $this->normalizeString($attempts[$preferredSlot]['grade'] ?? '');
        $existingRemark = $this->normalizeString($attempts[$preferredSlot]['remark'] ?? '');

        if ($existingGrade === $incomingGrade) {
            $attempts[$preferredSlot]['remark'] = $this->resolveStudentAttemptRemark($incomingGrade, $existingGrade, $existingRemark);
            return;
        }

        $targetSlot = $this->resolveStudentSubmissionTargetSlot($attempts, $preferredSlot);
        $targetExistingGrade = $this->normalizeString($attempts[$targetSlot]['grade'] ?? '');
        $targetExistingRemark = $this->normalizeString($attempts[$targetSlot]['remark'] ?? '');

        $attempts[$targetSlot]['grade'] = $incomingGrade;
        $attempts[$targetSlot]['remark'] = $this->resolveStudentAttemptRemark($incomingGrade, $targetExistingGrade, $targetExistingRemark);
    }

    private function resolveStudentSubmissionTargetSlot(array $attempts, int $preferredSlot): int
    {
        $preferredSlot = max(1, min(3, $preferredSlot));

        if (!$this->isLockedApprovedAttempt($attempts[$preferredSlot]['remark'] ?? '')) {
            return $preferredSlot;
        }

        for ($slot = $preferredSlot + 1; $slot <= 3; $slot++) {
            if (!$this->isLockedApprovedAttempt($attempts[$slot]['remark'] ?? '')) {
                return $slot;
            }
        }

        return $preferredSlot;
    }

    private function hasAnySavedAttempt(array $payload): bool
    {
        $grades = [
            $this->normalizeString($payload['final_grade'] ?? ''),
            $this->normalizeString($payload['final_grade_2'] ?? ''),
            $this->normalizeString($payload['final_grade_3'] ?? ''),
        ];

        foreach ($grades as $grade) {
            if ($grade !== '' && $grade !== 'No Grade') {
                return true;
            }
        }

        return false;
    }

    private function isLockedApprovedAttempt(mixed $remark): bool
    {
        return $this->normalizeString($remark) === 'Approved';
    }

    private function isCreditedLockedRecord(object|null $record): bool
    {
        if ($record === null) {
            return false;
        }

        $remarks = [
            $this->normalizeString($record->evaluator_remarks ?? ''),
            $this->normalizeString($record->evaluator_remarks_2 ?? ''),
            $this->normalizeString($record->evaluator_remarks_3 ?? ''),
        ];

        foreach ($remarks as $remark) {
            if ($remark !== '' && str_contains(strtolower($remark), 'credited')) {
                return true;
            }
        }

        return strtolower($this->normalizeString($record->approved_by ?? '')) === 'shift_engine'
            || strtolower($this->normalizeString($record->submitted_by ?? '')) === 'shift_engine';
    }

    private function studentExists(string $studentId): bool
    {
        return DB::table('student_info')->where('student_number', $studentId)->exists();
    }

    private function normalizeProgramLabel(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    private function resolveProgramAbbreviation(string $programName): string
    {
        $normalized = $this->normalizeProgramLabel($programName);
        if ($normalized === '') {
            return '';
        }

        $abbrAliases = [
            'BSBA MM' => 'BSBA-MM',
            'BSBA HRM' => 'BSBA-HRM',
            'BSCPE' => 'BSCpE',
            'BSCS' => 'BSCS',
            'BSHM' => 'BSHM',
            'BSINDT' => 'BSIndT',
            'BSIT' => 'BSIT',
            'BSED ENGLISH' => 'BSEd-English',
            'BSED MATH' => 'BSEd-Math',
            'BSED SCIENCE' => 'BSEd-Science',
        ];
        if (isset($abbrAliases[$normalized])) {
            return $abbrAliases[$normalized];
        }

        if (str_contains($normalized, 'BUSINESS ADMINISTRATION') && str_contains($normalized, 'MARKETING')) {
            return 'BSBA-MM';
        }
        if (str_contains($normalized, 'BUSINESS ADMINISTRATION') &&
            (str_contains($normalized, 'HUMAN RESOURCE') || str_contains($normalized, 'HRM'))) {
            return 'BSBA-HRM';
        }

        $programMap = [
            'Bachelor of Science in Business Administration - Major in Marketing Management' => 'BSBA-MM',
            'Bachelor of Science in Business Administration - Major in Human Resource Management' => 'BSBA-HRM',
            'Bachelor of Science in Computer Engineering' => 'BSCpE',
            'Bachelor of Science in Computer Science' => 'BSCS',
            'Bachelor of Science in Hospitality Management' => 'BSHM',
            'Bachelor of Science in Industrial Technology' => 'BSIndT',
            'Bachelor of Science in Information Technology' => 'BSIT',
            'Bachelor of Secondary Education major in English' => 'BSEd-English',
            'Bachelor of Secondary Education major Math' => 'BSEd-Math',
            'Bachelor of Secondary Education major in Science' => 'BSEd-Science',
        ];

        foreach ($programMap as $label => $abbr) {
            if ($this->normalizeProgramLabel($label) === $normalized) {
                return $abbr;
            }
        }

        return '';
    }

    private function canonicalProgramLabel(string $programKey): string
    {
        $normalizedKey = strtoupper(trim($programKey));
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

    private function resolveChecklistProgramLabels(string $programLabel, string $programKey = ''): array
    {
        $candidates = [];
        $values = [
            trim($programLabel),
            $this->canonicalProgramLabel($programKey),
            $this->canonicalProgramLabel($this->resolveProgramAbbreviation($programLabel)),
        ];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $candidates[$value] = true;

            $normalized = $this->normalizeProgramLabel($value);
            if ($normalized === 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION MAJOR IN MARKETING MANAGEMENT') {
                $candidates['Bachelor of Science in Business Administration - Major in Marketing Management'] = true;
            } elseif ($normalized === 'BACHELOR OF SCIENCE IN BUSINESS ADMINISTRATION MAJOR IN HUMAN RESOURCE MANAGEMENT') {
                $candidates['Bachelor of Science in Business Administration - Major in Human Resource Management'] = true;
            } elseif ($normalized === 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATH') {
                $candidates['Bachelor of Secondary Education Major in Mathematics'] = true;
            } elseif ($normalized === 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATHEMATICS') {
                $candidates['Bachelor of Secondary Education major Math'] = true;
            }
        }

        return array_keys($candidates);
    }

    private function resolveProgramTokens(string $programLabel, string $programKey = ''): array
    {
        $tokens = [];
        $values = [$programKey, $this->resolveProgramAbbreviation($programLabel)];

        foreach ($values as $value) {
            $value = strtoupper(trim((string) $value));
            if ($value === '') {
                continue;
            }

            $tokens[$value] = true;
            if ($value === 'BSCPE' || $value === 'BSCOE') {
                $tokens['BSCPE'] = true;
                $tokens['BSCOE'] = true;
            }
            if ($value === 'BSINDT' || $value === 'BSINDTECH') {
                $tokens['BSINDT'] = true;
                $tokens['BSINDTECH'] = true;
            }
        }

        return array_keys($tokens);
    }

    private function normalizeCurriculumYear(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}$/', $value)) {
            return $value;
        }

        return '';
    }

    private function latestCurriculumYear(string $programLabel, string $programKey = ''): string
    {
        $programLabels = $this->resolveChecklistProgramLabels($programLabel, $programKey);
        if (Schema::hasTable('curriculum_courses') && !empty($programLabels)) {
            $query = DB::table('curriculum_courses')->selectRaw('MAX(curriculum_year) AS latest_year');
            $query->where(function ($builder) use ($programLabels): void {
                foreach (array_values($programLabels) as $index => $label) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $builder->{$method}('UPPER(TRIM(program)) = ?', [strtoupper(trim($label))]);
                }
            });

            $row = $query->first();
            $year = $this->normalizeCurriculumYear((string) ($row->latest_year ?? ''));
            if ($year !== '') {
                return $year;
            }
        }

        $normalizedProgramKey = strtoupper(trim($programKey !== '' ? $programKey : $this->resolveProgramAbbreviation($programLabel)));
        if ($normalizedProgramKey !== '' && Schema::hasTable('program_curriculum_years')) {
            $year = $this->normalizeCurriculumYear((string) DB::table('program_curriculum_years')
                ->where('program', $normalizedProgramKey)
                ->max('curriculum_year'));
            if ($year !== '') {
                return $year;
            }
        }

        if (!Schema::hasTable('cvsucarmona_courses')) {
            return '';
        }

        $tokens = $this->resolveProgramTokens($programLabel, $programKey);
        if (empty($tokens)) {
            return '';
        }

        $conditions = [];
        $bindings = [];
        foreach ($tokens as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(programs), " ", "")) > 0';
            $bindings[] = $token;
        }

        $rows = DB::select(
            'SELECT MAX(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, "_", 1))) AS latest_year
             FROM cvsucarmona_courses
             WHERE ' . implode(' OR ', $conditions),
            $bindings
        );
        $row = $rows[0] ?? null;

        return $this->normalizeCurriculumYear((string) ($row->latest_year ?? ''));
    }

    private function resolveStudentCurriculumYear(string $studentId, string $programLabel = '', string $programKey = '', string $storedCurriculumYear = '', string $storedProgramLabel = ''): string
    {
        $selectedProgramKey = strtoupper(trim($programKey !== '' ? $programKey : $this->resolveProgramAbbreviation($programLabel)));
        $storedProgramKey = strtoupper(trim($this->resolveProgramAbbreviation($storedProgramLabel)));
        $storedCurriculumYear = $this->normalizeCurriculumYear($storedCurriculumYear);

        if ($selectedProgramKey !== '' && $storedProgramKey !== '' && $selectedProgramKey !== $storedProgramKey) {
            return $this->latestCurriculumYear($programLabel, $programKey);
        }

        if ($storedCurriculumYear !== '') {
            return $storedCurriculumYear;
        }

        return $this->latestCurriculumYear($programLabel, $programKey);
    }

    private function fetchChecklistCourses(string $studentId, string $programLabel, string $programKey = '', string $storedCurriculumYear = '', string $storedProgramLabel = ''): array
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return [];
        }

        $programLabels = $this->resolveChecklistProgramLabels($programLabel, $programKey);
        $curriculumYear = $this->resolveStudentCurriculumYear($studentId, $programLabel, $programKey, $storedCurriculumYear, $storedProgramLabel);
        if (Schema::hasTable('curriculum_courses') && !empty($programLabels)) {
            $conditions = [];
            $bindings = [$studentId];
            foreach ($programLabels as $candidateLabel) {
                $conditions[] = 'UPPER(TRIM(cc.program)) = ?';
                $bindings[] = strtoupper(trim($candidateLabel));
            }

            $curriculumYearClause = '';
            if ($curriculumYear !== '') {
                $curriculumYearClause = ' AND cc.curriculum_year = ?';
                $bindings[] = $curriculumYear;
            }

            $sql = '
                SELECT
                    TRIM(cc.course_code) AS course_code,
                    TRIM(cc.course_title) AS course_title,
                    IFNULL(cc.credit_units_lec, 0) AS credit_unit_lec,
                    IFNULL(cc.credit_units_lab, 0) AS credit_unit_lab,
                    IFNULL(cc.lect_hrs_lec, 0) AS contact_hrs_lec,
                    IFNULL(cc.lect_hrs_lab, 0) AS contact_hrs_lab,
                    TRIM(IFNULL(cc.pre_requisite, "NONE")) AS pre_requisite,
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
                WHERE (' . implode(' OR ', $conditions) . ')' . $curriculumYearClause . '
                ORDER BY
                    IFNULL(cc.curriculum_year, 0),
                    CASE UPPER(TRIM(cc.year_level))
                        WHEN "FIRST YEAR" THEN 1
                        WHEN "SECOND YEAR" THEN 2
                        WHEN "THIRD YEAR" THEN 3
                        WHEN "FOURTH YEAR" THEN 4
                        ELSE 99
                    END,
                    CASE UPPER(TRIM(cc.semester))
                        WHEN "FIRST SEMESTER" THEN 1
                        WHEN "SECOND SEMESTER" THEN 2
                        WHEN "MID YEAR" THEN 3
                        WHEN "MIDYEAR" THEN 3
                        WHEN "SUMMER" THEN 3
                        ELSE 99
                    END,
                    cc.id,
                    TRIM(cc.course_code)
            ';

            $rows = DB::select($sql, $bindings);
            $courses = array_map(static fn ($row): array => (array) $row, $rows);
            if (!empty($courses)) {
                return $this->normalizeCourseRows($courses);
            }
        }

        if (!Schema::hasTable('cvsucarmona_courses')) {
            return [];
        }

        $tokens = $this->resolveProgramTokens($programLabel, $programKey);
        if (empty($tokens)) {
            return [];
        }

        $conditions = [];
        $bindings = [$studentId];
        foreach ($tokens as $token) {
            $conditions[] = 'FIND_IN_SET(?, REPLACE(UPPER(c.programs), " ", "")) > 0';
            $bindings[] = $token;
        }

        $curriculumYearClause = '';
        if ($curriculumYear !== '') {
            $curriculumYearClause = ' AND TRIM(SUBSTRING_INDEX(c.curriculumyear_coursecode, "_", 1)) = ?';
            $bindings[] = $curriculumYear;
        }

        $sql = '
            SELECT DISTINCT
                TRIM(SUBSTRING_INDEX(c.curriculumyear_coursecode, "_", -1)) AS course_code,
                TRIM(c.course_title) AS course_title,
                IFNULL(c.credit_units_lec, 0) AS credit_unit_lec,
                IFNULL(c.credit_units_lab, 0) AS credit_unit_lab,
                IFNULL(c.lect_hrs_lec, 0) AS contact_hrs_lec,
                IFNULL(c.lect_hrs_lab, 0) AS contact_hrs_lab,
                TRIM(IFNULL(c.pre_requisite, "NONE")) AS pre_requisite,
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
                ON TRIM(SUBSTRING_INDEX(c.curriculumyear_coursecode, "_", -1)) = sc.course_code
                AND sc.student_id = ?
            WHERE (' . implode(' OR ', $conditions) . ')' . $curriculumYearClause . '
            ORDER BY
                CASE UPPER(TRIM(c.year_level))
                    WHEN "FIRST YEAR" THEN 1
                    WHEN "SECOND YEAR" THEN 2
                    WHEN "THIRD YEAR" THEN 3
                    WHEN "FOURTH YEAR" THEN 4
                    ELSE 99
                END,
                CASE UPPER(TRIM(c.semester))
                    WHEN "FIRST SEMESTER" THEN 1
                    WHEN "SECOND SEMESTER" THEN 2
                    WHEN "MID YEAR" THEN 3
                    WHEN "MIDYEAR" THEN 3
                    WHEN "SUMMER" THEN 3
                    ELSE 99
                END,
                c.curriculumyear_coursecode
        ';

        $rows = DB::select($sql, $bindings);

        return $this->normalizeCourseRows(array_map(static fn ($row): array => (array) $row, $rows));
    }

    private function parseArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeAssoc(array $values, array $keys): array
    {
        if (array_values($values) !== $values) {
            return $values;
        }

        $result = [];
        foreach ($keys as $index => $key) {
            $result[(string) $key] = $values[$index] ?? '';
        }

        return $result;
    }

    private function normalizeCourseRows(array $rows): array
    {
        $normalized = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $row['course_code'] = $this->normalizeCourseCode((string) ($row['course_code'] ?? ''));
            if ($row['course_code'] === '') {
                continue;
            }

            $dedupeKey = implode('|', [
                strtoupper($row['course_code']),
                strtoupper(trim((string) ($row['course_title'] ?? ''))),
                strtoupper(trim((string) ($row['year'] ?? ''))),
                strtoupper(trim((string) ($row['semester'] ?? ''))),
            ]);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $normalized[] = $row;
        }

        return $normalized;
    }

    private function normalizeCourseCode(string $value): string
    {
        $code = trim($value);
        if ($code === '') {
            return '';
        }

        foreach ([' CS-IT', ' CpE', ' CPE', ' IndT', ' INDT', ' CS', ' IT'] as $suffix) {
            if (strlen($code) > strlen($suffix) && strcasecmp(substr($code, -strlen($suffix)), $suffix) === 0) {
                return trim(substr($code, 0, -strlen($suffix)));
            }
        }

        return $code;
    }
}
