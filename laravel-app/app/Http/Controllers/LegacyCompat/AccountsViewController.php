<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AccountsViewController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $type = strtolower(trim((string) $request->input('type', 'students')));
            $allowedTypes = ['students', 'advisers', 'program_coordinators', 'admins'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'students';
            }

            $search = trim((string) $request->input('search', ''));
            $recordsPerPage = max(1, (int) $request->input('records_per_page', 10));
            $page = max(1, (int) $request->input('page', 1));
            $offset = ($page - 1) * $recordsPerPage;

            [$columns, $rows, $totalRecords, $totalPages, $title] = match ($type) {
                'advisers' => $this->loadAdviserAccounts($search, $offset, $recordsPerPage),
                'program_coordinators' => $this->loadProgramCoordinatorAccounts($search, $offset, $recordsPerPage),
                'admins' => $this->loadAdminAccounts($search, $offset, $recordsPerPage),
                default => $this->loadStudentAccounts($search, $offset, $recordsPerPage),
            };

            return response()->json([
                'success' => true,
                'type' => $type,
                'title' => $title,
                'columns' => $columns,
                'rows' => $rows,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'search' => $search,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load account records.',
            ], 500);
        }
    }

    private function loadStudentAccounts(string $search, int $offset, int $recordsPerPage): array
    {
        $query = DB::table('student_info')
            ->select(['student_number', 'last_name', 'first_name', 'middle_name', 'email', 'program', 'status']);

        if ($search !== '') {
            $this->applyLikeFilter($query, $search, ['student_number', 'last_name', 'first_name', 'middle_name', 'email']);
        }

        $totalRecords = (clone $query)->count();
        $rows = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->offset($offset)
            ->limit($recordsPerPage)
            ->get()
            ->map(static fn ($row): array => [
                $row->student_number ?? '',
                $row->last_name ?? '',
                $row->first_name ?? '',
                $row->middle_name ?? '',
                $row->email ?? '',
                $row->program ?? '',
                $row->status ?? '',
            ])
            ->all();

        return [
            ['Student Number', 'Last Name', 'First Name', 'Middle Name', 'Email', 'Program', 'Status'],
            $rows,
            $totalRecords,
            max(1, (int) ceil($totalRecords / max(1, $recordsPerPage))),
            'Student Accounts',
        ];
    }

    private function loadAdviserAccounts(string $search, int $offset, int $recordsPerPage): array
    {
        $query = DB::table('adviser')
            ->select(['id', 'last_name', 'first_name', 'middle_name', 'username', 'program', 'sex']);

        if ($search !== '') {
            $this->applyLikeFilter($query, $search, ['id', 'last_name', 'first_name', 'middle_name', 'username', 'program']);
        }

        $totalRecords = (clone $query)->count();
        $rows = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->offset($offset)
            ->limit($recordsPerPage)
            ->get()
            ->map(static fn ($row): array => [
                $row->id ?? '',
                $row->last_name ?? '',
                $row->first_name ?? '',
                $row->middle_name ?? '',
                $row->username ?? '',
                $row->program ?? '',
                $row->sex ?? '',
            ])
            ->all();

        return [
            ['Adviser ID', 'Last Name', 'First Name', 'Middle Name', 'Username', 'Program', 'Sex'],
            $rows,
            $totalRecords,
            max(1, (int) ceil($totalRecords / max(1, $recordsPerPage))),
            'Adviser Accounts',
        ];
    }

    private function loadProgramCoordinatorAccounts(string $search, int $offset, int $recordsPerPage): array
    {
        $table = $this->resolveProgramCoordinatorTable();
        if ($table === null) {
            return [
                ['Message'],
                [['No program coordinator table found (expected program_coordinator or program_coordinators).']],
                1,
                1,
                'Program Coordinator Accounts',
            ];
        }

        $selectColumns = ['id', 'last_name', 'first_name', 'middle_name', 'username', 'sex'];
        $hasProgram = Schema::hasColumn($table, 'program');
        if ($hasProgram) {
            $selectColumns[] = 'program';
        }

        $query = DB::table($table)->select($selectColumns);
        if ($search !== '') {
            $searchColumns = ['id', 'last_name', 'first_name', 'middle_name', 'username'];
            if ($hasProgram) {
                $searchColumns[] = 'program';
            }
            $this->applyLikeFilter($query, $search, $searchColumns);
        }

        $totalRecords = (clone $query)->count();
        $rows = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->offset($offset)
            ->limit($recordsPerPage)
            ->get()
            ->map(function ($row) use ($hasProgram): array {
                $data = [
                    $row->id ?? '',
                    $row->last_name ?? '',
                    $row->first_name ?? '',
                    $row->middle_name ?? '',
                    $row->username ?? '',
                ];

                if ($hasProgram) {
                    $data[] = $row->program ?? '';
                }

                $data[] = $row->sex ?? '';
                return $data;
            })
            ->all();

        $columns = ['Coordinator ID', 'Last Name', 'First Name', 'Middle Name', 'Username'];
        if ($hasProgram) {
            $columns[] = 'Program';
        }
        $columns[] = 'Sex';

        return [
            $columns,
            $rows,
            $totalRecords,
            max(1, (int) ceil($totalRecords / max(1, $recordsPerPage))),
            'Program Coordinator Accounts',
        ];
    }

    private function loadAdminAccounts(string $search, int $offset, int $recordsPerPage): array
    {
        $adminTable = $this->resolveAdminTable();
        if ($adminTable === null) {
            return [
                ['Message'],
                [['No admin table found.']],
                1,
                1,
                'Admin Accounts',
            ];
        }

        if ($adminTable === 'admins') {
            $query = DB::table('admins')->select(['username', 'full_name']);
            if ($search !== '') {
                $this->applyLikeFilter($query, $search, ['username', 'full_name']);
            }

            $totalRecords = (clone $query)->count();
            $rows = $query
                ->orderBy('username')
                ->offset($offset)
                ->limit($recordsPerPage)
                ->get()
                ->map(static fn ($row): array => [$row->username ?? '', $row->full_name ?? ''])
                ->all();

            return [
                ['Username', 'Full Name'],
                $rows,
                $totalRecords,
                max(1, (int) ceil($totalRecords / max(1, $recordsPerPage))),
                'Admin Accounts',
            ];
        }

        $query = DB::table('admin')->select(['admin_id', 'last_name', 'first_name', 'middle_name', 'username']);
        if ($search !== '') {
            $this->applyLikeFilter($query, $search, ['admin_id', 'last_name', 'first_name', 'middle_name', 'username']);
        }

        $totalRecords = (clone $query)->count();
        $rows = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->offset($offset)
            ->limit($recordsPerPage)
            ->get()
            ->map(static fn ($row): array => [
                $row->admin_id ?? '',
                $row->last_name ?? '',
                $row->first_name ?? '',
                $row->middle_name ?? '',
                $row->username ?? '',
            ])
            ->all();

        return [
            ['Admin ID', 'Last Name', 'First Name', 'Middle Name', 'Username'],
            $rows,
            $totalRecords,
            max(1, (int) ceil($totalRecords / max(1, $recordsPerPage))),
            'Admin Accounts',
        ];
    }

    private function applyLikeFilter($query, string $search, array $columns): void
    {
        $query->where(function ($builder) use ($search, $columns): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', '%' . $search . '%');
            }
        });
    }

    private function resolveAdminTable(): ?string
    {
        if (Schema::hasTable('admins')) {
            return 'admins';
        }

        if (Schema::hasTable('admin')) {
            return 'admin';
        }

        return null;
    }

    private function resolveProgramCoordinatorTable(): ?string
    {
        if (Schema::hasTable('program_coordinator')) {
            return 'program_coordinator';
        }

        if (Schema::hasTable('program_coordinators')) {
            return 'program_coordinators';
        }

        return null;
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }
}
