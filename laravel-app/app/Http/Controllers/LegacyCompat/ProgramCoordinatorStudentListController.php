<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgramCoordinatorStudentListController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $username = trim((string) $request->input('username', ''));
            $search = trim((string) $request->input('search', ''));
            $selectedBatch = trim((string) $request->input('batch', ''));
            $recordsPerPage = max(1, (int) $request->input('records_per_page', 10));
            $page = max(1, (int) $request->input('page', 1));

            $coordinatorPrograms = $this->resolveCoordinatorProgramKeys($username);
            if (empty($coordinatorPrograms)) {
                return response()->json([
                    'success' => true,
                    'coordinator_programs' => [],
                    'available_batches' => [],
                    'students' => [],
                    'total_records' => 0,
                    'total_pages' => 1,
                    'current_page' => $page,
                    'search' => $search,
                    'batch' => '',
                ]);
            }

            $availableBatches = $this->loadAvailableBatches($coordinatorPrograms);
            if ($selectedBatch !== '' && !in_array($selectedBatch, $availableBatches, true)) {
                $selectedBatch = '';
            }

            $students = $this->loadStudents($search, $selectedBatch, $coordinatorPrograms);
            $totalRecords = count($students);
            $totalPages = max(1, (int) ceil($totalRecords / $recordsPerPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $recordsPerPage;
            $studentsPage = array_slice($students, $offset, $recordsPerPage);

            return response()->json([
                'success' => true,
                'coordinator_programs' => $coordinatorPrograms,
                'available_batches' => $availableBatches,
                'students' => $studentsPage,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'search' => $search,
                'batch' => $selectedBatch,
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load student list.'], 500);
        }
    }

    private function loadAvailableBatches(array $programKeys): array
    {
        $batches = [];
        $rows = DB::table('student_info')
            ->select(['student_number', 'program'])
            ->whereNotNull('student_number')
            ->where('student_number', '!=', '')
            ->orderByDesc(DB::raw('LEFT(student_number, 4)'))
            ->get();

        foreach ($rows as $row) {
            $programKey = $this->normalizeProgramKey((string) ($row->program ?? ''));
            if (!in_array($programKey, $programKeys, true)) {
                continue;
            }

            $batch = substr((string) ($row->student_number ?? ''), 0, 4);
            if ($batch !== '') {
                $batches[$batch] = true;
            }
        }

        $batches = array_keys($batches);
        rsort($batches, SORT_STRING);

        return $batches;
    }

    private function loadStudents(string $search, string $selectedBatch, array $programKeys): array
    {
        $rows = DB::table('student_info')
            ->select(['student_number', 'last_name', 'first_name', 'middle_name', 'program'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $filtered = [];
        foreach ($rows as $row) {
            $programKey = $this->normalizeProgramKey((string) ($row->program ?? ''));
            if (!in_array($programKey, $programKeys, true)) {
                continue;
            }

            if ($selectedBatch !== '' && substr((string) ($row->student_number ?? ''), 0, 4) !== $selectedBatch) {
                continue;
            }

            if ($search !== '') {
                $haystack = strtolower(implode(' ', [
                    (string) ($row->student_number ?? ''),
                    (string) ($row->last_name ?? ''),
                    (string) ($row->first_name ?? ''),
                    (string) ($row->middle_name ?? ''),
                ]));
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }

            $filtered[] = [
                'student_number' => (string) ($row->student_number ?? ''),
                'last_name' => (string) ($row->last_name ?? ''),
                'first_name' => (string) ($row->first_name ?? ''),
                'middle_name' => (string) ($row->middle_name ?? ''),
                'program' => (string) ($row->program ?? ''),
            ];
        }

        return $filtered;
    }

    private function resolveCoordinatorProgramKeys(string $username): array
    {
        $username = trim($username);
        if ($username === '') {
            return [];
        }

        foreach (['program_coordinator', 'program_coordinators'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'program')) {
                continue;
            }

            $program = DB::table($table)->where('username', $username)->value('program');
            $keys = $this->parseProgramList((string) $program);
            if (!empty($keys)) {
                return $keys;
            }
        }

        $program = DB::table('adviser')->where('username', $username)->value('program');
        return $this->parseProgramList((string) $program);
    }

    private function parseProgramList(string $programRaw): array
    {
        $programRaw = trim($programRaw);
        if ($programRaw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\r\n|\r|\n)\s*/', $programRaw);
        $normalized = [];
        foreach ($parts as $part) {
            $key = $this->normalizeProgramKey($part);
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized, SORT_STRING));
    }

    private function normalizeProgramKey(string $programName): string
    {
        $programName = trim($programName);
        if ($programName === '') {
            return '';
        }

        $normalized = strtoupper((string) preg_replace('/\s+/', ' ', $programName));

        if (strpos($normalized, 'INFORMATION TECHNOLOGY') !== false) {
            return 'BSIT';
        }
        if (strpos($normalized, 'INDUSTRIAL TECHNOLOGY') !== false) {
            return 'BSINDTECH';
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

        if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN|ABENG|ABPSYCH|ABCOMM)\b/', $normalized, $codeMatch)) {
            $baseCode = strtoupper($codeMatch[1]);
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
            $baseCode = 'BS' . $this->acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
            $baseCode = 'BSED';
        } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
            $baseCode = 'BEED';
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE', '', $normalized));
            $baseCode = 'BS' . $this->acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF ARTS') !== false) {
            $subject = trim(str_replace('BACHELOR OF ARTS', '', $normalized));
            $baseCode = 'AB' . $this->acronymFromPhrase($subject);
        } else {
            $baseCode = strtoupper($programName);
        }

        $majorKey = '';
        if (preg_match('/MAJOR\s+IN\s+(.+)$/', $normalized, $majorMatch)) {
            $majorKey = $this->acronymFromPhrase($majorMatch[1]);
        }

        return $majorKey !== '' ? $baseCode . '-' . $majorKey : $baseCode;
    }

    private function acronymFromPhrase(string $text): string
    {
        $cleaned = strtoupper(trim($text));
        if ($cleaned === '') {
            return '';
        }

        $cleaned = preg_replace('/[^A-Z0-9\s]/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', (string) $cleaned);
        $tokens = explode(' ', (string) $cleaned);
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

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }
}
