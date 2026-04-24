<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgramCoordinatorCurriculumManagementController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $isAdmin = (bool) $request->boolean('is_admin', false);
            $username = trim((string) $request->input('username', ''));
            $requestedProgram = trim((string) $request->input('program', ''));

            $programs = $this->programNames();
            $coordinatorProgramRaw = '';
            $coordinatorProgramCode = '';
            $programConfigNotice = '';

            if (!$isAdmin && $username !== '') {
                $table = $this->resolveProgramCoordinatorTable();
                if ($table !== null) {
                    if (Schema::hasColumn($table, 'program')) {
                        $coordinatorProgramRaw = trim((string) DB::table($table)->where('username', $username)->value('program'));
                        $coordinatorProgramCode = $this->normalizeProgramCode($coordinatorProgramRaw);
                    } else {
                        $coordinatorProgramRaw = trim((string) DB::table('adviser')->where('username', $username)->value('program'));
                        $coordinatorProgramCode = $this->normalizeProgramCode($coordinatorProgramRaw);
                        if ($coordinatorProgramCode !== '') {
                            $programConfigNotice = 'Program source fallback is active (adviser table).';
                        }
                    }
                }
            }

            if ($isAdmin) {
                if ($requestedProgram !== '' && isset($programs[$requestedProgram])) {
                    $coordinatorProgramCode = $requestedProgram;
                }
                if ($coordinatorProgramCode !== '') {
                    $coordinatorProgramRaw = $programs[$coordinatorProgramCode] ?? $coordinatorProgramCode;
                } else {
                    $coordinatorProgramRaw = '';
                }
            }

            if ($coordinatorProgramCode === '') {
                return response()->json([
                    'success' => true,
                    'coordinator_program_raw' => '',
                    'coordinator_program_code' => '',
                    'program_config_notice' => $isAdmin ? '' : 'Program is not configured for this account.',
                    'existing' => new \stdClass(),
                    'curriculum_catalog' => new \stdClass(),
                ]);
            }

            $existing = $this->loadExistingCurriculumYears($coordinatorProgramCode);
            $curriculumCatalog = $this->loadCurriculumCatalog($coordinatorProgramCode);

            return response()->json([
                'success' => true,
                'coordinator_program_raw' => $coordinatorProgramRaw,
                'coordinator_program_code' => $coordinatorProgramCode,
                'program_config_notice' => $programConfigNotice,
                'existing' => $existing,
                'curriculum_catalog' => $curriculumCatalog,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load curriculum management data.'], 500);
        }
    }

    private function loadExistingCurriculumYears(string $programCode = ''): array
    {
        $existing = [];

        if (Schema::hasTable('cvsucarmona_courses')) {
            $query = DB::table('cvsucarmona_courses')
                ->select(DB::raw('DISTINCT SUBSTRING_INDEX(curriculumyear_coursecode, "_", 1) AS cy'), 'programs')
                ->orderByDesc(DB::raw('cy'));

            $rows = $query->get();

            foreach ($rows as $row) {
                if ($programCode !== '' && !$this->rowContainsProgram((string) ($row->programs ?? ''), $programCode)) {
                    continue;
                }

                $normalizedYear = $this->normalizeCurriculumYear((string) ($row->cy ?? ''));
                if ($normalizedYear === '') {
                    continue;
                }

                if ($programCode !== '') {
                    $this->appendCurriculumYear($existing, $programCode, $normalizedYear);
                    continue;
                }

                $programList = array_map('trim', explode(',', (string) ($row->programs ?? '')));
                foreach ($programList as $program) {
                    $this->appendCurriculumYear($existing, $program, $normalizedYear);
                }
            }
        }

        if (Schema::hasTable('program_curriculum_years')) {
            $rows = DB::table('program_curriculum_years')->select(['program', 'curriculum_year'])->get();
            foreach ($rows as $row) {
                $program = trim((string) ($row->program ?? ''));
                $year = $this->normalizeCurriculumYear((string) ($row->curriculum_year ?? ''));
                if ($program === '' || $year === '') {
                    continue;
                }
                if ($programCode !== '' && $this->normalizeProgramCode($program) !== $programCode) {
                    continue;
                }
                $this->appendCurriculumYear($existing, $program, $year);
            }
        }

        if ($programCode !== '' && Schema::hasTable('curriculum_courses')) {
            $programLabel = trim((string) ($this->programNames()[$programCode] ?? ''));
            if ($programLabel !== '') {
                $rows = DB::table('curriculum_courses')
                    ->select(['curriculum_year'])
                    ->whereRaw('UPPER(TRIM(program)) = ?', [strtoupper($programLabel)])
                    ->distinct()
                    ->orderBy('curriculum_year')
                    ->get();

                foreach ($rows as $row) {
                    $year = $this->normalizeCurriculumYear((string) ($row->curriculum_year ?? ''));
                    if ($year !== '') {
                        $this->appendCurriculumYear($existing, $programCode, $year);
                    }
                }
            }
        }

        foreach ($existing as $program => $years) {
            $years = array_values(array_unique($years));
            sort($years, SORT_NUMERIC);
            $existing[$program] = $years;
        }

        return $existing;
    }

    private function appendCurriculumYear(array &$existing, string $program, string $year): void
    {
        $programCode = $this->normalizeProgramCode($program);
        if ($programCode === '' || $year === '') {
            return;
        }

        $existing[$programCode] ??= [];
        if (!in_array($year, $existing[$programCode], true)) {
            $existing[$programCode][] = $year;
        }
    }

    private function loadCurriculumCatalog(string $programCode): array
    {
        $legacyCatalog = [];
        if (Schema::hasTable('cvsucarmona_courses')) {
            $rows = DB::table('cvsucarmona_courses')
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
                ->orderBy('curriculumyear_coursecode')
                ->get();

            foreach ($rows as $row) {
                if (!$this->rowContainsProgram((string) ($row->programs ?? ''), $programCode)) {
                    continue;
                }

                $key = (string) ($row->curriculumyear_coursecode ?? '');
                $parts = explode('_', $key, 2);
                $yearToken = $parts[0] ?? '';
                $normalizedYear = $this->normalizeCurriculumYear($yearToken);
                if ($normalizedYear === '') {
                    continue;
                }

                $this->appendCurriculumCatalogCourse($legacyCatalog, $normalizedYear, (string) ($row->year_level ?? ''), (string) ($row->semester ?? ''), [
                    'curriculum_key' => $key,
                    'course_code' => $this->normalizeCourseCode((string) ($parts[1] ?? $key)),
                    'course_title' => (string) ($row->course_title ?? ''),
                    'credit_units_lec' => (int) ($row->credit_units_lec ?? 0),
                    'credit_units_lab' => (int) ($row->credit_units_lab ?? 0),
                    'lect_hrs_lec' => (int) ($row->lect_hrs_lec ?? 0),
                    'lect_hrs_lab' => (int) ($row->lect_hrs_lab ?? 0),
                    'pre_requisite' => (string) ($row->pre_requisite ?? 'NONE'),
                ]);
            }
        }

        $curriculumCatalog = $legacyCatalog;

        if (Schema::hasTable('curriculum_courses')) {
            $programLabel = trim((string) ($this->programNames()[$programCode] ?? ''));
            if ($programLabel !== '') {
                $syncedCatalog = [];
                $rows = DB::table('curriculum_courses')
                    ->select([
                        'curriculum_year',
                        'year_level',
                        'semester',
                        'course_code',
                        'course_title',
                        'credit_units_lec',
                        'credit_units_lab',
                        'lect_hrs_lec',
                        'lect_hrs_lab',
                        'pre_requisite',
                    ])
                    ->whereRaw('UPPER(TRIM(program)) = ?', [strtoupper($programLabel)])
                    ->orderBy('curriculum_year')
                    ->orderBy('year_level')
                    ->orderBy('semester')
                    ->orderBy('course_code')
                    ->get();

                foreach ($rows as $row) {
                    $normalizedYear = $this->normalizeCurriculumYear((string) ($row->curriculum_year ?? ''));
                    if ($normalizedYear === '') {
                        continue;
                    }

                    $courseCode = $this->normalizeCourseCode((string) ($row->course_code ?? ''));
                    $this->appendCurriculumCatalogCourse($syncedCatalog, $normalizedYear, trim((string) ($row->year_level ?? '')), trim((string) ($row->semester ?? '')), [
                        'curriculum_key' => $normalizedYear . '_' . $courseCode,
                        'course_code' => $courseCode,
                        'course_title' => (string) ($row->course_title ?? ''),
                        'credit_units_lec' => (int) ($row->credit_units_lec ?? 0),
                        'credit_units_lab' => (int) ($row->credit_units_lab ?? 0),
                        'lect_hrs_lec' => (int) ($row->lect_hrs_lec ?? 0),
                        'lect_hrs_lab' => (int) ($row->lect_hrs_lab ?? 0),
                        'pre_requisite' => (string) ($row->pre_requisite ?? 'NONE'),
                    ]);
                }

                foreach (array_keys($syncedCatalog) as $year) {
                    if ($this->countCurriculumCatalogCoursesForYear($syncedCatalog, (string) $year) > $this->countCurriculumCatalogCoursesForYear($curriculumCatalog, (string) $year)) {
                        $curriculumCatalog[$year] = $syncedCatalog[$year];
                    }
                }
            }
        }

        return $curriculumCatalog;
    }

    private function resolveProgramCoordinatorTable(): ?string
    {
        foreach (['program_coordinator', 'program_coordinators'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return null;
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function normalizeProgramCode(string $program): string
    {
        $trimmed = trim($program);
        if ($trimmed !== '') {
            $catalog = $this->programNames();
            if (isset($catalog[$trimmed])) {
                return $trimmed;
            }
            foreach ($catalog as $code => $label) {
                if (strcasecmp($trimmed, (string) $label) === 0) {
                    return $code;
                }
            }
        }

        $p = strtoupper(trim($program));
        if ($p === '') {
            return '';
        }

        $directMap = [
            'BSINDT' => 'BSIndT',
            'BSCPE' => 'BSCpE',
            'BSCPE ' => 'BSCpE',
            'BSIT' => 'BSIT',
            'BSCS' => 'BSCS',
            'BSHM' => 'BSHM',
            'BSBA-HRM' => 'BSBA-HRM',
            'BSBA-MM' => 'BSBA-MM',
            'BSED-ENGLISH' => 'BSEd-English',
            'BSED-SCIENCE' => 'BSEd-Science',
            'BSED-MATH' => 'BSEd-Math',
        ];
        if (isset($directMap[$p])) {
            return $directMap[$p];
        }

        if (strpos($p, 'COMPUTER SCIENCE') !== false) {
            return 'BSCS';
        }
        if (strpos($p, 'INFORMATION TECHNOLOGY') !== false) {
            return 'BSIT';
        }
        if (strpos($p, 'COMPUTER ENGINEERING') !== false) {
            return 'BSCpE';
        }
        if (strpos($p, 'INDUSTRIAL TECHNOLOGY') !== false) {
            return 'BSIndT';
        }
        if (strpos($p, 'HOSPITALITY MANAGEMENT') !== false) {
            return 'BSHM';
        }
        if (strpos($p, 'BUSINESS ADMINISTRATION') !== false && strpos($p, 'HUMAN RESOURCE') !== false) {
            return 'BSBA-HRM';
        }
        if (strpos($p, 'BUSINESS ADMINISTRATION') !== false && strpos($p, 'MARKETING') !== false) {
            return 'BSBA-MM';
        }
        if (strpos($p, 'SECONDARY EDUCATION') !== false && strpos($p, 'ENGLISH') !== false) {
            return 'BSEd-English';
        }
        if (strpos($p, 'SECONDARY EDUCATION') !== false && strpos($p, 'SCIENCE') !== false) {
            return 'BSEd-Science';
        }
        if (strpos($p, 'SECONDARY EDUCATION') !== false && strpos($p, 'MATH') !== false) {
            return 'BSEd-Math';
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9-]{1,63}$/', $trimmed)) {
            return $trimmed;
        }

        return '';
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

    private function normalizeCurriculumYear(string $value): string
    {
        $v = strtoupper(trim($value));
        if ($v === '') {
            return '';
        }

        if (preg_match('/^(\d{2})V\d+$/', $v, $m)) {
            return '20' . $m[1];
        }

        if (preg_match('/^(\d{4})$/', $v, $m)) {
            return $m[1];
        }

        return '';
    }

    private function splitProgramTokens(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $tokens = array_map('trim', explode(',', $value));
        return array_values(array_filter($tokens, static fn ($token) => $token !== ''));
    }

    private function rowContainsProgram(string $programsCsv, string $programCode): bool
    {
        $targetCode = $this->normalizeProgramCode($programCode);
        if ($targetCode === '') {
            return false;
        }

        foreach ($this->splitProgramTokens($programsCsv) as $token) {
            if ($this->normalizeProgramCode($token) === $targetCode) {
                return true;
            }
        }

        return false;
    }

    private function appendCurriculumCatalogCourse(array &$catalog, string $curriculumYear, string $yearLevel, string $semester, array $course): void
    {
        if ($curriculumYear === '' || $yearLevel === '' || $semester === '') {
            return;
        }

        $catalog[$curriculumYear] ??= [];
        $catalog[$curriculumYear][$yearLevel] ??= [];
        $catalog[$curriculumYear][$yearLevel][$semester] ??= [];

        foreach ($catalog[$curriculumYear][$yearLevel][$semester] as $existingCourse) {
            if (($existingCourse['course_code'] ?? '') === ($course['course_code'] ?? '')) {
                return;
            }
        }

        $catalog[$curriculumYear][$yearLevel][$semester][] = $course;
    }

    private function countCurriculumCatalogCoursesForYear(array $catalog, string $curriculumYear): int
    {
        if (!isset($catalog[$curriculumYear]) || !is_array($catalog[$curriculumYear])) {
            return 0;
        }

        $count = 0;
        foreach ($catalog[$curriculumYear] as $yearBuckets) {
            if (!is_array($yearBuckets)) {
                continue;
            }

            foreach ($yearBuckets as $semesterBuckets) {
                if (is_array($semesterBuckets)) {
                    $count += count($semesterBuckets);
                }
            }
        }

        return $count;
    }

    private function programNames(): array
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

        if (Schema::hasTable('programs') && Schema::hasColumn('programs', 'code') && Schema::hasColumn('programs', 'name')) {
            $rows = DB::table('programs')->select(['code', 'name'])->get();
            foreach ($rows as $row) {
                $code = trim((string) ($row->code ?? ''));
                $name = trim((string) ($row->name ?? ''));
                if ($code !== '' && $name !== '') {
                    $catalog[$code] = preg_replace('/\s+/', ' ', $name) ?: $name;
                }
            }
        }

        return $catalog;
    }
}
