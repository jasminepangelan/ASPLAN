<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PreEnrollmentController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', $request->query('student_id', $request->session()->get('student_id', ''))));
            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No student ID provided',
                ], 422);
            }

            $year = trim((string) $request->input('year', '1st Yr'));
            $semester = trim((string) $request->input('semester', '1st Sem'));

            $studentRow = DB::table('student_info')
                ->select([
                    'student_number as student_id',
                    'last_name',
                    'first_name',
                    'middle_name',
                    'contact_number as contact_no',
                    'birthdate',
                    'picture',
                    'program',
                    'curriculum_year',
                    DB::raw("CONCAT_WS(', ', house_number_street, brgy, town, province) as address"),
                ])
                ->where('student_number', $studentId)
                ->first();

            if ($studentRow === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student profile not found',
                ], 404);
            }

            $student = (array) $studentRow;
            $student['age'] = $this->calculateAge((string) ($student['birthdate'] ?? ''));
            $programLabel = trim((string) ($student['program'] ?? ''));
            $programCode = $this->normalizeProgramCode($programLabel);
            $programLabels = $this->resolveCurriculumProgramLabels($programLabel, $programCode);
            $programTokens = $this->resolveProgramTokens($programLabel);
            $curriculumYear = $this->resolveCurriculumYear($programCode, (string) ($student['curriculum_year'] ?? ''));

            $allSubjects = $this->loadAllSubjects($programLabels, $programTokens, $curriculumYear);
            [$nextYear, $nextSemester] = $this->getNextSemester($year, $semester);
            $preenrollCourses = $this->loadPreenrollCourses($programLabels, $programTokens, $curriculumYear, $nextYear, $nextSemester);
            $failedSubjects = $this->loadFailedSubjects($studentId, $programLabels, $programTokens, $curriculumYear);
            [$yearLevel, $latestYear, $latestSemester, $retention] = $this->calculateRetentionData($failedSubjects, $studentId);

            return response()->json([
                'success' => true,
                'student' => $student,
                'all_subjects' => $allSubjects,
                'preenroll_courses' => $preenrollCourses,
                'year_level' => $yearLevel,
                'next_year' => $nextYear,
                'next_semester' => $nextSemester,
                'failed_subjects' => $failedSubjects,
                'latest_year' => $latestYear,
                'latest_semester' => $latestSemester,
                'retention_status' => $retention['retention_status'],
                'retention_color' => $retention['retention_color'],
                'failed_percentage' => $retention['failed_percentage'],
                'max_units' => $retention['max_units'],
                'show_retention_status' => $retention['show_retention_status'],
                'total_subjects_in_semester' => $retention['total_subjects_in_semester'],
                'failed_subjects_in_semester' => $retention['failed_subjects_in_semester'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load pre-enrollment bootstrap',
            ], 500);
        }
    }

    public function transactionHistory(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', $request->query('student_id', $request->session()->get('student_id', ''))));
            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No student ID provided',
                ], 422);
            }

            $rows = DB::table('pre_enrollments as pe')
                ->join('pre_enrollment_courses as pec', 'pe.id', '=', 'pec.pre_enrollment_id')
                ->select([
                    'pe.id',
                    'pe.created_at',
                    'pec.course_codes',
                    'pec.course_titles',
                    'pec.units',
                ])
                ->where('pe.student_id', $studentId)
                ->orderByDesc('pe.created_at')
                ->get();

            $transactions = [];
            foreach ($rows as $row) {
                $createdAt = (string) ($row->created_at ?? '');
                $transactions[] = [
                    'id' => $row->id,
                    'created_at' => $createdAt !== '' ? date('M d, Y h:i A', strtotime($createdAt)) : '',
                    'course_codes' => (string) ($row->course_codes ?? ''),
                    'course_titles' => (string) ($row->course_titles ?? ''),
                    'units' => (string) ($row->units ?? ''),
                    'total_units' => $this->sumUnits((string) ($row->units ?? '')),
                ];
            }

            return response()->json([
                'success' => true,
                'transactions' => $transactions,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load transaction history',
            ], 500);
        }
    }

    public function enrollmentDetails(Request $request): JsonResponse
    {
        try {
            $enrollmentId = trim((string) $request->input('enrollment_id', $request->query('enrollment_id', '')));
            if ($enrollmentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No enrollment ID provided',
                ], 422);
            }

            $enrollment = DB::table('pre_enrollments')->where('id', $enrollmentId)->first();
            if ($enrollment === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Enrollment not found',
                ], 404);
            }

            $coursesRow = DB::table('pre_enrollment_courses')
                ->select(['course_codes', 'course_titles', 'units'])
                ->where('pre_enrollment_id', $enrollmentId)
                ->first();

            $courses = $this->buildCourses($coursesRow);
            $data = (array) $enrollment;
            $data['courses'] = $courses;
            $data['formatted_date'] = isset($data['created_at']) && $data['created_at'] !== ''
                ? date('Y-m-d H:i:s', strtotime((string) $data['created_at']))
                : '';

            return response()->json([
                'success' => true,
                'enrollment' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load enrollment details',
            ], 500);
        }
    }

    public function loadPreEnrollment(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', $request->query('student_id', $request->session()->get('student_id', ''))));
            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No student ID provided',
                ], 422);
            }

            $enrollment = DB::table('pre_enrollments as pe')
                ->leftJoin('pre_enrollment_courses as pec', 'pe.id', '=', 'pec.pre_enrollment_id')
                ->select([
                    'pe.*',
                    'pec.course_codes',
                    'pec.course_titles',
                    'pec.units',
                ])
                ->where('pe.student_id', $studentId)
                ->orderByDesc('pe.created_at')
                ->first();

            if ($enrollment === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No pre-enrollment found',
                ], 404);
            }

            $data = (array) $enrollment;
            $data['courses'] = $this->buildCourses($enrollment);
            $data['formatted_date'] = isset($data['created_at']) && $data['created_at'] !== ''
                ? date('Y-m-d H:i:s', strtotime((string) $data['created_at']))
                : '';

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load pre-enrollment',
            ], 500);
        }
    }

    public function save(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $studentId = trim((string) ($payload['student_id'] ?? $request->query('student_id', $request->session()->get('student_id', ''))));

            if ($studentId === '') {
                return response()->json(['success' => false, 'message' => 'No student ID provided'], 422);
            }

            if ($payload === [] && $request->getContent() !== '') {
                $decoded = json_decode($request->getContent(), true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $payload['student_id'] = $studentId;

            if (!isset($payload['courses']) || !is_array($payload['courses']) || empty($payload['courses'])) {
                return response()->json(['success' => false, 'message' => 'No courses selected'], 422);
            }

            foreach (['name', 'year_level', 'course', 'classification', 'registration_status', 'scholarship_awarded', 'mode_of_payment'] as $field) {
                if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                    return response()->json(['success' => false, 'message' => 'Missing required field: ' . $field], 422);
                }
            }

            $sectionMajor = trim((string) ($payload['section_major'] ?? ''));
            if ($sectionMajor === '') {
                $sectionMajor = 'N/A';
            }

            $courseCodes = [];
            $courseTitles = [];
            $courseUnits = [];
            foreach ($payload['courses'] as $course) {
                $courseCodes[] = (string) ($course['course_code'] ?? '');
                $courseTitles[] = (string) ($course['course_title'] ?? '');
                $courseUnits[] = (string) ($course['units'] ?? '');
            }

            $courseCodesStr = implode(', ', $courseCodes);
            $courseTitlesStr = implode(', ', $courseTitles);
            $courseUnitsStr = implode(', ', $courseUnits);

            $duplicate = DB::table('pre_enrollments as pe')
                ->join('pre_enrollment_courses as pec', 'pe.id', '=', 'pec.pre_enrollment_id')
                ->where('pe.student_id', $studentId)
                ->where('pe.year_level', (string) $payload['year_level'])
                ->where('pe.course', (string) $payload['course'])
                ->where('pe.section_major', $sectionMajor)
                ->where('pe.classification', (string) $payload['classification'])
                ->where('pe.registration_status', (string) $payload['registration_status'])
                ->where('pe.scholarship_awarded', (string) $payload['scholarship_awarded'])
                ->where('pe.mode_of_payment', (string) $payload['mode_of_payment'])
                ->where('pec.course_codes', $courseCodesStr)
                ->where('pec.course_titles', $courseTitlesStr)
                ->where('pec.units', $courseUnitsStr)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate pre-enrollment found. Submission not saved.',
                ]);
            }

            $timestamp = date('YmdHis');
            $preEnrollmentId = 'PE' . $timestamp;
            $courseId = 'PC' . $timestamp . '001';

            DB::transaction(function () use ($payload, $studentId, $sectionMajor, $preEnrollmentId, $courseId, $courseCodesStr, $courseTitlesStr, $courseUnitsStr): void {
                DB::table('pre_enrollments')->insert([
                    'id' => $preEnrollmentId,
                    'student_id' => $studentId,
                    'name' => (string) $payload['name'],
                    'year_level' => (string) $payload['year_level'],
                    'course' => (string) $payload['course'],
                    'section_major' => $sectionMajor,
                    'classification' => (string) $payload['classification'],
                    'registration_status' => (string) $payload['registration_status'],
                    'scholarship_awarded' => (string) $payload['scholarship_awarded'],
                    'mode_of_payment' => (string) $payload['mode_of_payment'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('pre_enrollment_courses')->insert([
                    'id' => $courseId,
                    'pre_enrollment_id' => $preEnrollmentId,
                    'course_codes' => $courseCodesStr,
                    'course_titles' => $courseTitlesStr,
                    'units' => $courseUnitsStr,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Pre-enrollment saved successfully',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error saving pre-enrollment: ' . $e->getMessage(),
                'details' => $e->getTraceAsString(),
            ], 500);
        }
    }

    private function buildCourses(mixed $row): array
    {
        if ($row === null) {
            return [];
        }

        $courseCodes = array_map('trim', explode(', ', (string) ($row->course_codes ?? $row['course_codes'] ?? '')));
        $courseTitles = array_map('trim', explode(', ', (string) ($row->course_titles ?? $row['course_titles'] ?? '')));
        $units = array_map('trim', explode(', ', (string) ($row->units ?? $row['units'] ?? '')));

        $courses = [];
        $count = max(count($courseCodes), count($courseTitles), count($units));
        for ($i = 0; $i < $count; $i++) {
            $courses[] = [
                'course_code' => $courseCodes[$i] ?? '',
                'course_title' => $courseTitles[$i] ?? '',
                'units' => $units[$i] ?? '',
                'day' => '',
            ];
        }

        return $courses;
    }

    private function loadAllSubjects(array $programLabels, array $programTokens, string $curriculumYear): array
    {
        if (empty($programLabels) && empty($programTokens)) {
            return [];
        }

        $rows = collect();
        if (Schema::hasTable('curriculum_courses') && !empty($programLabels)) {
            $query = DB::table('curriculum_courses')
                ->select([
                    DB::raw("CASE year_level WHEN 'First Year' THEN '1st Yr' WHEN 'Second Year' THEN '2nd Yr' WHEN 'Third Year' THEN '3rd Yr' WHEN 'Fourth Year' THEN '4th Yr' ELSE year_level END as year"),
                    DB::raw("CASE semester WHEN 'First Semester' THEN '1st Sem' WHEN 'Second Semester' THEN '2nd Sem' WHEN 'Mid Year' THEN 'Mid Year' WHEN 'Midyear' THEN 'Mid Year' WHEN 'Summer' THEN 'Mid Year' ELSE semester END as semester"),
                    DB::raw('TRIM(course_code) as course_code'),
                    'course_title',
                    DB::raw('credit_units_lec + credit_units_lab as total_units'),
                    'pre_requisite',
                ]);
            $this->applyCurriculumProgramFilter($query, 'program', $programLabels);
            if ($curriculumYear !== '') {
                $query->where('curriculum_year', (int) $curriculumYear);
            }
            $rows = $query
                ->orderByRaw("FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year')")
                ->orderByRaw("FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer')")
                ->get();
        }

        if ($rows->isEmpty()) {
            $query = DB::table('cvsucarmona_courses')
                ->select([
                    DB::raw("CASE year_level WHEN 'First Year' THEN '1st Yr' WHEN 'Second Year' THEN '2nd Yr' WHEN 'Third Year' THEN '3rd Yr' WHEN 'Fourth Year' THEN '4th Yr' ELSE year_level END as year"),
                    DB::raw("CASE semester WHEN 'First Semester' THEN '1st Sem' WHEN 'Second Semester' THEN '2nd Sem' WHEN 'Mid Year' THEN 'Mid Year' WHEN 'Midyear' THEN 'Mid Year' ELSE semester END as semester"),
                    DB::raw("TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', -1)) as course_code"),
                    'course_title',
                    DB::raw('credit_units_lec + credit_units_lab as total_units'),
                    'pre_requisite',
                ])
                ->orderByRaw("FIELD(year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year')")
                ->orderByRaw("FIELD(semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear')");

            $this->applyLegacyProgramFilter($query, 'programs', $programTokens);
            if ($curriculumYear !== '') {
                $query->whereRaw("TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1)) = ?", [$curriculumYear]);
            }

            $rows = $query->get();
        }

        $allSubjects = [];
        foreach ($rows as $row) {
            $year = (string) ($row->year ?? '');
            $semester = (string) ($row->semester ?? '');
            if ($year === '' || $semester === '') {
                continue;
            }

            if (!isset($allSubjects[$year])) {
                $allSubjects[$year] = [];
            }
            if (!isset($allSubjects[$year][$semester])) {
                $allSubjects[$year][$semester] = [];
            }

            $entry = (array) $row;
            $entry['course_code'] = $this->normalizeCourseCode((string) ($entry['course_code'] ?? ''));
            $allSubjects[$year][$semester][] = $entry;
        }

        return $allSubjects;
    }

    private function loadPreenrollCourses(array $programLabels, array $programTokens, string $curriculumYear, string $year, string $semester): array
    {
        if (empty($programLabels) && empty($programTokens)) {
            return [];
        }

        $rows = collect();
        if (Schema::hasTable('curriculum_courses') && !empty($programLabels)) {
            $query = DB::table('curriculum_courses')
                ->select([
                    DB::raw('TRIM(course_code) as course_code'),
                    'course_title',
                    DB::raw('credit_units_lec as credit_unit_lec'),
                    DB::raw('credit_units_lab as credit_unit_lab'),
                ])
                ->whereRaw("CASE year_level WHEN 'First Year' THEN '1st Yr' WHEN 'Second Year' THEN '2nd Yr' WHEN 'Third Year' THEN '3rd Yr' WHEN 'Fourth Year' THEN '4th Yr' END = ?", [$year])
                ->whereRaw("CASE semester WHEN 'First Semester' THEN '1st Sem' WHEN 'Second Semester' THEN '2nd Sem' WHEN 'Mid Year' THEN 'Mid Year' WHEN 'Midyear' THEN 'Mid Year' WHEN 'Summer' THEN 'Mid Year' END = ?", [$semester]);
            $this->applyCurriculumProgramFilter($query, 'program', $programLabels);
            if ($curriculumYear !== '') {
                $query->where('curriculum_year', (int) $curriculumYear);
            }
            $rows = $query->orderBy('course_code')->get();
        }

        if ($rows->isEmpty()) {
            $query = DB::table('cvsucarmona_courses as cb')
                ->select([
                    DB::raw("TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', -1)) as course_code"),
                    'cb.course_title',
                    DB::raw('cb.credit_units_lec as credit_unit_lec'),
                    DB::raw('cb.credit_units_lab as credit_unit_lab'),
                ])
                ->whereRaw("CASE cb.year_level WHEN 'First Year' THEN '1st Yr' WHEN 'Second Year' THEN '2nd Yr' WHEN 'Third Year' THEN '3rd Yr' WHEN 'Fourth Year' THEN '4th Yr' END = ?", [$year])
                ->whereRaw("CASE cb.semester WHEN 'First Semester' THEN '1st Sem' WHEN 'Second Semester' THEN '2nd Sem' WHEN 'Mid Year' THEN 'Mid Year' WHEN 'Midyear' THEN 'Mid Year' END = ?", [$semester])
                ->orderBy('cb.curriculumyear_coursecode');

            $this->applyLegacyProgramFilter($query, 'cb.programs', $programTokens);
            if ($curriculumYear !== '') {
                $query->whereRaw("TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', 1)) = ?", [$curriculumYear]);
            }

            $rows = $query->get();
        }

        return array_map(function ($row): array {
            $entry = (array) $row;
            $entry['course_code'] = $this->normalizeCourseCode((string) ($entry['course_code'] ?? ''));
            return $entry;
        }, $rows->all());
    }

    private function loadFailedSubjects(string $studentId, array $programLabels, array $programTokens, string $curriculumYear): array
    {
        if (empty($programLabels) && empty($programTokens)) {
            return [];
        }

        $rows = collect();
        if (Schema::hasTable('curriculum_courses') && !empty($programLabels)) {
            $query = DB::table('curriculum_courses as cb')
                ->leftJoin('student_checklists as sc', function ($join) use ($studentId): void {
                    $join->on(DB::raw('TRIM(cb.course_code)'), '=', 'sc.course_code')
                        ->where('sc.student_id', $studentId);
                })
                ->select([
                    DB::raw("CASE cb.year_level WHEN 'First Year' THEN '1st Yr' WHEN 'Second Year' THEN '2nd Yr' WHEN 'Third Year' THEN '3rd Yr' WHEN 'Fourth Year' THEN '4th Yr' END as year"),
                    DB::raw("CASE cb.semester WHEN 'First Semester' THEN '1st Sem' WHEN 'Second Semester' THEN '2nd Sem' WHEN 'Mid Year' THEN 'Mid Year' WHEN 'Midyear' THEN 'Mid Year' WHEN 'Summer' THEN 'Mid Year' END as semester"),
                    DB::raw('TRIM(cb.course_code) as course_code'),
                    'cb.course_title',
                    'cb.pre_requisite',
                    DB::raw("COALESCE(sc.final_grade, 'No Grade') as final_grade"),
                    DB::raw("COALESCE(sc.evaluator_remarks, '') as evaluator_remarks"),
                    DB::raw('cb.credit_units_lec + cb.credit_units_lab as total_units'),
                ])
                ->orderByRaw("FIELD(cb.year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year')")
                ->orderByRaw("FIELD(cb.semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear', 'Summer')")
                ->orderBy('cb.id');
            $this->applyCurriculumProgramFilter($query, 'cb.program', $programLabels);
            if ($curriculumYear !== '') {
                $query->where('cb.curriculum_year', (int) $curriculumYear);
            }
            $rows = $query->get();
        }

        if ($rows->isEmpty()) {
            $query = DB::table('cvsucarmona_courses as cb')
                ->leftJoin('student_checklists as sc', function ($join) use ($studentId): void {
                    $join->on(DB::raw("TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', -1))"), '=', 'sc.course_code')
                        ->where('sc.student_id', $studentId);
                })
                ->select([
                    DB::raw("CASE cb.year_level WHEN 'First Year' THEN '1st Yr' WHEN 'Second Year' THEN '2nd Yr' WHEN 'Third Year' THEN '3rd Yr' WHEN 'Fourth Year' THEN '4th Yr' END as year"),
                    DB::raw("CASE cb.semester WHEN 'First Semester' THEN '1st Sem' WHEN 'Second Semester' THEN '2nd Sem' WHEN 'Mid Year' THEN 'Mid Year' WHEN 'Midyear' THEN 'Mid Year' END as semester"),
                    DB::raw("TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', -1)) as course_code"),
                    'cb.course_title',
                    'cb.pre_requisite',
                    DB::raw("COALESCE(sc.final_grade, 'No Grade') as final_grade"),
                    DB::raw("COALESCE(sc.evaluator_remarks, '') as evaluator_remarks"),
                    DB::raw('cb.credit_units_lec + cb.credit_units_lab as total_units'),
                ])
                ->orderByRaw("FIELD(cb.year_level, 'First Year', 'Second Year', 'Third Year', 'Fourth Year')")
                ->orderByRaw("FIELD(cb.semester, 'First Semester', 'Second Semester', 'Mid Year', 'Midyear')")
                ->orderBy('cb.curriculumyear_coursecode');

            $this->applyLegacyProgramFilter($query, 'cb.programs', $programTokens);
            if ($curriculumYear !== '') {
                $query->whereRaw("TRIM(SUBSTRING_INDEX(cb.curriculumyear_coursecode, '_', 1)) = ?", [$curriculumYear]);
            }

            $rows = $query->get();
        }

        $failedSubjects = [];
        foreach ($rows as $row) {
            $year = (string) ($row->year ?? '');
            $semester = (string) ($row->semester ?? '');
            if ($year === '' || $semester === '') {
                continue;
            }

            if (!isset($failedSubjects[$year])) {
                $failedSubjects[$year] = [];
            }
            if (!isset($failedSubjects[$year][$semester])) {
                $failedSubjects[$year][$semester] = [];
            }

            $entry = (array) $row;
            $entry['course_code'] = $this->normalizeCourseCode((string) ($entry['course_code'] ?? ''));
            $failedSubjects[$year][$semester][] = $entry;
        }

        return $failedSubjects;
    }

    private function applyLegacyProgramFilter($query, string $column, array $programTokens): void
    {
        $query->where(function ($inner) use ($column, $programTokens): void {
            foreach ($programTokens as $token) {
                $inner->orWhereRaw("FIND_IN_SET(?, REPLACE(UPPER({$column}), ', ', ',')) > 0", [$token]);
            }
        });
    }

    private function applyCurriculumProgramFilter($query, string $column, array $programLabels): void
    {
        $query->where(function ($inner) use ($column, $programLabels): void {
            foreach ($programLabels as $label) {
                $inner->orWhereRaw("UPPER(TRIM({$column})) = ?", [strtoupper(trim($label))]);
            }
        });
    }

    private function normalizeProgramCode(string $program): string
    {
        $value = strtoupper(trim($program));
        if ($value === '') {
            return '';
        }

        if (strpos($value, 'COMPUTER SCIENCE') !== false || $value === 'BSCS') {
            return 'BSCS';
        }
        if (strpos($value, 'INFORMATION TECHNOLOGY') !== false || $value === 'BSIT') {
            return 'BSIT';
        }
        if (strpos($value, 'COMPUTER ENGINEERING') !== false || $value === 'BSCPE') {
            return 'BSCPE';
        }
        if (strpos($value, 'INDUSTRIAL TECHNOLOGY') !== false || $value === 'BSINDT') {
            return 'BSINDT';
        }
        if (strpos($value, 'HOSPITALITY MANAGEMENT') !== false || $value === 'BSHM') {
            return 'BSHM';
        }
        if (strpos($value, 'HUMAN RESOURCE') !== false) {
            return 'BSBA-HRM';
        }
        if (strpos($value, 'MARKETING') !== false) {
            return 'BSBA-MM';
        }
        if (strpos($value, 'ENGLISH') !== false) {
            return 'BSED-ENGLISH';
        }
        if (strpos($value, 'SCIENCE') !== false) {
            return 'BSED-SCIENCE';
        }
        if (strpos($value, 'MATH') !== false || strpos($value, 'MATHEMATICS') !== false) {
            return 'BSED-MATH';
        }

        return $value;
    }

    private function resolveProgramTokens(string $programLabel): array
    {
        $programCode = $this->normalizeProgramCode($programLabel);
        $tokens = [];

        $map = [
            'BSCS' => ['BSCS'],
            'BSIT' => ['BSIT'],
            'BSCPE' => ['BSCPE', 'BSCPE '],
            'BSINDT' => ['BSINDT'],
            'BSHM' => ['BSHM'],
            'BSBA-HRM' => ['BSBA-HRM'],
            'BSBA-MM' => ['BSBA-MM'],
            'BSED-ENGLISH' => ['BSED-ENGLISH'],
            'BSED-SCIENCE' => ['BSED-SCIENCE'],
            'BSED-MATH' => ['BSED-MATH'],
        ];

        foreach ($map[$programCode] ?? [] as $token) {
            $tokens[strtoupper(trim($token))] = true;
        }

        return array_keys($tokens);
    }

    private function resolveCurriculumProgramLabels(string $programLabel, string $programCode): array
    {
        $labels = [];
        foreach ([$programLabel, $this->canonicalProgramLabel($programCode)] as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            $labels[strtoupper($label)] = $label;
        }

        return array_values($labels);
    }

    private function canonicalProgramLabel(string $programCode): string
    {
        return match (strtoupper(trim($programCode))) {
            'BSCS' => 'Bachelor of Science in Computer Science',
            'BSIT' => 'Bachelor of Science in Information Technology',
            'BSCPE' => 'Bachelor of Science in Computer Engineering',
            'BSINDT' => 'Bachelor of Science in Industrial Technology',
            'BSHM' => 'Bachelor of Science in Hospitality Management',
            'BSBA-HRM' => 'Bachelor of Science in Business Administration Major in Human Resource Management',
            'BSBA-MM' => 'Bachelor of Science in Business Administration Major in Marketing Management',
            'BSED-ENGLISH' => 'Bachelor of Secondary Education Major in English',
            'BSED-SCIENCE' => 'Bachelor of Secondary Education Major in Science',
            'BSED-MATH' => 'Bachelor of Secondary Education Major in Mathematics',
            default => '',
        };
    }

    private function resolveCurriculumYear(string $programCode, string $storedYear): string
    {
        $storedYear = $this->normalizeCurriculumYear($storedYear);
        if ($programCode === '') {
            return '';
        }

        if ($storedYear !== '') {
            $tokens = $this->resolveProgramTokens($programCode);
            if (!empty($tokens)) {
                $query = DB::table('cvsucarmona_courses')
                    ->whereRaw("TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1)) = ?", [$storedYear]);
                $this->applyLegacyProgramFilter($query, 'programs', $tokens);
                if ($query->exists()) {
                    return $storedYear;
                }
            }
        }

        $latest = '';
        if (Schema::hasTable('program_curriculum_years')) {
            $latest = (string) (DB::table('program_curriculum_years')
                ->where('program', $programCode)
                ->max('curriculum_year') ?? '');
            $latest = $this->normalizeCurriculumYear($latest);
        }

        if ($latest !== '') {
            return $latest;
        }

        $tokens = $this->resolveProgramTokens($programCode);
        if (empty($tokens)) {
            return '';
        }

        $query = DB::table('cvsucarmona_courses')->selectRaw("MAX(TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1))) AS latest_year");
        $this->applyLegacyProgramFilter($query, 'programs', $tokens);
        $row = $query->first();

        return $this->normalizeCurriculumYear((string) ($row->latest_year ?? ''));
    }

    private function normalizeCurriculumYear(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{2})V\d+$/', $value, $matches)) {
            return '20' . $matches[1];
        }

        return preg_match('/^\d{4}$/', $value) ? $value : '';
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

    private function getNextSemester(string $currentYear, string $currentSemester): array
    {
        $years = ['1st Yr', '2nd Yr', '3rd Yr', '4th Yr'];
        $semesters = ['1st Sem', '2nd Sem'];

        $yearIndex = array_search($currentYear, $years, true);
        $semIndex = array_search($currentSemester, $semesters, true);

        if ($semIndex === 0) {
            return [$currentYear, $semesters[1]];
        }

        if ($yearIndex !== false && $yearIndex < count($years) - 1) {
            return [$years[$yearIndex + 1], $semesters[0]];
        }

        return [$currentYear, $currentSemester];
    }

    private function calculateRetentionData(array $failedSubjects, string $studentId): array
    {
        $yearMap = [
            '1st Yr' => 1,
            '2nd Yr' => 2,
            '3rd Yr' => 3,
            '4th Yr' => 4,
        ];

        $highestYear = '1st Yr';
        foreach ($failedSubjects as $year => $semesters) {
            foreach ($semesters as $sem => $subjects) {
                foreach ($subjects as $subject) {
                    $grade = (string) ($subject['final_grade'] ?? '');
                    $remarks = (string) ($subject['evaluator_remarks'] ?? '');
                    if ($grade === 'No Grade' || $grade === '' || $grade === null || $remarks === 'Pending' || $remarks === '') {
                        continue;
                    }

                    if (!isset($yearMap[$year]) || !isset($yearMap[$highestYear])) {
                        continue;
                    }

                    if ($yearMap[$year] > $yearMap[$highestYear]) {
                        $highestYear = $year;
                    }
                }
            }
        }

        $yearLevel = DB::table('pre_enrollments')
            ->where('student_id', $studentId)
            ->orderByDesc('created_at')
            ->value('year_level');

        if (!is_string($yearLevel) || trim($yearLevel) === '') {
            $yearLevel = $highestYear;
        }

        $latestYear = '';
        $latestSemester = '';
        $yearOrder = ['1st Yr' => 1, '2nd Yr' => 2, '3rd Yr' => 3, '4th Yr' => 4];
        $semOrder = ['1st Sem' => 1, '2nd Sem' => 2, 'Mid Year' => 3];
        foreach ($failedSubjects as $yr => $semesters) {
            foreach ($semesters as $sem => $subjects) {
                $hasApprovedGrades = false;
                foreach ($subjects as $subject) {
                    $grade = (string) ($subject['final_grade'] ?? '');
                    $remarks = (string) ($subject['evaluator_remarks'] ?? '');
                    if ($grade !== 'No Grade' && $grade !== '' && $grade !== null && $remarks !== 'Pending' && $remarks !== '') {
                        $hasApprovedGrades = true;
                        break;
                    }
                }

                if (!$hasApprovedGrades) {
                    continue;
                }

                if ($latestYear === ''
                    || ($yearOrder[$yr] ?? 0) > ($yearOrder[$latestYear] ?? 0)
                    || (($yearOrder[$yr] ?? 0) === ($yearOrder[$latestYear] ?? 0) && ($semOrder[$sem] ?? 0) > ($semOrder[$latestSemester] ?? 0))) {
                    $latestYear = $yr;
                    $latestSemester = $sem;
                }
            }
        }

        $retentionStatus = 'None';
        $retentionColor = '#28a745';
        $failedPercentage = 0.0;
        $maxUnits = 0;
        $showRetentionStatus = false;
        $totalSubjectsInSemester = 0;
        $failedSubjectsInSemester = 0;

        if ($latestYear !== '' && $latestSemester !== '') {
            $subjectsInLatest = $failedSubjects[$latestYear][$latestSemester] ?? [];
            $totalSubjectsInSemester = count($subjectsInLatest);

            foreach ($subjectsInLatest as $subject) {
                $grade = (string) ($subject['final_grade'] ?? '');
                $remarks = (string) ($subject['evaluator_remarks'] ?? '');
                if ($grade === 'No Grade' || $grade === '' || $grade === null) {
                    continue;
                }
                if ($remarks === 'Pending' || $remarks === '') {
                    continue;
                }

                $isFailed = false;
                if (in_array($grade, ['INC', 'DRP', 'W'], true)) {
                    $isFailed = true;
                } else {
                    $numericGrade = (float) $grade;
                    if ($numericGrade === 0.0 || $numericGrade > 3.0) {
                        $isFailed = true;
                    }
                }

                if ($isFailed) {
                    $failedSubjectsInSemester++;
                }
            }

            $subjectsWithApprovedGrades = 0;
            foreach ($subjectsInLatest as $subject) {
                $grade = (string) ($subject['final_grade'] ?? '');
                $remarks = (string) ($subject['evaluator_remarks'] ?? '');
                if ($grade !== 'No Grade' && $grade !== '' && $grade !== null && $remarks !== 'Pending' && $remarks !== '') {
                    $subjectsWithApprovedGrades++;
                }
            }

            if ($subjectsWithApprovedGrades > 0) {
                $showRetentionStatus = true;
                $failedPercentage = ($failedSubjectsInSemester / $subjectsWithApprovedGrades) * 100;

                if ($failedPercentage >= 75) {
                    $retentionStatus = 'Disqualified';
                    $retentionColor = '#dc3545';
                } elseif ($failedPercentage >= 51) {
                    $retentionStatus = 'Probationary';
                    $retentionColor = '#fd7e14';
                    $maxUnits = 15;
                } elseif ($failedPercentage >= 30) {
                    $retentionStatus = 'Warning';
                    $retentionColor = '#ffc107';
                }
            }
        }

        return [
            $yearLevel,
            $latestYear,
            $latestSemester,
            [
                'retention_status' => $retentionStatus,
                'retention_color' => $retentionColor,
                'failed_percentage' => $failedPercentage,
                'max_units' => $maxUnits,
                'show_retention_status' => $showRetentionStatus,
                'total_subjects_in_semester' => $totalSubjectsInSemester,
                'failed_subjects_in_semester' => $failedSubjectsInSemester,
            ],
        ];
    }

    private function calculateAge(string $birthdate): string
    {
        if ($birthdate === '') {
            return 'N/A';
        }

        try {
            $dob = new \DateTime($birthdate);
            $now = new \DateTime();
            if ($dob <= $now) {
                return (string) $now->diff($dob)->y;
            }
        } catch (Throwable $e) {
            // Ignore invalid dates and fall back to N/A.
        }

        return 'N/A';
    }

    private function sumUnits(string $units): float
    {
        $parts = preg_split('/\s*,\s*/', trim($units), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($parts as $part) {
            $total += (float) $part;
        }

        return $total;
    }
}
