<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CurriculumManagementController extends Controller
{
    public function save(Request $request): JsonResponse
    {
        try {
            if (!$this->isAuthorizedCoordinator($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $input = $request->all();
            if (!is_array($input) || empty($input['program']) || empty($input['curriculum_year']) || !array_key_exists('courses', $input)) {
                return response()->json(['success' => false, 'message' => 'Missing required fields'], 422);
            }

            $program = trim((string) $input['program']);
            $curriculumYear = trim((string) $input['curriculum_year']);
            $courses = is_array($input['courses']) ? $input['courses'] : [];
            $deletedCourses = is_array($input['deleted_courses'] ?? null) ? $input['deleted_courses'] : [];

            if (!$this->isValidProgram($program)) {
                return response()->json(['success' => false, 'message' => 'Invalid program'], 422);
            }

            if (!preg_match('/^\d{4}$/', $curriculumYear) || (int) $curriculumYear < 2017 || (int) $curriculumYear > 2099) {
                return response()->json(['success' => false, 'message' => 'Invalid curriculum year'], 422);
            }

            $prefix = $curriculumYear . '_';
            $canonicalProgramLabel = $this->canonicalProgramLabel($program);
            if ($canonicalProgramLabel === '') {
                return response()->json(['success' => false, 'message' => 'Unable to resolve program label for curriculum sync.'], 422);
            }

            DB::statement(
                "CREATE TABLE IF NOT EXISTS program_curriculum_years (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    program VARCHAR(64) NOT NULL,
                    curriculum_year CHAR(4) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_program_year (program, curriculum_year)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            DB::beginTransaction();

            DB::table('program_curriculum_years')->updateOrInsert(
                ['program' => $program, 'curriculum_year' => $curriculumYear],
                ['updated_at' => now()]
            );

            $validYears = ['First Year', 'Second Year', 'Third Year', 'Fourth Year'];
            $validSemesters = ['First Semester', 'Second Semester', 'Mid Year'];

            $codeMap = [];
            foreach ($courses as $course) {
                $courseCode = strtoupper($this->normalizeCourseCode((string) ($course['course_code'] ?? '')));
                if ($courseCode === '') {
                    continue;
                }

                if (!isset($codeMap[$courseCode])) {
                    $codeMap[$courseCode] = [];
                }

                $codeMap[$courseCode][] = trim((string) ($course['course_title'] ?? ''));
            }

            $conflicts = [];
            foreach ($codeMap as $code => $titles) {
                if (count($titles) > 1) {
                    $uniqueTitles = array_values(array_unique(array_filter($titles, static fn ($value) => $value !== '')));
                    $conflicts[] = count($uniqueTitles) > 1 ? ($code . ' (' . implode(' / ', $uniqueTitles) . ')') : $code;
                }
            }

            if (!empty($conflicts)) {
                $this->safeRollback();
                return response()->json([
                    'success' => false,
                    'message' => 'Conflicting course codes found: ' . implode(', ', $conflicts),
                ], 422);
            }

            $changed = 0;
            $curriculumRowsToSync = [];

            foreach ($deletedCourses as $deletedCodeRaw) {
                $deletedToken = trim((string) $deletedCodeRaw);
                if ($deletedToken === '') {
                    continue;
                }

                $lookupKey = preg_match('/^\d{4}_.+$/', $deletedToken) ? $deletedToken : ($prefix . $deletedToken);
                $existingDelete = DB::table('cvsucarmona_courses')
                    ->select(['curriculumyear_coursecode', 'programs'])
                    ->where('curriculumyear_coursecode', $lookupKey)
                    ->first();

                if ($existingDelete === null) {
                    continue;
                }

                $programs = array_values(array_filter(array_map('trim', explode(',', (string) ($existingDelete->programs ?? '')))));
                if (!in_array($program, $programs, true)) {
                    continue;
                }

                if (count($programs) === 1) {
                    DB::table('cvsucarmona_courses')
                        ->where('curriculumyear_coursecode', $lookupKey)
                        ->delete();
                    $changed++;
                    continue;
                }

                $remainingPrograms = array_values(array_filter($programs, static fn ($value) => $value !== $program));
                DB::table('cvsucarmona_courses')
                    ->where('curriculumyear_coursecode', $lookupKey)
                    ->update(['programs' => implode(', ', $remainingPrograms)]);
                $changed++;
            }

            foreach ($courses as $course) {
                $courseCode = $this->normalizeCourseCode((string) ($course['course_code'] ?? ''));
                $courseTitle = trim((string) ($course['course_title'] ?? ''));
                $yearLevel = trim((string) ($course['year_level'] ?? ''));
                $semester = trim((string) ($course['semester'] ?? ''));
                $originalCourseCode = $this->normalizeCourseCode((string) ($course['original_course_code'] ?? ''));
                $originalCurriculumKey = trim((string) ($course['original_curriculum_key'] ?? ''));
                $curriculumKeyPrefix = trim((string) ($course['curriculum_key_prefix'] ?? ''));

                if ($courseCode === '' || $courseTitle === '') {
                    continue;
                }
                if (!in_array($yearLevel, $validYears, true) || !in_array($semester, $validSemesters, true)) {
                    continue;
                }

                $keyPrefix = $prefix;
                if ($originalCurriculumKey !== '' && preg_match('/^([^_]+)_.+$/', $originalCurriculumKey, $matches)) {
                    $keyPrefix = trim((string) ($matches[1] ?? $prefix));
                } elseif ($curriculumKeyPrefix !== '') {
                    $keyPrefix = $curriculumKeyPrefix;
                }

                $key = $keyPrefix . '_' . $courseCode;
                $lookupKey = $originalCurriculumKey !== '' ? $originalCurriculumKey : ($originalCourseCode !== '' ? $prefix . $originalCourseCode : $key);
                $hasOriginalIdentity = $originalCurriculumKey !== '' || $originalCourseCode !== '';
                $creditLec = (int) ($course['credit_units_lec'] ?? 0);
                $creditLab = (int) ($course['credit_units_lab'] ?? 0);
                $hrsLec = (int) ($course['lect_hrs_lec'] ?? 0);
                $hrsLab = (int) ($course['lect_hrs_lab'] ?? 0);
                $prereq = trim((string) ($course['pre_requisite'] ?? '')) ?: 'NONE';
                $curriculumRowsToSync[] = [
                    'year_level' => $yearLevel,
                    'semester' => $semester,
                    'course_code' => $courseCode,
                    'course_title' => $courseTitle,
                    'credit_units_lec' => $creditLec,
                    'credit_units_lab' => $creditLab,
                    'lect_hrs_lec' => $hrsLec,
                    'lect_hrs_lab' => $hrsLab,
                    'pre_requisite' => $prereq,
                    'original_course_code' => $originalCourseCode,
                    'original_curriculum_key' => $originalCurriculumKey,
                ];

                if ($lookupKey !== $key) {
                    $conflict = DB::table('cvsucarmona_courses')
                        ->where('curriculumyear_coursecode', $key)
                        ->exists();
                    if ($conflict) {
                        $this->safeRollback();
                        return response()->json([
                            'success' => false,
                            'message' => 'Course code already exists for this curriculum year: ' . $courseCode,
                        ], 422);
                    }
                }

                $existing = DB::table('cvsucarmona_courses')
                    ->select([
                        'curriculumyear_coursecode',
                        'programs',
                        'course_title',
                        'year_level',
                        'semester',
                        'credit_units_lec',
                        'credit_units_lab',
                        'lect_hrs_lec',
                        'lect_hrs_lab',
                        'pre_requisite',
                    ])
                    ->where('curriculumyear_coursecode', $lookupKey)
                    ->first();

                if ($existing !== null) {
                    $programList = array_map('trim', explode(',', (string) ($existing->programs ?? '')));
                    if (!in_array($program, $programList, true)) {
                        $programList[] = $program;
                    }

                    $newPrograms = implode(', ', array_values(array_filter($programList)));
                    $needsUpdate = (
                        (string) ($existing->curriculumyear_coursecode ?? '') !== $key
                        || (string) ($existing->course_title ?? '') !== $courseTitle
                        || (string) ($existing->year_level ?? '') !== $yearLevel
                        || (string) ($existing->semester ?? '') !== $semester
                        || (int) ($existing->credit_units_lec ?? 0) !== $creditLec
                        || (int) ($existing->credit_units_lab ?? 0) !== $creditLab
                        || (int) ($existing->lect_hrs_lec ?? 0) !== $hrsLec
                        || (int) ($existing->lect_hrs_lab ?? 0) !== $hrsLab
                        || (string) ($existing->pre_requisite ?? 'NONE') !== $prereq
                        || (string) ($existing->programs ?? '') !== $newPrograms
                    );

                    if ($needsUpdate) {
                        DB::table('cvsucarmona_courses')
                            ->where('curriculumyear_coursecode', $lookupKey)
                            ->update([
                                'curriculumyear_coursecode' => $key,
                                'programs' => $newPrograms,
                                'course_title' => $courseTitle,
                                'year_level' => $yearLevel,
                                'semester' => $semester,
                                'credit_units_lec' => $creditLec,
                                'credit_units_lab' => $creditLab,
                                'lect_hrs_lec' => $hrsLec,
                                'lect_hrs_lab' => $hrsLab,
                                'pre_requisite' => $prereq,
                            ]);

                        if ($lookupKey !== $key && $originalCourseCode !== '') {
                            DB::table('student_checklists')
                                ->where('course_code', $originalCourseCode)
                                ->update(['course_code' => $courseCode]);

                            DB::table('student_study_plan_overrides')
                                ->where('course_code', $originalCourseCode)
                                ->update(['course_code' => $courseCode]);
                        }
                        $changed++;
                    }
                } else {
                    if ($hasOriginalIdentity) {
                        $this->safeRollback();
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to update course: original entry was not found. Please reload the curriculum and try again.',
                        ], 422);
                    }

                    DB::table('cvsucarmona_courses')->insert([
                        'curriculumyear_coursecode' => $key,
                        'programs' => $program,
                        'course_title' => $courseTitle,
                        'year_level' => $yearLevel,
                        'semester' => $semester,
                        'credit_units_lec' => $creditLec,
                        'credit_units_lab' => $creditLab,
                        'lect_hrs_lec' => $hrsLec,
                        'lect_hrs_lab' => $hrsLab,
                        'pre_requisite' => $prereq,
                    ]);
                    $changed++;
                }
            }

            $duplicateCodes = DB::table('cvsucarmona_courses')
                ->selectRaw("UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1))) AS normalized_code")
                ->where('curriculumyear_coursecode', 'like', $prefix . '%')
                ->whereRaw("FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0", [$program])
                ->groupByRaw("UPPER(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)))")
                ->havingRaw('COUNT(*) > 1')
                ->pluck('normalized_code')
                ->filter(static fn ($value) => (string) $value !== '')
                ->values()
                ->all();

            if (!empty($duplicateCodes)) {
                $this->safeRollback();
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate course codes detected for this curriculum year: ' . implode(', ', array_values(array_unique($duplicateCodes))),
                ], 422);
            }

            $this->ensureCurriculumCoursesTable();
            $rowsToInsert = $this->buildSyncedCurriculumRows(
                $program,
                $curriculumYear,
                $canonicalProgramLabel,
                $curriculumRowsToSync,
                $deletedCourses
            );
            DB::table('curriculum_courses')
                ->where('curriculum_year', (int) $curriculumYear)
                ->whereRaw('UPPER(TRIM(program)) = ?', [strtoupper($canonicalProgramLabel)])
                ->delete();

            if (!empty($rowsToInsert)) {
                DB::table('curriculum_courses')->insert($rowsToInsert);
            }

            DB::commit();

            $message = $changed > 0
                ? 'Curriculum changes saved successfully.'
                : 'Curriculum year saved successfully. You can add courses later.';

            return response()->json([
                'success' => true,
                'message' => $message,
                'inserted' => $changed,
            ]);
        } catch (Throwable $e) {
            $this->safeRollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to save curriculum: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteYear(Request $request): JsonResponse
    {
        try {
            if (!$this->isAuthorizedCoordinator($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $input = $request->all();
            if (!is_array($input) || empty($input['program']) || empty($input['curriculum_year'])) {
                return response()->json(['success' => false, 'message' => 'Missing required fields'], 422);
            }

            $program = trim((string) $input['program']);
            $curriculumYear = trim((string) $input['curriculum_year']);

            if (!$this->isValidProgram($program)) {
                return response()->json(['success' => false, 'message' => 'Invalid program'], 422);
            }

            if (!preg_match('/^\d{4}$/', $curriculumYear) || (int) $curriculumYear < 2017 || (int) $curriculumYear > 2099) {
                return response()->json(['success' => false, 'message' => 'Invalid curriculum year'], 422);
            }

            $prefix = $curriculumYear . '_';
            $canonicalProgramLabel = $this->canonicalProgramLabel($program);
            if ($canonicalProgramLabel === '') {
                return response()->json(['success' => false, 'message' => 'Unable to resolve program label for curriculum deletion.'], 422);
            }

            DB::statement(
                "CREATE TABLE IF NOT EXISTS program_curriculum_years (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    program VARCHAR(64) NOT NULL,
                    curriculum_year CHAR(4) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_program_year (program, curriculum_year)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            DB::beginTransaction();

            $existingRows = DB::table('cvsucarmona_courses')
                ->select(['curriculumyear_coursecode', 'programs'])
                ->where('curriculumyear_coursecode', 'like', $prefix . '%')
                ->get();

            foreach ($existingRows as $row) {
                $programs = array_map('trim', explode(',', (string) ($row->programs ?? '')));
                if (!in_array($program, $programs, true)) {
                    continue;
                }

                if (count($programs) === 1) {
                    DB::table('cvsucarmona_courses')
                        ->where('curriculumyear_coursecode', $row->curriculumyear_coursecode)
                        ->delete();
                } else {
                    $programs = array_values(array_filter($programs, static fn ($value) => $value !== $program));
                    DB::table('cvsucarmona_courses')
                        ->where('curriculumyear_coursecode', $row->curriculumyear_coursecode)
                        ->update(['programs' => implode(', ', $programs)]);
                }
            }

            DB::table('program_curriculum_years')
                ->whereIn('program', array_values(array_unique([$program, $canonicalProgramLabel])))
                ->where('curriculum_year', $curriculumYear)
                ->delete();

            $this->ensureCurriculumCoursesTable();
            DB::table('curriculum_courses')
                ->where('curriculum_year', (int) $curriculumYear)
                ->whereRaw('UPPER(TRIM(program)) = ?', [strtoupper($canonicalProgramLabel)])
                ->delete();

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Curriculum year deleted successfully.']);
        } catch (Throwable $e) {
            $this->safeRollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete curriculum year: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function studyPlanOverride(Request $request): JsonResponse
    {
        try {
            if (!$this->isAuthorizedCoordinator($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $input = $request->all();
            if (!is_array($input)) {
                return response()->json(['success' => false, 'message' => 'Invalid payload'], 422);
            }

            $studentId = trim((string) ($input['student_id'] ?? ''));
            $courseCode = trim((string) ($input['course_code'] ?? ''));
            $targetYear = trim((string) ($input['target_year'] ?? ''));
            $targetSemester = trim((string) ($input['target_semester'] ?? ''));

            if ($studentId === '' || $courseCode === '' || $targetYear === '' || $targetSemester === '') {
                return response()->json(['success' => false, 'message' => 'Missing required fields'], 422);
            }

            $validYears = ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
            $validSemesters = ['1st Sem', '2nd Sem', 'Mid Year'];
            if (!in_array($targetYear, $validYears, true) || !in_array($targetSemester, $validSemesters, true)) {
                return response()->json(['success' => false, 'message' => 'Invalid target term'], 422);
            }

            $student = DB::table('student_info')->where('student_number', $studentId)->first();
            if ($student === null) {
                return response()->json(['success' => false, 'message' => 'Student not found'], 404);
            }

            DB::statement(
                "CREATE TABLE IF NOT EXISTS student_study_plan_overrides (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id VARCHAR(32) NOT NULL,
                    course_code VARCHAR(64) NOT NULL,
                    target_year VARCHAR(20) NOT NULL,
                    target_semester VARCHAR(20) NOT NULL,
                    updated_by VARCHAR(120) DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_student_course (student_id, course_code),
                    KEY idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            if (!Schema::hasColumn('student_study_plan_overrides', 'updated_by')) {
                DB::statement("ALTER TABLE student_study_plan_overrides ADD COLUMN updated_by VARCHAR(120) DEFAULT NULL");
            }
            if (!Schema::hasColumn('student_study_plan_overrides', 'updated_at')) {
                DB::statement("ALTER TABLE student_study_plan_overrides ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }

            $updatedBy = $this->getActorName($request);

            DB::table('student_study_plan_overrides')->updateOrInsert(
                ['student_id' => $studentId, 'course_code' => $courseCode],
                [
                    'target_year' => $targetYear,
                    'target_semester' => $targetSemester,
                    'updated_by' => $updatedBy,
                    'updated_at' => now(),
                ]
            );

            return response()->json(['success' => true, 'message' => 'Course moved successfully']);
        } catch (Throwable $e) {
            logger()->error('Failed to save study plan override', [
                'student_id' => $studentId ?? null,
                'course_code' => $courseCode ?? null,
                'target_year' => $targetYear ?? null,
                'target_semester' => $targetSemester ?? null,
                'exception' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to save override'], 500);
        }
    }

    private function isAuthorizedCoordinator(Request $request): bool
    {
        if (filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return (bool) (
            $request->session()->has('username')
            || $request->session()->has('admin_username')
            || $request->session()->has('admin_id')
        );
    }

    private function isValidProgram(string $program): bool
    {
        return array_key_exists(trim($program), $this->programCatalog());
    }

    private function canonicalProgramLabel(string $program): string
    {
        $catalog = $this->programCatalog();
        $key = trim($program);
        return $catalog[$key] ?? '';
    }

    private function programCatalog(): array
    {
        $catalog = [
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

        if (Schema::hasTable('programs')) {
            $codeColumn = Schema::hasColumn('programs', 'code');
            $nameColumn = Schema::hasColumn('programs', 'name');

            if ($codeColumn && $nameColumn) {
                $rows = DB::table('programs')->select(['code', 'name'])->get();
                foreach ($rows as $row) {
                    $code = trim((string) ($row->code ?? ''));
                    $name = trim((string) ($row->name ?? ''));
                    if ($code !== '' && $name !== '') {
                        $catalog[$code] = preg_replace('/\s+/', ' ', $name) ?: $name;
                    }
                }
            }
        }

        return $catalog;
    }

    private function ensureCurriculumCoursesTable(): void
    {
        if (Schema::hasTable('curriculum_courses')) {
            return;
        }

        DB::statement(
            "CREATE TABLE IF NOT EXISTS curriculum_courses (
                id INT(11) NOT NULL AUTO_INCREMENT,
                curriculum_year INT(4) NOT NULL,
                program VARCHAR(255) NOT NULL,
                year_level VARCHAR(50) NOT NULL,
                semester VARCHAR(50) NOT NULL,
                course_code VARCHAR(20) NOT NULL,
                course_title VARCHAR(255) NOT NULL,
                credit_units_lec INT(2) DEFAULT 0,
                credit_units_lab INT(2) DEFAULT 0,
                lect_hrs_lec INT(2) DEFAULT 0,
                lect_hrs_lab INT(2) DEFAULT 0,
                pre_requisite VARCHAR(255) DEFAULT 'NONE',
                PRIMARY KEY (id),
                KEY curriculum_year (curriculum_year),
                KEY program (program),
                KEY course_code (course_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function buildSyncedCurriculumRows(string $program, string $curriculumYear, string $canonicalProgramLabel, array $incomingRows, array $deletedCourses): array
    {
        $existingRows = DB::table('curriculum_courses')
            ->select([
                'course_code',
                'course_title',
                'year_level',
                'semester',
                'credit_units_lec',
                'credit_units_lab',
                'lect_hrs_lec',
                'lect_hrs_lab',
                'pre_requisite',
            ])
            ->where('curriculum_year', (int) $curriculumYear)
            ->whereRaw('UPPER(TRIM(program)) = ?', [strtoupper($canonicalProgramLabel)])
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => $this->normalizeCurriculumSyncRow((array) $row))
            ->all();

        $legacyRows = [];
        if (Schema::hasTable('cvsucarmona_courses')) {
            $legacyRows = DB::table('cvsucarmona_courses')
                ->selectRaw("
                    TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) AS course_code,
                    course_title,
                    year_level,
                    semester,
                    credit_units_lec,
                    credit_units_lab,
                    lect_hrs_lec,
                    lect_hrs_lab,
                    pre_requisite
                ")
                ->where('curriculumyear_coursecode', 'like', $curriculumYear . '_%')
                ->whereRaw("FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0", [$program])
                ->orderBy('curriculumyear_coursecode')
                ->get()
                ->map(fn ($row): array => $this->normalizeCurriculumSyncRow((array) $row))
                ->all();
        }

        $baselineRows = count($existingRows) >= count($legacyRows) ? $existingRows : $legacyRows;
        $rowsByCode = [];
        foreach ($baselineRows as $row) {
            $code = $this->curriculumSyncCodeKey((string) ($row['course_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $rowsByCode[$code] = $row;
        }

        foreach ($deletedCourses as $deletedCodeRaw) {
            $deletedCode = $this->extractCurriculumCourseCode((string) $deletedCodeRaw);
            $deletedKey = $this->curriculumSyncCodeKey($deletedCode);
            if ($deletedKey !== '') {
                unset($rowsByCode[$deletedKey]);
            }
        }

        foreach ($incomingRows as $row) {
            $normalizedRow = $this->normalizeCurriculumSyncRow($row);
            $courseCode = (string) ($normalizedRow['course_code'] ?? '');
            $courseKey = $this->curriculumSyncCodeKey($courseCode);
            if ($courseKey === '') {
                continue;
            }

            $originalCourseCode = trim((string) ($row['original_course_code'] ?? ''));
            if ($originalCourseCode === '') {
                $originalCourseCode = $this->extractCurriculumCourseCode((string) ($row['original_curriculum_key'] ?? ''));
            }
            $originalKey = $this->curriculumSyncCodeKey($originalCourseCode);
            if ($originalKey !== '' && $originalKey !== $courseKey) {
                unset($rowsByCode[$originalKey]);
            }

            $rowsByCode[$courseKey] = $normalizedRow;
        }

        $rowsToInsert = [];
        foreach (array_values($rowsByCode) as $row) {
            $rowsToInsert[] = [
                'curriculum_year' => (int) $curriculumYear,
                'program' => $canonicalProgramLabel,
                'year_level' => $row['year_level'],
                'semester' => $row['semester'],
                'course_code' => $row['course_code'],
                'course_title' => $row['course_title'],
                'credit_units_lec' => $row['credit_units_lec'],
                'credit_units_lab' => $row['credit_units_lab'],
                'lect_hrs_lec' => $row['lect_hrs_lec'],
                'lect_hrs_lab' => $row['lect_hrs_lab'],
                'pre_requisite' => $row['pre_requisite'],
            ];
        }

        return $rowsToInsert;
    }

    private function normalizeCurriculumSyncRow(array $row): array
    {
        return [
            'course_code' => $this->normalizeCourseCode((string) ($row['course_code'] ?? '')),
            'course_title' => trim((string) ($row['course_title'] ?? '')),
            'year_level' => trim((string) ($row['year_level'] ?? '')),
            'semester' => trim((string) ($row['semester'] ?? '')),
            'credit_units_lec' => (int) ($row['credit_units_lec'] ?? 0),
            'credit_units_lab' => (int) ($row['credit_units_lab'] ?? 0),
            'lect_hrs_lec' => (int) ($row['lect_hrs_lec'] ?? 0),
            'lect_hrs_lab' => (int) ($row['lect_hrs_lab'] ?? 0),
            'pre_requisite' => trim((string) ($row['pre_requisite'] ?? 'NONE')) ?: 'NONE',
        ];
    }

    private function extractCurriculumCourseCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}_(.+)$/', $value, $matches)) {
            return $this->normalizeCourseCode((string) ($matches[1] ?? ''));
        }

        return $this->normalizeCourseCode($value);
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

    private function curriculumSyncCodeKey(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function getActorName(Request $request): string
    {
        if ($request->session()->has('admin_username')) {
            return (string) $request->session()->get('admin_username', 'admin');
        }

        return (string) $request->session()->get('username', 'program_coordinator');
    }

    private function safeRollback(): void
    {
        try {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            // Ignore rollback errors; preserve original failure message.
        }
    }
}
