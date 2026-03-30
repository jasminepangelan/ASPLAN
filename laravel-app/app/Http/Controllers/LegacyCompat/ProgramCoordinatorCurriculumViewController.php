<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgramCoordinatorCurriculumViewController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $username = trim((string) $request->input('username', ''));
            $selectedYear = $this->normalizeCurriculumYear((string) $request->input('year', ''));

            $pcTable = $this->resolveProgramCoordinatorTable();
            if ($pcTable === null || $username === '') {
                return response()->json([
                    'success' => true,
                    'coordinator_program_raw' => '',
                    'coordinator_program_code' => '',
                    'error_message' => 'Program is not configured for this account.',
                    'available_years' => [],
                    'selected_year' => '',
                    'courses_by_term' => new \stdClass(),
                    'total_courses' => 0,
                    'program_display_name' => '',
                ]);
            }

            $coordinatorProgramRaw = '';
            if (Schema::hasColumn($pcTable, 'program')) {
                $coordinatorProgramRaw = trim((string) DB::table($pcTable)->where('username', $username)->value('program'));
            }

            $coordinatorProgramCode = $this->normalizeProgramCode($coordinatorProgramRaw);
            if ($coordinatorProgramCode === '') {
                return response()->json([
                    'success' => true,
                    'coordinator_program_raw' => $coordinatorProgramRaw,
                    'coordinator_program_code' => '',
                    'error_message' => 'Program is not configured for this account.',
                    'available_years' => [],
                    'selected_year' => '',
                    'courses_by_term' => new \stdClass(),
                    'total_courses' => 0,
                    'program_display_name' => $coordinatorProgramRaw,
                ]);
            }

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
                ->whereRaw("FIND_IN_SET(?, REPLACE(programs, ', ', ',')) > 0", [$coordinatorProgramCode])
                ->get();

            $availableYearsMap = [];
            $allRows = [];
            foreach ($rows as $row) {
                $allRows[] = $row;
                $prefix = explode('_', (string) ($row->curriculumyear_coursecode ?? ''), 2)[0] ?? '';
                $normalizedYear = $this->normalizeCurriculumYear($prefix);
                if ($normalizedYear !== '') {
                    $availableYearsMap[$normalizedYear] = true;
                }
            }

            $availableYears = array_keys($availableYearsMap);
            sort($availableYears, SORT_NUMERIC);

            if ($selectedYear === '' && !empty($availableYears)) {
                $selectedYear = (string) end($availableYears);
            }

            $coursesByTerm = [];
            $totalCourses = 0;
            $yearOrder = ['First Year' => 1, 'Second Year' => 2, 'Third Year' => 3, 'Fourth Year' => 4];
            $semesterOrder = ['First Semester' => 1, 'Second Semester' => 2, 'Mid Year' => 3, 'Midyear' => 3];

            foreach ($allRows as $row) {
                $parts = explode('_', (string) ($row->curriculumyear_coursecode ?? ''), 2);
                $prefix = $parts[0] ?? '';
                $courseCode = $parts[1] ?? (string) ($row->curriculumyear_coursecode ?? '');
                $normalizedYear = $this->normalizeCurriculumYear($prefix);

                if ($normalizedYear === '' || $normalizedYear !== $selectedYear) {
                    continue;
                }

                $yearLevel = (string) ($row->year_level ?? '');
                $semester = (string) ($row->semester ?? '');

                if ($yearLevel === '' || $semester === '') {
                    continue;
                }

                if (!isset($coursesByTerm[$yearLevel])) {
                    $coursesByTerm[$yearLevel] = [];
                }
                if (!isset($coursesByTerm[$yearLevel][$semester])) {
                    $coursesByTerm[$yearLevel][$semester] = [];
                }

                $coursesByTerm[$yearLevel][$semester][] = [
                    'course_code' => (string) $courseCode,
                    'course_title' => (string) ($row->course_title ?? ''),
                    'credit_units_lec' => (int) ($row->credit_units_lec ?? 0),
                    'credit_units_lab' => (int) ($row->credit_units_lab ?? 0),
                    'lect_hrs_lec' => (int) ($row->lect_hrs_lec ?? 0),
                    'lect_hrs_lab' => (int) ($row->lect_hrs_lab ?? 0),
                    'pre_requisite' => (string) ($row->pre_requisite ?? 'NONE'),
                ];
                $totalCourses++;
            }

            uksort($coursesByTerm, static function ($a, $b) use ($yearOrder): int {
                return ($yearOrder[$a] ?? 99) <=> ($yearOrder[$b] ?? 99);
            });

            foreach ($coursesByTerm as $yearLevel => $semesters) {
                uksort($semesters, static function ($a, $b) use ($semesterOrder): int {
                    return ($semesterOrder[$a] ?? 99) <=> ($semesterOrder[$b] ?? 99);
                });

                foreach ($semesters as $semester => $rowsForTerm) {
                    usort($rowsForTerm, static fn (array $x, array $y): int => strcmp($x['course_code'], $y['course_code']));
                    $coursesByTerm[$yearLevel][$semester] = $rowsForTerm;
                }
            }

            return response()->json([
                'success' => true,
                'coordinator_program_raw' => $coordinatorProgramRaw,
                'coordinator_program_code' => $coordinatorProgramCode,
                'error_message' => '',
                'available_years' => $availableYears,
                'selected_year' => $selectedYear,
                'courses_by_term' => $coursesByTerm,
                'total_courses' => $totalCourses,
                'program_display_name' => $this->programDisplayName($coordinatorProgramCode, $coordinatorProgramRaw),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load curriculum view.',
            ], 500);
        }
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

    private function normalizeProgramCode(string $program): string
    {
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

        return '';
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

    private function programDisplayName(string $programCode, string $programRaw): string
    {
        $programNames = [
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

        return $programNames[$programCode] ?? $programRaw;
    }
}
