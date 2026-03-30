<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdviserManagementController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->bridgeResponse(false, 'Unauthorized', 403);
            }

            $userType = trim((string) $request->input('user_type', ''));
            $username = trim((string) $request->input('username', ''));
            $requestedProgram = trim((string) $request->input('selected_program', ''));

            $selectedProgram = $requestedProgram;
            if ($userType === 'program_coordinator') {
                $selectedProgram = $this->resolveCoordinatorProgramKey($username);
            }

            $availablePrograms = $this->loadAvailablePrograms();
            if ($selectedProgram !== '' && !isset($availablePrograms[$selectedProgram])) {
                $availablePrograms[$selectedProgram] = $this->getProgramLabelFromKey($selectedProgram);
            }

            if ($selectedProgram === '' && count($availablePrograms) === 1) {
                $selectedProgram = (string) array_key_first($availablePrograms);
            }

            if ($selectedProgram !== '' && !isset($availablePrograms[$selectedProgram])) {
                $availablePrograms[$selectedProgram] = $this->getProgramLabelFromKey($selectedProgram);
            }

            $batches = $this->loadBatches($selectedProgram);
            $usedBatchFallback = false;
            if ($selectedProgram !== '' && empty($batches)) {
                $batches = $this->loadAllBatches();
                $usedBatchFallback = !empty($batches);
            }

            $advisers = $this->loadAdvisers($selectedProgram);
            $batchAssignments = $this->loadBatchAssignments($selectedProgram);

            return $this->bridgeResponse(true, 'Adviser management overview loaded.', 200, [
                'selected_program' => $selectedProgram,
                'available_programs' => $availablePrograms,
                'batches' => $batches,
                'advisers' => $advisers,
                'batch_assignments' => $batchAssignments,
                'used_batch_fallback' => $usedBatchFallback,
            ]);
        } catch (Throwable $e) {
            return $this->bridgeResponse(false, 'Failed to load adviser management overview.', 500);
        }
    }

    public function batchUpdate(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->bridgeResponse(false, 'Unauthorized', 403);
            }

            $redirectTarget = $this->resolveRedirectTarget((string) $request->input('redirect_to', ''));
            $batch = trim((string) $request->input('batch', ''));
            $selectedProgram = trim((string) $request->input('selected_program', ''));
            $userType = trim((string) $request->input('user_type', ''));
            $username = trim((string) $request->input('username', ''));

            if ($batch === '') {
                return $this->bridgeResponse(false, 'Invalid request.');
            }

            if ($userType === 'program_coordinator') {
                $selectedProgram = $this->resolveCoordinatorProgramKey($username);
                if ($selectedProgram === '') {
                    return $this->bridgeResponse(false, 'Program is not configured for your coordinator account.');
                }
            }

            $scopedAdvisers = $this->loadProgramScopedAdvisers($selectedProgram);
            $scopedAdviserIds = $scopedAdvisers['ids'];
            $scopedByUsername = $scopedAdvisers['by_username'];
            $programQuery = $selectedProgram !== '' ? '&program=' . urlencode($selectedProgram) : '';

            if ($request->boolean('unassign_batch')) {
                $this->deleteScopedBatchAssignments($batch, $selectedProgram, $scopedAdviserIds);

                return $this->bridgeResponse(true, "All advisers unassigned from batch {$batch}.", 200, [
                    'redirect_to' => $redirectTarget . '?message=' . urlencode("All advisers unassigned from batch {$batch}.") . $programQuery,
                ]);
            }

            if (!$request->boolean('direct_submit')) {
                return $this->bridgeResponse(false, 'Invalid request.');
            }

            $this->deleteScopedBatchAssignments($batch, $selectedProgram, $scopedAdviserIds);

            $selectedAdvisers = $this->normalizeUsernames($request->input('advisers', []));
            $inserted = 0;

            if (!empty($selectedAdvisers)) {
                DB::transaction(function () use ($batch, $selectedAdvisers, $scopedByUsername, &$inserted): void {
                    foreach ($selectedAdvisers as $usernameValue) {
                        if (!isset($scopedByUsername[$usernameValue])) {
                            continue;
                        }

                        $adviserId = (int) $scopedByUsername[$usernameValue];
                        if ($adviserId <= 0) {
                            continue;
                        }

                        try {
                            DB::table('adviser_batch')->insert([
                                'adviser_id' => $adviserId,
                                'batch' => $batch,
                            ]);
                            $inserted++;
                        } catch (Throwable $e) {
                            if ($this->isDuplicateEntry($e)) {
                                continue;
                            }

                            throw $e;
                        }
                    }
                });

                return $this->bridgeResponse(true, "Assigned {$inserted} adviser(s) to batch {$batch}.", 200, [
                    'redirect_to' => $redirectTarget . '?message=' . urlencode("Assigned {$inserted} adviser(s) to batch {$batch}.") . $programQuery,
                ]);
            }

            return $this->bridgeResponse(true, "All advisers removed from batch {$batch}.", 200, [
                'redirect_to' => $redirectTarget . '?message=' . urlencode("All advisers removed from batch {$batch}.") . $programQuery,
            ]);
        } catch (Throwable $e) {
            return $this->bridgeResponse(false, 'An error occurred while updating batch assignments.');
        }
    }

    public function batchUpdateAll(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return $this->bridgeResponse(false, 'Unauthorized', 403);
            }

            $redirectTarget = $this->resolveRedirectTarget((string) $request->input('redirect_to', ''));
            $selectedProgram = trim((string) $request->input('selected_program', ''));
            $userType = trim((string) $request->input('user_type', ''));
            $username = trim((string) $request->input('username', ''));

            if ($userType === 'program_coordinator') {
                $selectedProgram = $this->resolveCoordinatorProgramKey($username);
                if ($selectedProgram === '') {
                    return $this->bridgeResponse(false, 'Program is not configured for your coordinator account.');
                }
            }

            $assignments = $request->input('assignments_json', []);
            if (is_string($assignments)) {
                $decoded = json_decode($assignments, true);
                $assignments = is_array($decoded) ? $decoded : [];
            }

            if (!is_array($assignments) || empty($assignments)) {
                return $this->bridgeResponse(false, 'No batch assignments were provided.');
            }

            $scopedAdvisers = $this->loadProgramScopedAdvisers($selectedProgram);
            $scopedAdviserIds = $scopedAdvisers['ids'];
            $scopedByUsername = $scopedAdvisers['by_username'];
            $programQuery = $selectedProgram !== '' ? '&program=' . urlencode($selectedProgram) : '';

            $updatedBatches = 0;
            $totalAssignments = 0;

            DB::transaction(function () use ($assignments, $selectedProgram, $scopedAdviserIds, $scopedByUsername, &$updatedBatches, &$totalAssignments): void {
                foreach ($assignments as $batch => $usernames) {
                    $batch = trim((string) $batch);
                    if ($batch === '') {
                        continue;
                    }

                    $this->deleteScopedBatchAssignments($batch, $selectedProgram, $scopedAdviserIds);
                    $updatedBatches++;

                    if (!is_array($usernames) || empty($usernames)) {
                        continue;
                    }

                    $uniqueUsernames = $this->normalizeUsernames($usernames);
                    foreach ($uniqueUsernames as $usernameValue) {
                        if (!isset($scopedByUsername[$usernameValue])) {
                            continue;
                        }

                        try {
                            DB::table('adviser_batch')->insert([
                                'adviser_id' => (int) $scopedByUsername[$usernameValue],
                                'batch' => $batch,
                            ]);
                            $totalAssignments++;
                        } catch (Throwable $e) {
                            if ($this->isDuplicateEntry($e)) {
                                continue;
                            }

                            throw $e;
                        }
                    }
                }
            });

            if ($updatedBatches === 0) {
                return $this->bridgeResponse(false, 'No valid batches were updated.');
            }

            return $this->bridgeResponse(true, "Updated {$updatedBatches} batch(es) with {$totalAssignments} adviser assignment(s).", 200, [
                'redirect_to' => $redirectTarget . '?message=' . urlencode("Updated {$updatedBatches} batch(es) with {$totalAssignments} adviser assignment(s).") . $programQuery,
            ]);
        } catch (Throwable $e) {
            return $this->bridgeResponse(false, 'An error occurred while updating all batch assignments.');
        }
    }

    private function loadProgramScopedAdvisers(string $selectedProgram): array
    {
        $byUsername = [];
        $ids = [];

        $rows = DB::table('adviser')
            ->select(['id', 'username', DB::raw('TRIM(program) AS program')])
            ->get();

        foreach ($rows as $row) {
            $programKey = $this->normalizeProgramKey((string) ($row->program ?? ''));
            if ($selectedProgram !== '' && $programKey !== $selectedProgram) {
                continue;
            }

            $id = (int) ($row->id ?? 0);
            $username = trim((string) ($row->username ?? ''));
            if ($id <= 0 || $username === '') {
                continue;
            }

            $ids[] = $id;
            $byUsername[$username] = $id;
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'by_username' => $byUsername,
        ];
    }

    private function loadAvailablePrograms(): array
    {
        $programs = [];
        $rows = DB::table('adviser')
            ->select(DB::raw('DISTINCT TRIM(program) AS program'))
            ->whereNotNull('program')
            ->whereRaw('TRIM(program) != ""')
            ->orderBy('program')
            ->get();

        foreach ($rows as $row) {
            $programKey = $this->normalizeProgramKey((string) ($row->program ?? ''));
            if ($programKey === '') {
                continue;
            }

            if (!isset($programs[$programKey])) {
                $programs[$programKey] = $this->getProgramLabelFromKey($programKey);
            }
        }

        ksort($programs);
        return $programs;
    }

    private function loadBatches(string $selectedProgram): array
    {
        if ($selectedProgram === '') {
            return [];
        }

        $batches = [];
        $rows = DB::table('student_info')
            ->select(DB::raw('DISTINCT LEFT(student_number, 4) as batch'), DB::raw('TRIM(program) AS program'))
            ->whereNotNull('student_number')
            ->where('student_number', '!=', '')
            ->orderByDesc('batch')
            ->get();

        foreach ($rows as $row) {
            if ($this->normalizeProgramKey((string) ($row->program ?? '')) === $selectedProgram) {
                $batches[] = (string) ($row->batch ?? '');
            }
        }

        $batches = array_values(array_unique(array_filter($batches, static fn (string $value): bool => $value !== ''), SORT_STRING));
        rsort($batches, SORT_STRING);

        return $batches;
    }

    private function loadAllBatches(): array
    {
        $batches = [];
        $rows = DB::table('student_info')
            ->select(DB::raw('DISTINCT LEFT(student_number, 4) as batch'))
            ->whereNotNull('student_number')
            ->where('student_number', '!=', '')
            ->orderByDesc('batch')
            ->get();

        foreach ($rows as $row) {
            $batch = trim((string) ($row->batch ?? ''));
            if ($batch !== '') {
                $batches[] = $batch;
            }
        }

        $batches = array_values(array_unique($batches, SORT_STRING));
        rsort($batches, SORT_STRING);

        return $batches;
    }

    private function loadAdvisers(string $selectedProgram): array
    {
        $advisers = [];
        $rows = DB::table('adviser')
            ->select([
                'id',
                'first_name',
                'last_name',
                'username',
                DB::raw('TRIM(program) AS program'),
            ])
            ->whereNotNull('program')
            ->whereRaw('TRIM(program) != ""')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        foreach ($rows as $row) {
            $programKey = $this->normalizeProgramKey((string) ($row->program ?? ''));
            if ($selectedProgram !== '' && $programKey !== $selectedProgram) {
                continue;
            }

            $fullName = trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? ''));
            $advisers[] = [
                'id' => (int) ($row->id ?? 0),
                'full_name' => $fullName,
                'username' => (string) ($row->username ?? ''),
                'program_key' => $programKey,
            ];
        }

        return $advisers;
    }

    private function loadBatchAssignments(string $selectedProgram): array
    {
        $batchAssignments = [];
        $rows = DB::table('adviser_batch as ab')
            ->join('adviser as a', 'ab.adviser_id', '=', 'a.id')
            ->select([
                'ab.batch',
                'a.username',
                'a.first_name',
                'a.last_name',
                DB::raw('TRIM(a.program) AS program'),
            ])
            ->orderByDesc('ab.batch')
            ->get();

        foreach ($rows as $row) {
            $programKey = $this->normalizeProgramKey((string) ($row->program ?? ''));
            if ($selectedProgram !== '' && $programKey !== $selectedProgram) {
                continue;
            }

            $batch = (string) ($row->batch ?? '');
            if ($batch === '') {
                continue;
            }

            if (!isset($batchAssignments[$batch])) {
                $batchAssignments[$batch] = [];
            }

            $batchAssignments[$batch][] = [
                'username' => (string) ($row->username ?? ''),
                'full_name' => trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? '')),
            ];
        }

        return $batchAssignments;
    }

    private function getProgramLabelFromKey(string $programKey): string
    {
        $programKey = trim($programKey);
        if ($programKey === '') {
            return $programKey;
        }

        $parts = explode('-', $programKey, 2);
        if (count($parts) === 2 && $parts[1] !== '') {
            return $parts[0] . ' - ' . $parts[1];
        }

        return $programKey;
    }

    private function deleteScopedBatchAssignments(string $batch, string $selectedProgram, array $scopedAdviserIds): void
    {
        if ($selectedProgram === '') {
            DB::table('adviser_batch')
                ->where('batch', $batch)
                ->delete();
            return;
        }

        if (!empty($scopedAdviserIds)) {
            DB::table('adviser_batch')
                ->where('batch', $batch)
                ->whereIn('adviser_id', $scopedAdviserIds)
                ->delete();
        }
    }

    private function normalizeProgramKey(string $programName): string
    {
        $programName = trim($programName);
        if ($programName === '') {
            return '';
        }

        $normalized = strtoupper((string) preg_replace('/\s+/', ' ', $programName));

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

    private function resolveCoordinatorProgramKey(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return '';
        }

        foreach (['program_coordinator', 'program_coordinators'] as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            if (!DB::getSchemaBuilder()->hasColumn($table, 'program')) {
                continue;
            }

            $program = DB::table($table)
                ->where('username', $username)
                ->value('program');

            $normalized = $this->normalizeProgramKey((string) $program);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $program = DB::table('adviser')
            ->where('username', $username)
            ->value('program');

        return $this->normalizeProgramKey((string) $program);
    }

    private function normalizeUsernames(mixed $usernames): array
    {
        if (!is_array($usernames)) {
            return [];
        }

        $normalized = [];
        foreach ($usernames as $username) {
            $username = trim((string) $username);
            if ($username !== '') {
                $normalized[] = $username;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function resolveRedirectTarget(string $value): string
    {
        $allowed = [
            'admin/adviser_management.php',
            'program_coordinator/adviser_management.php',
        ];

        $candidate = trim($value);
        return in_array($candidate, $allowed, true) ? $candidate : 'admin/adviser_management.php';
    }

    private function isDuplicateEntry(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false;
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function bridgeResponse(bool $success, string $message, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra), $status);
    }
}
