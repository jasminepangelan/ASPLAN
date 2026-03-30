<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ListOfStudentsController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $search = trim((string) $request->input('search', ''));
            $selectedProgram = trim((string) $request->input('program', ''));
            $selectedBatch = trim((string) $request->input('batch', ''));
            $recordsPerPage = max(1, (int) $request->input('records_per_page', 10));
            $page = max(1, (int) $request->input('page', 1));
            $export = filter_var($request->input('export', false), FILTER_VALIDATE_BOOL);

            $availablePrograms = $this->loadAvailablePrograms();
            if ($selectedProgram !== '' && !in_array($selectedProgram, $availablePrograms, true)) {
                $selectedProgram = '';
            }

            $availableBatches = [];
            if ($selectedProgram !== '') {
                $availableBatches = $this->loadAvailableBatches($selectedProgram);
                if ($selectedBatch !== '' && !in_array($selectedBatch, $availableBatches, true)) {
                    $selectedBatch = '';
                }
            } else {
                $selectedBatch = '';
            }

            $allStudents = $this->loadStudents($search, $selectedProgram, $selectedBatch, false, 0, 0);
            $totalRecords = count($allStudents);
            $totalPages = max(1, (int) ceil($totalRecords / $recordsPerPage));
            $offset = ($page - 1) * $recordsPerPage;
            $students = $export ? $allStudents : array_slice($allStudents, $offset, $recordsPerPage);

            return response()->json([
                'success' => true,
                'title' => 'Student Directory',
                'available_programs' => $availablePrograms,
                'available_batches' => $availableBatches,
                'students' => $students,
                'export_students' => $allStudents,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'search' => $search,
                'program' => $selectedProgram,
                'batch' => $selectedBatch,
                'export_mode' => $export,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load student directory.',
            ], 500);
        }
    }

    private function loadAvailablePrograms(): array
    {
        return DB::table('student_info')
            ->whereNotNull('program')
            ->whereRaw('TRIM(program) != ""')
            ->distinct()
            ->orderBy('program')
            ->pluck(DB::raw('TRIM(program)'))
            ->filter(static fn ($value): bool => trim((string) $value) !== '')
            ->values()
            ->all();
    }

    private function loadAvailableBatches(string $selectedProgram): array
    {
        return DB::table('student_info')
            ->whereNotNull('student_number')
            ->where('student_number', '!=', '')
            ->whereRaw('TRIM(program) = ?', [$selectedProgram])
            ->distinct()
            ->orderByDesc(DB::raw('LEFT(student_number, 4)'))
            ->pluck(DB::raw('LEFT(student_number, 4)'))
            ->filter(static fn ($value): bool => trim((string) $value) !== '')
            ->values()
            ->all();
    }

    private function loadStudents(string $search, string $selectedProgram, string $selectedBatch, bool $paged, int $offset, int $limit): array
    {
        $query = DB::table('student_info')
            ->select(['student_number', 'last_name', 'first_name', 'middle_name', 'program']);

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('student_number', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orWhere('first_name', 'like', '%' . $search . '%')
                    ->orWhere('middle_name', 'like', '%' . $search . '%');
            });
        }

        if ($selectedProgram !== '') {
            $query->whereRaw('TRIM(program) = ?', [$selectedProgram]);
        }

        if ($selectedBatch !== '') {
            $query->whereRaw('LEFT(student_number, 4) = ?', [$selectedBatch]);
        }

        $query->orderBy('last_name')->orderBy('first_name');

        if ($paged && $limit > 0) {
            $query->offset($offset)->limit($limit);
        }

        return $query->get()->map(static fn ($row): array => [
            'student_number' => $row->student_number ?? '',
            'last_name' => $row->last_name ?? '',
            'first_name' => $row->first_name ?? '',
            'middle_name' => $row->middle_name ?? '',
            'program' => $row->program ?? '',
        ])->all();
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }
}
