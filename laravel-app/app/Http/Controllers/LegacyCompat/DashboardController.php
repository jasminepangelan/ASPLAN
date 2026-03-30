<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $role = strtolower(trim((string) $request->input('role', 'student')));
            $studentId = trim((string) $request->input('student_id', ''));
            $username = trim((string) $request->input('username', ''));
            $userId = (int) $request->input('user_id', 0);

            if ($role === 'student') {
                if ($studentId === '') {
                    return response()->json(['success' => false, 'message' => 'Student record not found.'], 404);
                }

                $student = DB::table('student_info')
                    ->select(['student_number', 'last_name', 'first_name', 'middle_name', 'picture', 'program'])
                    ->where('student_number', $studentId)
                    ->first();

                if ($student === null) {
                    return response()->json(['success' => false, 'message' => 'Student record not found.'], 404);
                }

                $summary = $this->loadStudentShiftSummary($studentId);
                return response()->json([
                    'success' => true,
                    'role' => 'student',
                    'student' => (array) $student,
                    'summary' => $summary,
                ]);
            }

            if ($role === 'adviser') {
                $programKeys = $this->resolveAdviserProgramKeys($userId, $username);
                $summary = $this->loadAdviserShiftSummary($programKeys);

                return response()->json([
                    'success' => true,
                    'role' => 'adviser',
                    'summary' => $summary,
                    'program_keys' => $programKeys,
                ]);
            }

            if ($role === 'program_coordinator') {
                $programKeys = $this->resolveCoordinatorProgramKeys($username);
                $summary = $this->loadCoordinatorShiftSummary();

                return response()->json([
                    'success' => true,
                    'role' => 'program_coordinator',
                    'summary' => $summary,
                    'program_keys' => $programKeys,
                ]);
            }

            return response()->json(['success' => false, 'message' => 'Unsupported dashboard role.'], 422);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load dashboard data.'], 500);
        }
    }

    private function loadStudentShiftSummary(string $studentNumber): array
    {
        $summary = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'latest_status' => null,
            'latest_requested_program' => null,
            'latest_requested_at' => null,
        ];

        $row = DB::table('program_shift_requests')
            ->selectRaw("COUNT(*) AS total")
            ->selectRaw("SUM(CASE WHEN status IN ('pending_adviser', 'pending_coordinator') THEN 1 ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved")
            ->selectRaw("SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected")
            ->where('student_number', $studentNumber)
            ->first();

        if ($row) {
            $summary['total'] = (int) ($row->total ?? 0);
            $summary['pending'] = (int) ($row->pending ?? 0);
            $summary['approved'] = (int) ($row->approved ?? 0);
            $summary['rejected'] = (int) ($row->rejected ?? 0);
        }

        $latest = DB::table('program_shift_requests')
            ->select(['status', 'requested_program', 'requested_at'])
            ->where('student_number', $studentNumber)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->first();

        if ($latest) {
            $summary['latest_status'] = (string) ($latest->status ?? '');
            $summary['latest_requested_program'] = (string) ($latest->requested_program ?? '');
            $summary['latest_requested_at'] = (string) ($latest->requested_at ?? '');
        }

        return $summary;
    }

    private function loadAdviserShiftSummary(array $programKeys): array
    {
        $summary = [
            'pending' => 0,
            'forwarded' => 0,
            'rejected' => 0,
        ];

        $rows = DB::table('program_shift_requests')
            ->select(['status', 'current_program'])
            ->whereIn('status', ['pending_adviser', 'pending_coordinator', 'rejected'])
            ->get();

        foreach ($rows as $row) {
            if (!$this->programMatchesActorKeys((string) ($row->current_program ?? ''), $programKeys)) {
                continue;
            }

            $status = (string) ($row->status ?? '');
            if ($status === 'pending_adviser') {
                $summary['pending']++;
            } elseif ($status === 'pending_coordinator') {
                $summary['forwarded']++;
            } elseif ($status === 'rejected') {
                $summary['rejected']++;
            }
        }

        return $summary;
    }

    private function loadCoordinatorShiftSummary(): array
    {
        $summary = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
        ];

        $rows = DB::table('program_shift_requests')
            ->select(['status'])
            ->whereIn('status', ['pending_coordinator', 'approved', 'rejected'])
            ->get();

        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            if ($status === 'pending_coordinator') {
                $summary['pending']++;
            } elseif ($status === 'approved') {
                $summary['approved']++;
            } elseif ($status === 'rejected') {
                $summary['rejected']++;
            }
        }

        return $summary;
    }

    private function resolveAdviserProgramKeys(int $adviserId, string $username): array
    {
        $keys = [];

        if ($adviserId > 0) {
            $program = DB::table('adviser')->where('id', $adviserId)->value('program');
            $keys = $this->parseProgramList((string) $program);
        }

        if (empty($keys) && $username !== '') {
            $program = DB::table('adviser')->where('username', $username)->value('program');
            $keys = $this->parseProgramList((string) $program);
        }

        return $keys;
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

        return [];
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

        if (preg_match('/\b(BSCS|BSIT|BSIS|BSBA|BSA|BSED|BEED|BSCPE|BSCP[E]?|BSCE|BSEE|BSME|BSTM|BSHM|BSN)\b/', $normalized, $codeMatch)) {
            $baseCode = strtoupper($codeMatch[1]);
        } elseif (strpos($normalized, 'BACHELOR OF SCIENCE IN') !== false) {
            $subject = trim(str_replace('BACHELOR OF SCIENCE IN', '', $normalized));
            $baseCode = 'BS' . $this->acronymFromPhrase($subject);
        } elseif (strpos($normalized, 'BACHELOR OF SECONDARY EDUCATION') !== false) {
            $baseCode = 'BSED';
        } elseif (strpos($normalized, 'BACHELOR OF ELEMENTARY EDUCATION') !== false) {
            $baseCode = 'BEED';
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

    private function programMatchesActorKeys(string $programLabel, array $actorProgramKeys): bool
    {
        if (empty($actorProgramKeys)) {
            return true;
        }

        $programKey = $this->normalizeProgramKey($programLabel);
        if ($programKey === '') {
            return true;
        }

        $actorKeysExpanded = $this->expandProgramKeys($actorProgramKeys);
        $programKeysExpanded = $this->expandProgramKeys([$programKey]);

        return !empty(array_intersect($programKeysExpanded, $actorKeysExpanded));
    }

    private function expandProgramKeys(array $keys): array
    {
        $expanded = [];
        foreach ($keys as $key) {
            $normalized = strtoupper(trim((string) $key));
            if ($normalized === '') {
                continue;
            }

            $expanded[$normalized] = true;
            if ($normalized === 'BSCPE' || $normalized === 'BSCOE') {
                $expanded['BSCPE'] = true;
                $expanded['BSCOE'] = true;
            }
        }

        return array_keys($expanded);
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }
}
