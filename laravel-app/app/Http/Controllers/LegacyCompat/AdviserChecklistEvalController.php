<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdviserChecklistEvalController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $adviserId = (int) $request->input('adviser_id', 0);
            $search = trim((string) $request->input('search', ''));
            $recordsPerPage = max(1, (int) $request->input('records_per_page', 10));
            $page = max(1, (int) $request->input('page', 1));

            $adviserProgram = $this->resolveAdviserProgram($adviserId);
            $batches = $this->resolveAdviserBatches($adviserId);

            if ($adviserProgram === '' || empty($batches)) {
                return response()->json([
                    'success' => true,
                    'adviser_program' => $adviserProgram,
                    'batches' => [],
                    'students' => [],
                    'total_records' => 0,
                    'total_pages' => 1,
                    'current_page' => $page,
                    'search' => $search,
                ]);
            }

            $query = DB::table('student_info')
                ->select(['student_number', 'last_name', 'first_name', 'middle_name', 'program'])
                ->where('program', $adviserProgram)
                ->where(function ($batchQuery) use ($batches): void {
                    foreach (array_values($batches) as $index => $batch) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $batchQuery->{$method}('student_number', 'like', $batch . '%');
                    }
                });

            if ($search !== '') {
                $query->where(function ($searchQuery) use ($search): void {
                    $searchTerm = '%' . $search . '%';
                    $searchQuery
                        ->where('student_number', 'like', $searchTerm)
                        ->orWhere('last_name', 'like', $searchTerm)
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhere('middle_name', 'like', $searchTerm);
                });
            }

            $totalRecords = (clone $query)->count();
            $totalPages = max(1, (int) ceil($totalRecords / $recordsPerPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $recordsPerPage;

            $students = $query
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->offset($offset)
                ->limit($recordsPerPage)
                ->get()
                ->map(static fn ($row): array => [
                    'student_id' => (string) ($row->student_number ?? ''),
                    'student_number' => (string) ($row->student_number ?? ''),
                    'last_name' => (string) ($row->last_name ?? ''),
                    'first_name' => (string) ($row->first_name ?? ''),
                    'middle_name' => (string) ($row->middle_name ?? ''),
                    'program' => (string) ($row->program ?? ''),
                ])
                ->all();

            return response()->json([
                'success' => true,
                'adviser_program' => $adviserProgram,
                'batches' => $batches,
                'students' => $students,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'search' => $search,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load checklist evaluation student list.',
            ], 500);
        }
    }

    private function resolveAdviserProgram(int $adviserId): string
    {
        if ($adviserId <= 0 || !Schema::hasTable('adviser')) {
            return '';
        }

        return trim((string) DB::table('adviser')->where('id', $adviserId)->value('program'));
    }

    private function resolveAdviserBatches(int $adviserId): array
    {
        if ($adviserId <= 0 || !Schema::hasTable('adviser_batch')) {
            return [];
        }

        $rows = DB::table('adviser_batch')
            ->select(['batch'])
            ->where('adviser_id', $adviserId)
            ->orderBy('batch')
            ->get();

        $batches = [];
        foreach ($rows as $row) {
            $batch = trim((string) ($row->batch ?? ''));
            if ($batch !== '') {
                $batches[] = $batch;
            }
        }

        return array_values(array_unique($batches, SORT_STRING));
    }
}
